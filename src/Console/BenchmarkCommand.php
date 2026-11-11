<?php

namespace Vos\TollTroll\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use SplFileObject;
use Vos\TollTroll\Agents\Classifier;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Benchmark;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;

class BenchmarkCommand extends Command
{
    protected $signature = 'toll-troll:benchmark {suite : Trusted local PHP suite file}
        {dataset? : Labeled local JSONL file}
        {--output= : New private JSON report path}
        {--replay= : Rescore a completed report without inference}
        {--limit=100 : Maximum eligible records, in file order}
        {--max-bytes=4000 : Maximum combined UTF-8 source and reference bytes per record or rewrite}
        {--dry-run : Validate and show the call budget without inference}
        {--yes : Approve sending the selected context to configured providers}';

    protected $description = 'Benchmark one policy across models, sample weights, and thresholds; never dispatch jobs';

    public function handle(Benchmark $benchmark): int
    {
        $suitePath = realpath($this->argument('suite'));
        if ($suitePath === false || ! is_file($suitePath)) {
            throw new InvalidArgumentException('Suite must be a trusted local PHP file.');
        }
        $suite = $benchmark->suite(require $suitePath);
        $capture = $benchmark->captureSpecification($suite);
        $replay = $this->option('replay');

        if ($replay) {
            $report = json_decode(file_get_contents($replay), true, flags: JSON_THROW_ON_ERROR);
            if (($report['format'] ?? null) !== 1 || ($report['complete'] ?? false) !== true || ($report['capture'] ?? null) !== $capture) {
                throw new InvalidArgumentException('Replay needs a complete matching capture. Only profiles and thresholds may change.');
            }
            $this->validateCapture($report, $suite);
            $this->info('Offline replay: no inference calls.');
        } else {
            $limits = Validator::make(['limit' => $this->option('limit'), 'max_bytes' => $this->option('max-bytes')], [
                'limit' => ['required', 'integer', 'min:1'], 'max_bytes' => ['required', 'integer', 'min:1'],
            ])->validate();
            [$records, $skipped] = $this->dataset($this->argument('dataset'), (int) $limits['limit'], (int) $limits['max_bytes'], $suite['policy']);
            if ($records === []) {
                throw new InvalidArgumentException('No eligible records.');
            }
            $calls = count($records) * ($suite['variants'] + count($suite['models']) * (1 + $suite['repeats'] + $suite['variants']));
            $this->info(count($records).' records; '.count($skipped)." skipped; at most {$calls} SDK prompt calls (provider retries may add HTTP requests).");
            $report = ['format' => 1, 'complete' => false, 'capture' => $capture, 'limits' => $limits,
                'prompt_sha256' => ['classifier' => hash_file('sha256', (new \ReflectionClass(Classifier::class))->getFileName()),
                    'paraphraser' => hash_file('sha256', (new \ReflectionClass(Paraphraser::class))->getFileName())],
                'created_at' => gmdate('c'), 'skipped' => $skipped, 'records' => []];
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->option('output')) {
            throw new InvalidArgumentException('Provide --output with a new private report path.');
        }
        if (! $replay && ! $this->option('yes') && ! $this->confirm('Send these records to the configured inference providers?')) {
            return self::FAILURE;
        }

        // Exclusive creation protects an earlier run. Reports contain sensitive context.
        $oldMask = umask(0077);
        try {
            $file = fopen($this->option('output'), 'x');
        } finally {
            umask($oldMask);
        }
        if ($file === false) {
            throw new InvalidArgumentException('Cannot create output; choose a new path in an existing directory.');
        }
        try {
            if (! $replay) {
                $this->save($file, $report);
                foreach ($records as $record) {
                    $report['records'][] = $benchmark->collect($record, $suite, (int) $limits['max_bytes']);
                    $this->save($file, $report);
                }
                $report['complete'] = true;
            }
            $report['profiles'] = $suite['profiles'];
            $report['summary'] = $benchmark->summarize($report['records'], $suite);
            $this->save($file, $report);
        } finally {
            fclose($file);
        }

        $rows = [];
        foreach ($report['summary'] as $model => $profiles) {
            foreach ($profiles as $name => $metrics) {
                $rows[] = [$model, $name, $metrics['total'], $metrics['false_passes'], $metrics['withheld_passes'], $metrics['unknown'], $metrics['errors'], round($metrics['p95_seconds'], 3)];
            }
        }
        $this->table(['Model', 'Profile', 'Cases', 'False pass', 'Withheld pass', 'Unknown', 'Error', 'p95 s'], $rows);
        $this->info('Saved isolated-policy diagnostics, not approval decisions. No jobs dispatched.');

        return self::SUCCESS;
    }

    private function dataset(?string $path, int $limit, int $maxBytes, Policy $policy): array
    {
        if ($path === null || ! is_file($path)) {
            throw new InvalidArgumentException('Provide a local JSONL dataset.');
        }
        $referenceBytes = array_sum(array_map('strlen', $policy->references));
        if ($referenceBytes >= $maxBytes) {
            throw new InvalidArgumentException('Policy references leave no room for sources within --max-bytes.');
        }
        $records = $skipped = $seen = [];
        foreach (new SplFileObject($path) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            Validator::make($record, [
                'id' => ['required', 'string'], 'expected' => ['required', 'in:pass,fail,unknown'],
                'instructions' => ['required', 'string'], 'sources' => ['required', 'array'],
                'policy' => ['sometimes', 'array'], 'policy.id' => ['required_with:policy', 'string'], 'policy.criteria' => ['required_with:policy', 'string'],
                'policy.references' => ['sometimes', 'array'],
            ])->validate();
            if (isset($record['policy']) && ($record['policy']['id'] !== $policy->id || $record['policy']['criteria'] !== $policy->criteria
                || ($record['policy']['references'] ?? []) !== $policy->references)) {
                throw new InvalidArgumentException('Annotated policy wording or references do not match the benchmark suite. Relabel changed policies.');
            }
            new Context($record['instructions'], $record['sources']);
            if (isset($seen[$record['id']])) {
                throw new InvalidArgumentException('Dataset IDs must be unique.');
            }
            $seen[$record['id']] = true;
            if (array_sum(array_map('strlen', $record['sources'])) + $referenceBytes > $maxBytes) {
                $skipped[] = ['id' => $record['id'], 'reason' => $referenceBytes > 0 ? 'context_size_limit' : 'source_size_limit'];

                continue;
            }
            $records[] = $record;
            if (count($records) === $limit) {
                break;
            }
        }

        return [$records, $skipped];
    }

    private function validateCapture(array $report, array $suite): void
    {
        Validator::make($report, [
            'records' => ['required', 'array', 'list', 'min:1'],
            'records.*.id' => ['required', 'string', 'distinct:strict'],
            'records.*.expected' => ['required', 'in:pass,fail,unknown'],
            'records.*.rewrites' => ['present', 'array', 'list', 'size:'.$suite['variants']],
            'records.*.models' => ['required', 'array'],
            'records.*.models.*' => ['required', 'array:original,repeated,paraphrased'],
            'records.*.models.*.original' => ['required', 'array', 'list', 'size:1'],
            'records.*.models.*.repeated' => ['present', 'array', 'list', 'size:'.$suite['repeats']],
            'records.*.models.*.paraphrased' => ['present', 'array', 'list', 'size:'.$suite['variants']],
            'records.*.models.*.*.*.score' => ['present', 'nullable', 'numeric', 'between:0,1'],
            'records.*.models.*.*.*.error' => ['nullable', 'string'],
            'records.*.models.*.*.*.seconds' => ['required', 'numeric', 'min:0'],
            'records.*.models.*.*.*.prompt_calls' => ['required', 'integer', 'between:0,1'],
        ])->validate();
        foreach ($report['records'] as $record) {
            if (array_keys($record['models']) !== array_keys($suite['models'])) {
                throw new InvalidArgumentException('Capture model names do not match the suite.');
            }
        }
    }

    private function save($file, array $report): void
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        rewind($file);
        if (fwrite($file, $json) !== strlen($json) || ! ftruncate($file, strlen($json)) || ! fflush($file)) {
            throw new \RuntimeException('Could not save benchmark progress.');
        }
    }
}

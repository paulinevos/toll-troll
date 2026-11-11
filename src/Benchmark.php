<?php

namespace Vos\TollTroll;

use Exception;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use OverflowException;

/** Isolated policy experiments, never authorization or job dispatch. */
class Benchmark
{
    public function suite(array $suite): array
    {
        $suite += ['repeats' => 0, 'variants' => 0, 'timeout' => 60, 'paraphraser' => ['provider' => null, 'model' => null]];
        Validator::make($suite, [
            'policy' => ['required'],
            'models' => ['required', 'array', 'min:1'],
            'models.*' => ['required', 'array:provider,model'],
            'models.*.provider' => ['present', 'nullable', 'string'],
            'models.*.model' => ['present', 'nullable', 'string'],
            'repeats' => ['required', 'integer', 'min:0'],
            'variants' => ['required', 'integer', 'min:0'],
            'timeout' => ['required', 'integer', 'min:1'],
            'paraphraser' => ['required', 'array:provider,model'],
            'paraphraser.provider' => ['present', 'nullable', 'string'],
            'paraphraser.model' => ['present', 'nullable', 'string'],
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*' => ['required', 'array:aggregation,weights,pass,fail'],
            'profiles.*.aggregation' => ['required', 'in:weighted,unanimous,veto'],
            'profiles.*.weights' => ['required', 'array:original,repeated,paraphrased'],
            'profiles.*.weights.*' => ['required', 'numeric', 'min:0'],
            'profiles.*.pass' => ['required', 'numeric', 'between:0,1'],
            'profiles.*.fail' => ['required', 'numeric', 'between:0,1'],
        ])->validate();

        foreach (['repeats', 'variants', 'timeout'] as $key) {
            $suite[$key] = (int) $suite[$key];
        }

        if (! $suite['policy'] instanceof Policy) {
            throw new InvalidArgumentException('The suite policy must be a Policy object.');
        }

        foreach (['models', 'profiles'] as $key) {
            foreach (array_keys($suite[$key]) as $name) {
                if (! is_string($name) || trim($name) === '') {
                    throw new InvalidArgumentException('Models and profiles need nonempty string names.');
                }
            }
        }

        foreach ($suite['profiles'] as $profile) {
            $suite['policy']->withThresholds($profile['pass'], $profile['fail']);
            $sum = array_sum($profile['weights']);
            if (! is_finite((float) $sum) || $sum <= 0
                || (($profile['weights']['repeated'] ?? 0) > 0 && $suite['repeats'] === 0)
                || (($profile['weights']['paraphrased'] ?? 0) > 0 && $suite['variants'] === 0)) {
                throw new InvalidArgumentException('Weights must have a finite positive sum and select only collected groups.');
            }
        }

        return $suite;
    }

    /** Capture identity excludes thresholds/profiles, which can be changed offline. */
    public function captureSpecification(array $suite): array
    {
        $policy = ['id' => $suite['policy']->id, 'criteria' => $suite['policy']->criteria];
        if ($suite['policy']->references !== []) {
            $policy['references'] = $suite['policy']->references;
        }

        return [
            'policy' => $policy,
            'models' => $suite['models'], 'paraphraser' => $suite['paraphraser'],
            'repeats' => $suite['repeats'], 'variants' => $suite['variants'], 'timeout' => $suite['timeout'],
        ];
    }

    public function collect(array $record, array $suite, int $maxBytes): array
    {
        $troll = app(TollTroll::class);
        $context = new Context($record['instructions'], $record['sources']);
        $result = ['id' => $record['id'], 'expected' => $record['expected'], 'context' => $context, 'rewrites' => [], 'models' => []];
        $referenceBytes = array_sum(array_map('strlen', $suite['policy']->references));

        for ($i = 0; $i < $suite['variants']; $i++) {
            $start = microtime(true);
            try {
                $rewrite = $troll->paraphrase($context, $i, $suite['paraphraser']['provider'], $suite['paraphraser']['model'], $suite['timeout']);
                if (array_sum(array_map('strlen', $rewrite['sources'])) + $referenceBytes > $maxBytes) {
                    throw new OverflowException('Rewrite and references exceeded the context size limit.');
                }
            } catch (Exception $exception) {
                $rewrite = ['error' => $exception::class];
            }
            $result['rewrites'][] = $rewrite + ['seconds' => microtime(true) - $start, 'prompt_calls' => 1];
        }

        foreach ($suite['models'] as $name => $engine) {
            $groups = ['original' => [], 'repeated' => [], 'paraphrased' => []];
            $samples = [['original', $context]];
            for ($i = 0; $i < $suite['repeats']; $i++) {
                $samples[] = ['repeated', $context];
            }
            foreach ($result['rewrites'] as $rewrite) {
                $samples[] = ['paraphrased', isset($rewrite['error']) ? null : new Context($context->instructions, $rewrite['sources'])];
            }
            foreach ($samples as $i => [$group, $sample]) {
                if ($sample === null) {
                    $groups[$group][] = ['score' => null, 'error' => 'rewrite_failed', 'seconds' => 0, 'prompt_calls' => 0];

                    continue;
                }
                $start = microtime(true);
                $assessment = $troll->assess($sample, $suite['policy'], $engine['provider'], $engine['model'], $suite['timeout'], $group.'_'.$i);
                $groups[$group][] = [...(array) $assessment, 'verdict' => $assessment->verdict->value, 'seconds' => microtime(true) - $start, 'prompt_calls' => 1];
            }
            $result['models'][$name] = $groups;
        }

        return $result;
    }

    /** Recomputes decisions from scores, never cached verdicts. */
    public function score(array $groups, array $profile): array
    {
        $selected = [];
        $weighted = 0;
        $weightSum = array_sum($profile['weights']);
        foreach ($profile['weights'] as $group => $weight) {
            if ($weight <= 0) {
                continue;
            }
            if (empty($groups[$group])) {
                throw new InvalidArgumentException('The capture is missing a selected sample group.');
            }
            array_push($selected, ...$groups[$group]);
            // Weight group means, not individual votes: adding repeats does not increase the group's weight.
            $weighted += array_sum(array_column($groups[$group], 'score')) / count($groups[$group]) * $weight;
        }
        $weighted /= $weightSum;

        $scores = array_column($selected, 'score');
        $verdict = match (true) {
            $profile['aggregation'] === 'veto' && collect($scores)->contains(fn ($s) => $s !== null && $s <= $profile['fail']) => 'fail',
            collect($selected)->contains(fn ($a) => ! empty($a['error'])) => 'error',
            in_array(null, $scores, true) => 'unknown',
            $profile['aggregation'] === 'weighted' => $weighted >= $profile['pass'] ? 'pass' : ($weighted <= $profile['fail'] ? 'fail' : 'unknown'),
            min($scores) >= $profile['pass'] => 'pass',
            max($scores) <= $profile['fail'] => 'fail',
            default => 'unknown',
        };

        return ['verdict' => $verdict, 'score' => $profile['aggregation'] === 'weighted' && ! in_array(null, $scores, true) && ! collect($selected)->contains(fn ($a) => ! empty($a['error'])) ? $weighted : null];
    }

    public function summarize(array $records, array $suite): array
    {
        $summary = [];
        foreach ($suite['models'] as $model => $_) {
            foreach ($suite['profiles'] as $name => $profile) {
                $metrics = ['total' => count($records), 'correct' => 0, 'false_passes' => 0, 'withheld_passes' => 0, 'unknown' => 0, 'errors' => 0,
                    'prompt_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'unreported_usage_calls' => 0, 'confusion' => [], 'decisions' => []];
                $times = [];
                foreach ($records as $record) {
                    $groups = $record['models'][$model];
                    $decision = $this->score($groups, $profile);
                    $actual = $decision['verdict'];
                    $expected = $record['expected'];
                    $metrics['decisions'][] = ['id' => $record['id'], 'expected' => $expected, ...$decision];
                    $metrics['confusion'][$expected][$actual] = ($metrics['confusion'][$expected][$actual] ?? 0) + 1;
                    $metrics['correct'] += (int) ($actual === $expected);
                    $metrics['false_passes'] += (int) ($actual === 'pass' && $expected !== 'pass');
                    $metrics['withheld_passes'] += (int) ($actual !== 'pass' && $expected === 'pass');
                    $metrics['unknown'] += (int) ($actual === 'unknown');
                    $metrics['errors'] += (int) ($actual === 'error');
                    $calls = ($profile['weights']['paraphrased'] ?? 0) > 0 ? $record['rewrites'] : [];
                    foreach ($profile['weights'] as $group => $weight) {
                        if ($weight > 0) {
                            array_push($calls, ...$groups[$group]);
                        }
                    }
                    $times[] = array_sum(array_column($calls, 'seconds'));
                    foreach ($calls as $call) {
                        $metrics['prompt_calls'] += $call['prompt_calls'];
                        $metrics['input_tokens'] += $call['usage']['input_tokens'] ?? 0;
                        $metrics['output_tokens'] += $call['usage']['output_tokens'] ?? 0;
                        $metrics['unreported_usage_calls'] += empty($call['usage']) ? $call['prompt_calls'] : 0;
                    }
                }
                sort($times);
                $metrics['accuracy'] = $records === [] ? null : $metrics['correct'] / count($records);
                $metrics['p50_seconds'] = $times === [] ? null : $times[(int) ceil(count($times) * 0.5) - 1];
                $metrics['p95_seconds'] = $times === [] ? null : $times[(int) ceil(count($times) * 0.95) - 1];
                $summary[$model][$name] = $metrics;
            }
        }

        return $summary;
    }
}

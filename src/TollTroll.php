<?php

namespace Vos\TollTroll;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use UnexpectedValueException;
use Vos\TollTroll\Agents\Classifier;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Events\ReviewRequired;

class TollTroll
{
    /**
     * @param  array<string, list<Policy>>  $branches  Sequential policies in each independent branch.
     * @param  list<Policy>  $guards  Additional mandatory guards, after injection screening.
     */
    public function evaluate(Context $context, array $branches, array $guards = []): Evaluation
    {
        $guards = [Policy::noPromptInjection(), ...$guards];
        $this->validatePolicies($guards);

        if ($branches === []) {
            throw new InvalidArgumentException('At least one named policy branch is required.');
        }

        foreach ($branches as $name => $policies) {
            if (! is_string($name) || trim($name) === '' || ! is_array($policies)) {
                throw new InvalidArgumentException('Branches must map nonempty names to policy lists.');
            }

            $this->validatePolicies($policies);
        }

        $config = Validator::make(config('toll-troll'), [
            'variants' => ['required', 'integer', 'min:0'],
            'timeout' => ['required', 'integer', 'min:1'],
            'injection_guard' => ['required', 'array:pass,fail'],
            'injection_guard.pass' => ['required', 'numeric', 'between:0,1', 'gt:injection_guard.fail'],
            'injection_guard.fail' => ['required', 'numeric', 'between:0,1'],
            'paraphrasers' => ['present', Rule::requiredIf(config('toll-troll.variants') > 0), 'array', 'list'],
            'classifiers' => ['required', 'array', 'list', 'min:1'],
            'paraphrasers.*' => ['required', 'array:provider,model'],
            'classifiers.*' => ['required', 'array:provider,model'],
            'paraphrasers.*.provider' => ['present', 'nullable', 'string'],
            'paraphrasers.*.model' => ['present', 'nullable', 'string'],
            'classifiers.*.provider' => ['present', 'nullable', 'string'],
            'classifiers.*.model' => ['present', 'nullable', 'string'],
        ])->validate();

        // Screen references from every policy, including branches that may later be skipped.
        $references = [];
        foreach ([null, ...array_keys($branches)] as $branch) {
            foreach ($branch === null ? $guards : $branches[$branch] as $policy) {
                foreach ($policy->references as $name => $text) {
                    // Qualify names without collisions between branches, policies, or sources.
                    $references[json_encode([$branch, $policy->id, $name], JSON_THROW_ON_ERROR)] = $text;
                }
            }
        }
        $guards[0] = new Policy($guards[0]->id, $guards[0]->criteria,
            $config['injection_guard']['pass'], $config['injection_guard']['fail'], $references);

        $variants = [];
        $errors = [];

        for ($index = 0; $index < $config['variants']; $index++) {
            $engine = $config['paraphrasers'][$index % count($config['paraphrasers'])];

            try {
                $variants['variant_'.($index + 1)] = $this->paraphrase(
                    $context, $index, $engine['provider'], $engine['model'], $config['timeout'],
                );
            } catch (Exception $exception) {
                $errors[] = ['stage' => 'variant_'.($index + 1), 'error' => $exception::class];
            }
        }

        $samples = ['original' => $context->sources];

        foreach ($variants as $name => $variant) {
            $samples[$name] = $variant['sources'];
        }

        $guardResults = $this->sequence($guards, $context, $samples, $config, $errors === [], true);
        $guarded = $errors === [] && collect($guardResults)->every(fn (PolicyResult $result) => $result->verdict === Verdict::Pass);
        $branchResults = [];

        foreach ($branches as $name => $policies) {
            $branchResults[$name] = $this->sequence($policies, $context, $samples, $config, $guarded);
        }

        return new Evaluation((string) Str::uuid7(), $context, $variants, $guardResults, $branchResults, $errors);
    }

    /**
     * Factories run only for allowed branches and must return queued jobs.
     *
     * @param  array<string, callable(Evaluation): ShouldQueue>  $actions
     * @return list<string> Dispatched branch names.
     */
    public function dispatch(Evaluation $evaluation, array $actions): array
    {
        // Validate the complete routing table before causing any side effects.
        foreach ($actions as $branch => $factory) {
            $evaluation->allows($branch);

            if (! is_callable($factory)) {
                throw new InvalidArgumentException('Each action must be a job factory.');
            }
        }

        if ($evaluation->needsReview()) {
            Event::dispatch(new ReviewRequired($evaluation));
        }

        $jobs = [];

        foreach ($actions as $branch => $factory) {
            if ($evaluation->allows($branch)) {
                $job = $factory($evaluation);

                if (! $job instanceof ShouldQueue) {
                    throw new InvalidArgumentException('Action jobs must implement ShouldQueue.');
                }

                $jobs[$branch] = $job;
            }
        }

        foreach ($jobs as $job) {
            Bus::dispatch($job);
        }

        return array_keys($jobs);
    }

    private function validatePolicies(array $policies): void
    {
        if ($policies === [] || ! array_is_list($policies)) {
            throw new InvalidArgumentException('Each policy sequence must be a nonempty list.');
        }

        $ids = [];

        foreach ($policies as $policy) {
            if (! $policy instanceof Policy || in_array($policy->id, $ids, true)) {
                throw new InvalidArgumentException('Each sequence requires Policy objects with unique identifiers.');
            }

            $ids[] = $policy->id;
        }
    }

    /** @return list<PolicyResult> */
    private function sequence(array $policies, Context $context, array $samples, array $config, bool $proceed, bool $guard = false): array
    {
        $results = [];

        foreach ($policies as $policy) {
            if (! $proceed) {
                $results[] = new PolicyResult($policy, Verdict::Skipped);

                continue;
            }

            $assessments = [];

            foreach ($samples as $name => $sources) {
                foreach ($config['classifiers'] as $engine) {
                    $assessments[] = $this->assess(new Context($context->instructions, $sources), $policy, $engine['provider'], $engine['model'], $config['timeout'], $name);
                }
            }

            $verdicts = array_column($assessments, 'verdict');
            $verdict = match (true) {
                $guard && in_array(Verdict::Fail, $verdicts, true) => Verdict::Fail,
                in_array(Verdict::Error, $verdicts, true) => Verdict::Error,
                collect($verdicts)->every(fn (Verdict $v) => $v === Verdict::Pass) => Verdict::Pass,
                collect($verdicts)->every(fn (Verdict $v) => $v === Verdict::Fail) => Verdict::Fail,
                default => Verdict::Unknown,
            };

            $results[] = new PolicyResult($policy, $verdict, $assessments);
            $proceed = $verdict === Verdict::Pass;
        }

        return $results;
    }

    /** A single policy judgment for diagnostics. No guards, aggregation, or dispatch. */
    public function assess(Context $context, Policy $policy, ?string $provider = null, ?string $model = null, int $timeout = 60, string $sample = 'original'): Assessment
    {
        $sources = $context->sources;
        $input = ['sources' => $sources];
        if ($policy->references !== []) {
            $input['references'] = $policy->references;
        }

        try {
            $response = (new Classifier($context->instructions, $policy))->prompt(
                json_encode($input, JSON_THROW_ON_ERROR),
                provider: $provider, model: $model, timeout: $timeout,
            );

            $data = Validator::make($response->toArray(), [
                'score' => ['present', 'nullable', 'numeric', 'between:0,1'],
                'reason' => ['required', 'string'],
                'evidence' => ['present', 'array', 'list'],
                'evidence.*' => ['required', 'array:kind,source,quote'],
                'evidence.*.kind' => ['sometimes', 'required', 'in:source,reference'],
                'evidence.*.source' => ['required', 'string'],
                'evidence.*.quote' => ['required', 'string'],
                'missing' => ['present', 'array', 'list'],
                'missing.*' => ['required', 'string'],
            ])->validate();

            if ($data['score'] !== null && ! is_int($data['score']) && ! is_float($data['score'])) {
                throw new UnexpectedValueException('Score must be a JSON number or null.');
            }

            foreach ($data['evidence'] as $evidence) {
                $texts = ($evidence['kind'] ?? 'source') === 'reference' ? $policy->references : $sources;
                if (! isset($texts[$evidence['source']]) || ! str_contains($texts[$evidence['source']], $evidence['quote'])) {
                    throw new UnexpectedValueException('Evidence must quote the identified source or reference.');
                }
            }

            $score = $data['score'] === null ? null : (float) $data['score'];
            $verdict = match (true) {
                $score === null => Verdict::Unknown,
                $score >= $policy->passThreshold => Verdict::Pass,
                $score <= $policy->failThreshold => Verdict::Fail,
                default => Verdict::Unknown,
            };

            return new Assessment($sample, $verdict, $data['reason'], $data['evidence'], $data['missing'], $response->meta->provider, $response->meta->model, score: $score, usage: $response->usage->toArray());
        } catch (Exception $exception) {
            // Exception messages may contain credentials or raw prompts. Retain only the class.
            return new Assessment($sample, Verdict::Error, 'Evaluation failed.', provider: $provider, model: $model, error: $exception::class, usage: isset($response) ? $response->usage->toArray() : []);
        }
    }

    /** Generate and validate one rewrite. This does not sanitize or authorize context. */
    public function paraphrase(Context $context, int $index = 0, ?string $provider = null, ?string $model = null, int $timeout = 60): array
    {
        $response = (new Paraphraser($index))->prompt(
            json_encode(['sources' => $context->sources], JSON_THROW_ON_ERROR),
            provider: $provider, model: $model, timeout: $timeout,
        );

        $data = Validator::make($response->toArray(), [
            'sources' => ['required', 'array', 'list', 'size:'.count($context->sources)],
            'sources.*' => ['required', 'array:id,text'],
            'sources.*.id' => ['required', 'string', 'distinct:strict', Rule::in(array_keys($context->sources))],
            'sources.*.text' => ['required', 'string'],
        ])->validate();

        return [
            'sources' => array_column($data['sources'], 'text', 'id'),
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
            'usage' => $response->usage->toArray(),
        ];
    }
}

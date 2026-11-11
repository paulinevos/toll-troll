<?php

namespace Demo;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Vos\TollTroll\Agents\AnnotationAdvisor;
use Vos\TollTroll\Agents\Classifier;
use Vos\TollTroll\Agents\Paraphraser;

class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            throw new RuntimeException('The TollTroll demo is local-only. Copy .env.example to .env; never deploy this app.');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        config()->set('toll-troll-annotations.analysis.enabled', true);
        config()->set('toll-troll-annotations.analysis.model', 'demo-fixture-advisor-v2');
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $user->getAuthIdentifier() === 'demo-reviewer' && str_starts_with($dataset, 'demo-'));

        Classifier::fake()->preventStrayPrompts();
        Paraphraser::fake()->preventStrayPrompts();
        AnnotationAdvisor::fake(function (string $prompt) {
            $input = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);
            $ids = collect($input['cases'])->filter(fn ($case) => $case['expected'] !== $case['prediction']['verdict'])->pluck('id')->take(3)->all();

            return ['suggestions' => count($ids) < 3 ? [] : [[
                'category' => 'policy_wording',
                'finding' => 'In these synthetic examples, complete reproduction steps written in prose were labeled Pass by reviewers but predicted Fail.',
                'change' => 'Clarify the policy: “Accept concrete reproduction steps written in prose; a numbered list is not required. Necessary inputs and environment must still be supplied.”',
                'validation' => 'Compare the original and revised wording on held-out prose and numbered-step reports. Check false failures without increasing false passes.',
                'annotation_ids' => $ids,
            ], [
                'category' => 'domain_context',
                'finding' => 'The synthetic failures all contain actionable prose steps. A reference example could help distinguish missing details from an unfamiliar format.',
                'change' => 'Add an approved, synthetic prose report and an incomplete counterexample as policy references. Explain which reproduction facts each contains or lacks.',
                'validation' => 'Benchmark the original and reference-enriched policy on the same held-out ground-truth set, including incomplete prose reports that must still fail.',
                'annotation_ids' => $ids,
            ], [
                'category' => 'classifier',
                'finding' => 'The fixture classifier repeatedly rejects prose reports despite sufficient reproduction information. This suggests a targeted model comparison, not a proven model defect.',
                'change' => 'Compare the current classifier with one candidate that supports structured output. Keep policy wording, thresholds, and paraphrases fixed to isolate the model change.',
                'validation' => 'Benchmark both configurations on the held-out ground-truth set. Compare false failures, false passes, unknowns, latency, and inference cost before choosing.',
                'annotation_ids' => $ids,
            ]]];
        })->preventStrayPrompts();
    }
}

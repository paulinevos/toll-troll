<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Vos\TollTroll\Agents\AnnotationAdvisor;

class AnalyzeAnnotations
{
    public function analyze(string $dataset): void
    {
        if (! config('toll-troll-annotations.analysis.enabled')) {
            return;
        }

        $config = config('toll-troll-annotations.analysis');
        Validator::make($config, [
            'min_reviewed' => ['required', 'integer', 'min:3'],
            'min_disagreements' => ['required', 'integer', 'min:3'],
            'min_disagreement_rate' => ['required', 'numeric', 'gt:0', 'max:1'],
            'max_cases' => ['required', 'integer', 'gte:min_reviewed'],
            'provider' => ['nullable', 'string'], 'model' => ['nullable', 'string'],
        ])->validate();

        // Catch up captures made before advice was enabled, newest revision per policy.
        $policies = Annotation::where('dataset', $dataset)->orderByDesc('created_at')->orderByDesc('id')->get(['policy', 'policy_hash'])
            ->unique(fn ($case) => $case->policy['id']);
        foreach ($policies as $case) {
            $this->recordRevision($dataset, $case->policy['id'], $case->policy_hash);
        }

        foreach (DB::table('toll_troll_annotation_policy_revisions')->where('dataset', $dataset)->whereNull('retired_at')->pluck('policy_hash') as $hash) {
            $fingerprint = $this->fingerprint($dataset, $hash);
            if (AnnotationAnalysis::where('dataset', $dataset)->where('policy_hash', $hash)->where('fingerprint', $fingerprint)->exists()) {
                continue;
            }

            // Repeated evaluations/branches of one context are not independent evidence.
            $cases = Annotation::where('dataset', $dataset)->where('policy_hash', $hash)->whereNotNull('label')
                ->whereNotNull('prediction')->orderByDesc('reviewed_at')->orderByDesc('id')->get()
                ->filter(fn ($case) => in_array($case->prediction['verdict'] ?? null, ['pass', 'fail', 'unknown'], true))
                ->groupBy(fn ($case) => hash('sha256', json_encode($case->context, JSON_THROW_ON_ERROR)))
                ->filter(fn ($group) => $group->pluck('label')->unique()->count() === 1)
                ->map(fn ($group) => $group->first())->take($config['max_cases'])->values();
            $disagreements = $cases->filter(fn ($case) => $case->label !== $case->prediction['verdict']);
            if ($cases->count() < $config['min_reviewed'] || $disagreements->count() < $config['min_disagreements']
                || $disagreements->count() / $cases->count() < $config['min_disagreement_rate']) {
                continue;
            }

            $response = (new AnnotationAdvisor)->prompt(json_encode([
                'policy' => $cases->first()->policy,
                'cases' => $cases->map(fn ($case) => ['id' => $case->id, 'context' => $case->context,
                    'expected' => $case->label, 'note' => $case->note, 'prediction' => $case->prediction])->all(),
            ], JSON_THROW_ON_ERROR),
                provider: $config['provider'],
                model: $config['model'],
                timeout: config('toll-troll.timeout', 60));

            $data = Validator::make($response->toArray(), [
                'suggestions' => ['present', 'array', 'max:3'],
                'suggestions.*.category' => ['required', 'in:thresholds,policy_wording,domain_context,target_instructions,input_quality,classifier,label_quality'],
                'suggestions.*.finding' => ['required', 'string', 'max:2000'],
                'suggestions.*.change' => ['required', 'string', 'max:4000'],
                'suggestions.*.validation' => ['required', 'string', 'max:2000'],
                'suggestions.*.annotation_ids' => ['required', 'array', 'min:3', 'max:20'],
                'suggestions.*.annotation_ids.*' => ['required', 'string'],
            ])->validate();
            $suggestions = array_values(array_filter($data['suggestions'], function ($suggestion) use ($disagreements) {
                $ids = array_unique($suggestion['annotation_ids']);

                return count($ids) >= 3 && array_diff($ids, $disagreements->pluck('id')->all()) === [];
            }));

            // Save the input signature, not a newer signature if reviews changed during inference.
            AnnotationAnalysis::updateOrCreate(['dataset' => $dataset, 'policy_hash' => $hash], [
                'fingerprint' => $fingerprint, 'reviewed_count' => $cases->count(),
                'disagreement_count' => $disagreements->count(), 'suggestions' => $suggestions,
            ]);
        }
    }

    public function handleDeployment(PolicyDeployed $event): void
    {
        if (config('toll-troll-annotations.analysis.enabled')) {
            $hash = hash('sha256', json_encode(Annotation::policySnapshot($event->policy), JSON_THROW_ON_ERROR));
            $this->recordRevision($event->dataset, $event->policy->id, $hash, deployed: true);
        }
    }

    public function recordRevision(string $dataset, string $policyId, string $hash, bool $deployed = false): void
    {
        DB::transaction(function () use ($dataset, $policyId, $hash, $deployed) {
            $revisions = DB::table('toll_troll_annotation_policy_revisions')->where('dataset', $dataset)->where('policy_key', hash('sha256', $policyId));
            $known = (clone $revisions)->lockForUpdate()->get();
            // Late captures cannot reactivate retired revisions or override an explicit deployment.
            if (! $deployed && ($known->contains('policy_hash', $hash) || $known->contains('deployment_tracked', true))) {
                return;
            }

            $revisions->update(['retired_at' => now()]);
            DB::table('toll_troll_annotation_policy_revisions')->updateOrInsert(
                ['dataset' => $dataset, 'policy_hash' => $hash],
                ['policy_key' => hash('sha256', $policyId), 'retired_at' => null, 'deployment_tracked' => $deployed],
            );
        });
    }

    public function advice(string $dataset, string $reviewerId): Collection
    {
        if (! config('toll-troll-annotations.analysis.enabled')) {
            return collect();
        }

        $analyses = AnnotationAnalysis::where('dataset', $dataset)->whereIn('policy_hash',
            DB::table('toll_troll_annotation_policy_revisions')->where('dataset', $dataset)->whereNull('retired_at')->select('policy_hash'))
            ->orderBy('id')->get()->filter(fn ($analysis) => $analysis->suggestions !== [] && $analysis->fingerprint === $this->fingerprint($dataset, $analysis->policy_hash));
        $dismissals = DB::table('toll_troll_annotation_advice_dismissals')->where('reviewer_id', $reviewerId)->whereIn('analysis_id', $analyses->modelKeys())->get();

        return $analyses->flatMap(fn ($analysis) => collect($analysis->suggestions)->map(fn ($suggestion) => [
            'analysis' => $analysis, 'suggestion' => $suggestion, 'key' => AnnotationAnalysis::suggestionKey($suggestion),
        ])->reject(fn ($card) => $dismissals->contains(fn ($dismissal) => (int) $dismissal->analysis_id === $analysis->id && $dismissal->suggestion_key === $card['key'])))->values();
    }

    private function fingerprint(string $dataset, string $hash): string
    {
        return hash('sha256', json_encode([
            Annotation::where('dataset', $dataset)->where('policy_hash', $hash)->whereNotNull('label')->orderBy('id')->pluck('version', 'id')->all(),
            config('toll-troll-annotations.analysis'), config('ai.default'),
        ], JSON_THROW_ON_ERROR));
    }
}

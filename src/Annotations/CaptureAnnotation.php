<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Support\Facades\DB;

class CaptureAnnotation
{
    public function handle(AnnotationRequested $event): void
    {
        $this->store($event);
    }

    public function handleEvaluation(EvaluationReviewRequested $event): void
    {
        DB::transaction(function () use ($event) {
            $evaluation = $event->evaluation;
            foreach ([null, ...array_keys($evaluation->branches)] as $branch) {
                foreach ($branch === null ? $evaluation->guards : $evaluation->branches[$branch] as $result) {
                    $this->store(new AnnotationRequested($event->dataset, $event->key, $evaluation->context, $result->policy), [
                        'evaluation_id' => $evaluation->id,
                        'branch' => $branch,
                        'verdict' => $result->verdict->value,
                        'assessments' => $result->assessments,
                        'variants' => $evaluation->variants,
                        'errors' => $evaluation->errors,
                    ]);
                }
            }
        });
    }

    private function store(AnnotationRequested $event, ?array $prediction = null): void
    {
        $policy = Annotation::policySnapshot($event->policy);
        $policyHash = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
        $identity = [$event->dataset, $event->key, $event->context->fingerprint(), $policyHash];
        if ($prediction !== null) {
            $identity[] = $prediction;
        }
        $fingerprint = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        $case = app(Annotation::class)->firstOrCreate(['fingerprint' => $fingerprint], [
            'dataset' => $event->dataset, 'sample_key' => $event->key,
            'context' => (array) $event->context, 'policy' => $policy, 'policy_hash' => $policyHash,
            'prediction' => $prediction,
        ]);

        if ($case->wasRecentlyCreated && config('toll-troll-annotations.analysis.enabled')) {
            app(AnalyzeAnnotations::class)->recordRevision($event->dataset, $policy['id'], $policyHash);
        }
    }
}

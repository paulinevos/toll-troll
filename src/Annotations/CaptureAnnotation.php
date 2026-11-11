<?php

namespace Vos\TollTroll\Annotations;

class CaptureAnnotation
{
    public function handle(AnnotationRequested $event): void
    {
        $policy = (array) $event->policy;
        // Preserve hashes and idempotency for snapshots created before references existed.
        if ($policy['references'] === []) {
            unset($policy['references']);
        }
        $policyHash = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode([$event->dataset, $event->key, $event->context->fingerprint(), $policyHash], JSON_THROW_ON_ERROR));

        Annotation::firstOrCreate(['fingerprint' => $fingerprint], [
            'dataset' => $event->dataset, 'sample_key' => $event->key,
            'context' => (array) $event->context, 'policy' => $policy, 'policy_hash' => $policyHash,
        ]);
    }
}

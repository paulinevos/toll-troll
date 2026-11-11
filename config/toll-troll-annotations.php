<?php

return [
    'path' => 'toll-troll/annotations',
    // Gate authorization and an authenticated user are always required as well.
    'middleware' => ['web'],
    // Null disables pruning; a positive integer expires snapshots by capture age.
    // The application must explicitly schedule model:prune for the Annotation model.
    'retention_days' => null,

    // Opt in before sending reviewed snapshots to an inference provider.
    'analysis' => [
        'enabled' => false,
        // Null inherits the first TollTroll classifier, then Laravel AI defaults.
        // An explicit provider with a null model uses that provider's default model.
        'provider' => null,
        'model' => null,
        'min_reviewed' => 30,
        'min_disagreements' => 5,
        'min_disagreement_rate' => 0.1,
        // Analyze the most recently reviewed distinct contexts per policy revision.
        'max_cases' => 100,
    ],
];

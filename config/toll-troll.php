<?php

return [
    // Paraphrasing is opt-in. Set to 3 to test three additional rewrites.
    'variants' => 0,

    // Policy-satisfaction thresholds for the mandatory injection guard.
    // These are uncalibrated score cutoffs, not measured probabilities.
    'injection_guard' => [
        'pass' => 0.8,
        'fail' => 0.2,
    ],

    // Null uses the application's Laravel AI SDK defaults.
    'paraphrasers' => [
        ['provider' => null, 'model' => null],
    ],

    // Every classifier evaluates every sample. This is an ensemble, not failover.
    'classifiers' => [
        ['provider' => null, 'model' => null],
    ],

    'timeout' => 60,
];

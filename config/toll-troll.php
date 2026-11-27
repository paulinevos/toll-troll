<?php

return [
    // Paraphrasing is opt-in. Set to 3 to test three additional rewrites.
    'variants' => 0,

    // Automatic injection screening is on by default; explicit guards always run.
    // Pass requires P(pass) >= pass; fail requires P(fail) >= 1 - fail.
    // Model probabilities are not calibrated guarantees of correctness.
    'injection_guard' => [
        'enabled' => true,
        'pass' => 0.8,
        'fail' => 0.2,
    ],

    // Null uses the application's Laravel AI SDK defaults.
    'paraphrasers' => [
        ['provider' => null, 'model' => null],
    ],

    // One native decision provider/model for every guard and policy in a bridge.
    // Supported SDK drivers: typesafe (Jev), openai, gemini.
    // Null uses ai.default_for_classification, not the SDK's text default.
    'classifier' => ['provider' => null, 'model' => null],

    'timeout' => 60,
];

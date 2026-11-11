<?php

use Vos\TollTroll\Policy;

return [
    'policy' => Policy::noPromptInjection(), // Or your own application policy.
    'models' => [
        'default' => ['provider' => null, 'model' => null],
        // 'comparison' => ['provider' => 'your-sdk-provider', 'model' => 'your-model'],
    ],
    'paraphraser' => ['provider' => null, 'model' => null],
    'repeats' => 2, // Additional original judgments: 1 + 2 = 3 total.
    'variants' => 1,
    'profiles' => [
        'original' => ['aggregation' => 'veto', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2],
        'hybrid-veto' => ['aggregation' => 'veto', 'weights' => ['original' => 1, 'repeated' => 1, 'paraphrased' => 1], 'pass' => 0.8, 'fail' => 0.2],
        'weighted' => ['aggregation' => 'weighted', 'weights' => ['original' => 1, 'repeated' => 1, 'paraphrased' => 2], 'pass' => 0.9, 'fail' => 0.2],
    ],
];

<?php

use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

// Require this file from a bootstrapped Laravel application. The factory must
// construct your application's ShouldQueue job using the supplied evaluation.
return function (Context $context, callable $startFixAgent): Evaluation {
    $tollTroll = app(TollTroll::class);

    $evaluation = $tollTroll->evaluate($context, [
        'bug' => [
            Policy::bugReport(),
            Policy::expectedAndActualBehavior(),
            Policy::reproductionSteps(),
        ],
    ]);

    $tollTroll->dispatch($evaluation, ['bug' => $startFixAgent]);

    return $evaluation;
};

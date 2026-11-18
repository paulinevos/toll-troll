<?php

use Illuminate\Support\Facades\Event;
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\TollTroll;

// Enable AnnotationServiceProvider and its migrations before calling this.
// Capture both passing and blocked runs; filtering to passes hides false negatives.
return function (string $dataset, string $key, Context $context, array $branches): Evaluation {
    $bridge = new Bridge($dataset, $context->instructions, $branches);
    $evaluation = app(TollTroll::class)->cross($bridge, $context->sources);

    Event::dispatch(new EvaluationReviewRequested($bridge, $key, $evaluation));

    // No TollTroll::dispatch(): no action jobs or ReviewRequired events.
    return $evaluation;
};

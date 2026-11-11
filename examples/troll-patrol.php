<?php

use Illuminate\Support\Facades\Event;
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\TollTroll;

// Enable AnnotationServiceProvider and its migrations before calling this.
// Capture both passing and blocked runs; filtering to passes hides false negatives.
return function (string $dataset, string $key, Context $context, array $branches): Evaluation {
    $evaluation = app(TollTroll::class)->evaluate($context, $branches);

    Event::dispatch(new EvaluationReviewRequested($dataset, $key, $evaluation));

    // No TollTroll::dispatch(): no action jobs or ReviewRequired events.
    return $evaluation;
};

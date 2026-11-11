<?php

namespace Vos\TollTroll\Events;

use Vos\TollTroll\Evaluation;

final readonly class ReviewRequired
{
    public function __construct(public Evaluation $evaluation) {}
}

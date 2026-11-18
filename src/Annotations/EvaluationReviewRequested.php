<?php

namespace Vos\TollTroll\Annotations;

use InvalidArgumentException;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Evaluation;

final readonly class EvaluationReviewRequested
{
    public string $dataset;

    public function __construct(string|Bridge $dataset, public string $key, public Evaluation $evaluation)
    {
        $dataset = $dataset instanceof Bridge ? $dataset->name : $dataset;
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $dataset) || trim($key) === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Use a bridge name (1–64 letters, digits, underscores or hyphens) and a nonempty key of at most 255 bytes.');
        }

        if ($evaluation->bridge !== null && $evaluation->bridge !== $dataset) {
            throw new InvalidArgumentException('Capture an evaluation under its own bridge, not another entrypoint.');
        }

        $this->dataset = $dataset;
    }
}

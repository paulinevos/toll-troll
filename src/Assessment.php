<?php

namespace Vos\TollTroll;

final readonly class Assessment
{
    /**
     * @param  array<string, float>  $probabilities  Candidate probabilities, not generated explanations.
     * @param  array  $usage  Single-policy diagnostics only; shared batch usage is on Evaluation::classificationCalls.
     */
    public function __construct(
        public string $sample,
        public Verdict $verdict,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $error = null,
        public ?float $score = null,
        public ?float $failProbability = null,
        public ?string $choice = null,
        public array $probabilities = [],
        public ?float $confidence = null,
        public array $usage = [],
    ) {}
}

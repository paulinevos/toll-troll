<?php

namespace Vos\TollTroll;

final readonly class EvaluationConfig
{
    /**
     * Null fields inherit defaults; false, zero, and empty lists are explicit values.
     *
     * @param  list<array{provider: ?string, model: ?string}>|null  $paraphrasers
     * @param  array{provider: ?string, model: ?string}|null  $classifier  Shared by every guard and branch policy.
     */
    public function __construct(
        public ?int $variants = null,
        public ?array $paraphrasers = null,
        public ?int $paraphraseTimeout = null,
        public ?bool $injectionGuard = null,
        public ?float $injectionPassThreshold = null,
        public ?float $injectionFailThreshold = null,
        public ?array $classifier = null,
        public ?int $classificationTimeout = null,
    ) {}
}

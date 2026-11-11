<?php

namespace Vos\TollTroll;

use InvalidArgumentException;

final readonly class EvaluationConfig
{
    /**
     * Null fields inherit defaults; false, zero, and empty lists are explicit values.
     *
     * @param  list<array{provider: ?string, model: ?string}>|null  $paraphrasers
     * @param  array<string, PolicyConfig>  $policies  Overrides by policy ID, across branches and guards.
     */
    public function __construct(
        public ?int $variants = null,
        public ?array $paraphrasers = null,
        public ?int $paraphraseTimeout = null,
        public ?bool $injectionGuard = null,
        public ?float $injectionPassThreshold = null,
        public ?float $injectionFailThreshold = null,
        public ?PolicyConfig $classification = null,
        public array $policies = [],
    ) {
        foreach ($policies as $id => $settings) {
            if (! is_string($id) || trim($id) === '' || ! $settings instanceof PolicyConfig) {
                throw new InvalidArgumentException('Policy overrides must map nonempty policy IDs to PolicyConfig objects.');
            }
        }
    }
}

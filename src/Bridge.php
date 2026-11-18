<?php

namespace Vos\TollTroll;

use InvalidArgumentException;
use Vos\TollTroll\Contracts\Policy as PolicyContract;

final readonly class Bridge
{
    /**
     * Define one input entrypoint. Reuse policies, not names, across entrypoints.
     *
     * @param  array<string, list<PolicyContract>>  $branches
     * @param  list<PolicyContract>  $guards
     */
    public function __construct(
        public string $name,
        public string $instructions,
        public array $branches,
        public array $guards = [],
        public ?EvaluationConfig $config = null,
    ) {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $name) || trim($instructions) === '') {
            throw new InvalidArgumentException('Use a bridge name (1–64 letters, digits, underscores or hyphens) and nonempty trusted instructions.');
        }
    }
}

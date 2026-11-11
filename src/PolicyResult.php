<?php

namespace Vos\TollTroll;

use Vos\TollTroll\Contracts\Policy as PolicyContract;

final readonly class PolicyResult
{
    public Policy $policy;

    /** @param list<Assessment> $assessments */
    public function __construct(
        PolicyContract $policy,
        public Verdict $verdict,
        public array $assessments = [],
    ) {
        $this->policy = Policy::from($policy);
    }
}

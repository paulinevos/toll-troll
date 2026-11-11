<?php

namespace Vos\TollTroll;

final readonly class PolicyResult
{
    /** @param list<Assessment> $assessments */
    public function __construct(
        public Policy $policy,
        public Verdict $verdict,
        public array $assessments = [],
    ) {}
}

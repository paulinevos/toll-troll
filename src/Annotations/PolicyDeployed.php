<?php

namespace Vos\TollTroll\Annotations;

use InvalidArgumentException;
use Vos\TollTroll\Contracts\Policy as PolicyContract;
use Vos\TollTroll\Policy;

final readonly class PolicyDeployed
{
    public Policy $policy;

    public function __construct(public string $dataset, PolicyContract $policy)
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $dataset)) {
            throw new InvalidArgumentException('Use a dataset slug of 1–64 letters, digits, underscores or hyphens.');
        }

        $this->policy = Policy::from($policy);
    }
}

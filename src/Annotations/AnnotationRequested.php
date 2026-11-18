<?php

namespace Vos\TollTroll\Annotations;

use InvalidArgumentException;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Context;
use Vos\TollTroll\Contracts\Policy as PolicyContract;
use Vos\TollTroll\Policy;

final readonly class AnnotationRequested
{
    public string $dataset;

    public Policy $policy;

    public function __construct(string|Bridge $dataset, public string $key, public Context $context, PolicyContract $policy)
    {
        $dataset = $dataset instanceof Bridge ? $dataset->name : $dataset;
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $dataset) || trim($key) === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Use a bridge name (1–64 letters, digits, underscores or hyphens) and a nonempty key of at most 255 bytes.');
        }

        $this->dataset = $dataset;
        $this->policy = Policy::from($policy);
    }
}

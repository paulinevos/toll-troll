<?php

namespace Vos\TollTroll\Annotations;

use InvalidArgumentException;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;

final readonly class AnnotationRequested
{
    public function __construct(public string $dataset, public string $key, public Context $context, public Policy $policy)
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $dataset) || trim($key) === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Use a dataset slug (1–64 letters, digits, underscores or hyphens) and a nonempty key of at most 255 bytes.');
        }
    }
}

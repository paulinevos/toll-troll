<?php

namespace Vos\TollTroll;

use InvalidArgumentException;

final readonly class Context
{
    /** @param array<string, string> $sources Source identifiers mapped to untrusted text. */
    public function __construct(public string $instructions, public array $sources)
    {
        if (trim($instructions) === '' || $sources === []) {
            throw new InvalidArgumentException('Target instructions and at least one context source are required.');
        }

        foreach ($sources as $id => $text) {
            if (! is_string($id) || trim($id) === '' || ! is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException('Context sources must have nonempty string identifiers and text.');
            }
        }

        json_encode($this, JSON_THROW_ON_ERROR);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this, JSON_THROW_ON_ERROR));
    }
}

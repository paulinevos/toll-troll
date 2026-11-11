<?php

namespace Vos\TollTroll\Contracts;

/** Resolved application-owned policy data, snapshotted before use. */
interface Policy
{
    public function id(): string;

    public function criteria(): string;

    public function passThreshold(): float;

    public function failThreshold(): float;

    /** @return array<string, string> Named, resolved domain context; data, not instructions. */
    public function references(): array;
}

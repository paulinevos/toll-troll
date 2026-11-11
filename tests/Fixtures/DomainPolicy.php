<?php

namespace Vos\TollTroll\Tests\Fixtures;

use Vos\TollTroll\Contracts\Policy;

class DomainPolicy implements Policy
{
    // Deliberately different storage and extra state: only contract data may escape.
    public string $internal = 'PRIVATE_IMPLEMENTATION_MARKER';

    public function __construct(
        public string $name = 'documented_behavior',
        public string $rubric = 'Pass when reported behavior contradicts the documented contract.',
        public float $pass = 0.7,
        public float $fail = 0.1,
        public array $documents = ['manual' => 'Drafts must save.'],
    ) {}

    public function id(): string
    {
        return $this->name;
    }

    public function criteria(): string
    {
        return $this->rubric;
    }

    public function passThreshold(): float
    {
        return $this->pass;
    }

    public function failThreshold(): float
    {
        return $this->fail;
    }

    public function references(): array
    {
        return $this->documents;
    }
}

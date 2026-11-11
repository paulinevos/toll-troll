<?php

namespace Vos\TollTroll;

final readonly class Assessment
{
    /**
     * @param  list<array{source: string, quote: string, kind?: 'source'|'reference'}>  $evidence  Omitted kind means source.
     * @param  list<string>  $missing
     */
    public function __construct(
        public string $sample,
        public Verdict $verdict,
        public string $reason,
        public array $evidence = [],
        public array $missing = [],
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $error = null,
        public ?float $score = null,
        public array $usage = [],
    ) {}
}

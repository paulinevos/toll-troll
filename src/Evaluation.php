<?php

namespace Vos\TollTroll;

use InvalidArgumentException;

final readonly class Evaluation
{
    /**
     * @param  array<string, array{sources: array<string, string>, provider: ?string, model: ?string}>  $variants
     * @param  list<PolicyResult>  $guards
     * @param  array<string, list<PolicyResult>>  $branches
     * @param  list<array{stage: string, error: string}>  $errors
     */
    public function __construct(
        public string $id,
        public Context $context,
        public array $variants,
        public array $guards,
        public array $branches,
        public array $errors = [],
    ) {}

    public function guarded(): bool
    {
        return $this->errors === [] && $this->allPassed($this->guards);
    }

    public function allows(string $branch): bool
    {
        $this->assertBranch($branch);

        return $this->guarded() && $this->allPassed($this->branches[$branch]);
    }

    public function needsReview(): bool
    {
        if (! $this->guarded()) {
            return true;
        }

        foreach ($this->branches as $results) {
            foreach ($results as $result) {
                if (in_array($result->verdict, [Verdict::Unknown, Verdict::Error], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function actionKey(string $branch): string
    {
        $this->assertBranch($branch);

        return $this->id.':'.$branch;
    }

    private function assertBranch(string $branch): void
    {
        if (! array_key_exists($branch, $this->branches)) {
            throw new InvalidArgumentException("Unknown branch: {$branch}");
        }
    }

    /** @param list<PolicyResult> $results */
    private function allPassed(array $results): bool
    {
        return $results !== [] && collect($results)->every(fn (PolicyResult $result) => $result->verdict === Verdict::Pass);
    }
}

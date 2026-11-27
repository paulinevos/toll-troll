<?php

namespace Vos\TollTroll\Tests;

use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Context;
use Vos\TollTroll\EvaluationConfig;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;
use Vos\TollTroll\TollTrollServiceProvider;
use Vos\TollTroll\Verdict;

class BatchingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, TollTrollServiceProvider::class];
    }

    public function test_one_original_request_covers_guards_and_branches_in_flattened_occurrence_order(): void
    {
        $calls = [];
        Classification::fake(function (ClassificationPrompt $prompt) use (&$calls) {
            $calls[] = $prompt;
            $this->assertSame(['sources' => ['report' => 'SOURCE_MARKER']], $prompt->state);
            $this->assertSame(['policy_0', 'policy_1', 'policy_2', 'policy_3', 'policy_4'], array_keys($prompt->questions));
            $this->assertSame(['no_prompt_injection', 'scope', 'bug_report', 'reproduction_steps', 'feature_request'],
                array_map(fn ($question) => $question->instructions['policy']['id'], array_values($prompt->questions)));
            foreach ($prompt->questions as $question) {
                $this->assertSame('Triage reports.', $question->instructions['target_instructions']);
                $this->assertStringNotContainsString('SOURCE_MARKER', json_encode($question->instructions));
                $this->assertSame(['id', 'criteria'], array_keys($question->instructions['policy']));
            }

            return $this->passingAnswers($prompt);
        })->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'bug' => [Policy::bugReport(), Policy::reproductionSteps()],
            'feature' => [Policy::featureRequest()],
        ], guards: [new Policy('scope', 'Check scope.')]);

        $this->assertTrue($result->allows('bug'));
        $this->assertTrue($result->allows('feature'));
        $this->assertCount(1, $calls);
        $this->assertCount(1, $result->classificationCalls);
        $this->assertSame(0.91, $result->branches['feature'][0]->assessments[0]->score);
    }

    public function test_duplicate_ids_with_different_criteria_and_thresholds_map_by_occurrence_not_answer_order(): void
    {
        Classification::fake(function (ClassificationPrompt $prompt) {
            $this->assertSame(['policy_0', 'policy_1', 'policy_2'], array_keys($prompt->questions));
            $this->assertSame('Accept documented bug.', $prompt->questions['policy_0']->instructions['policy']['criteria']);
            $this->assertSame('Require implementation detail.', $prompt->questions['policy_1']->instructions['policy']['criteria']);

            return new ClassificationResponse([
                'policy_2' => new ChoiceAnswer('fail', ['pass' => 0.04, 'fail' => 0.93, 'unknown' => 0.03]),
                'policy_1' => new ChoiceAnswer('pass', ['pass' => 0.83, 'fail' => 0.11, 'unknown' => 0.06]),
                'policy_0' => new ChoiceAnswer('pass', ['pass' => 0.89, 'fail' => 0.07, 'unknown' => 0.04]),
            ], new TextUsage, new Meta('typesafe', 'batch-model'));
        })->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'triage' => [new Policy('same_id', 'Accept documented bug.', 0.85, 0.1)],
            'implement' => [new Policy('same_id', 'Require implementation detail.', 0.9, 0.2)],
            'reject' => [new Policy('same_id', 'Check another criterion.', 0.8, 0.15)],
        ], config: new EvaluationConfig(injectionGuard: false));

        $this->assertTrue($result->allows('triage'));
        $this->assertSame(Verdict::Unknown, $result->branches['implement'][0]->verdict);
        $this->assertSame(Verdict::Fail, $result->branches['reject'][0]->verdict);
        $this->assertSame([0.89, 0.83, 0.04], array_map(fn ($branch) => $branch[0]->assessments[0]->score, array_values($result->branches)));
        $this->assertCount(1, $result->classificationCalls);
    }

    public function test_equal_reference_maps_batch_and_distinct_maps_are_physically_isolated(): void
    {
        $seen = [];
        $references = ['manual' => 'REFERENCE_A'];
        Classification::fake(function (ClassificationPrompt $prompt) use (&$seen, $references) {
            $seen[] = array_keys($prompt->questions);
            if ($prompt->asks('policy_0')) {
                $this->assertSame(['policy_0', 'policy_2'], array_keys($prompt->questions));
                $this->assertSame($references, $prompt->state['references']);
                $this->assertStringNotContainsString('REFERENCE_B', json_encode($prompt->state));
            } elseif ($prompt->asks('policy_1')) {
                $this->assertSame(['policy_1'], array_keys($prompt->questions));
                $this->assertSame(['manual' => 'REFERENCE_B'], $prompt->state['references']);
                $this->assertStringNotContainsString('REFERENCE_A', json_encode($prompt->state));
            } else {
                $this->assertSame(['policy_3'], array_keys($prompt->questions));
                $this->assertArrayNotHasKey('references', $prompt->state);
            }
            foreach ($prompt->questions as $question) {
                $this->assertStringNotContainsString('REFERENCE_', json_encode($question->instructions));
            }

            return $this->passingAnswers($prompt);
        })->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'a' => [new Policy('a', 'Check A.', references: $references)],
            'b' => [new Policy('b', 'Check B.', references: ['manual' => 'REFERENCE_B'])],
            'c' => [new Policy('c', 'Check C.', references: $references)],
            'd' => [Policy::bugReport()],
        ], config: new EvaluationConfig(injectionGuard: false));

        $this->assertCount(3, $seen);
        $this->assertCount(3, $result->classificationCalls);
        foreach (array_keys($result->branches) as $branch) {
            $this->assertTrue($result->allows($branch));
        }
    }

    #[DataProvider('questionFailures')]
    public function test_missing_or_malformed_question_only_affects_its_branch(bool $missing): void
    {
        Classification::fake(function (ClassificationPrompt $prompt) use ($missing) {
            $answers = $this->passingAnswers($prompt);
            if ($missing) {
                unset($answers['policy_0']);
            } else {
                $answers['policy_0'] = new BooleanAnswer(1.0);
            }

            // Array fakes synthesize missing answers; an explicit response preserves the defect.
            return new ClassificationResponse($answers, new TextUsage, new Meta('typesafe', 'batch-model'));
        })->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'broken' => [Policy::bugReport()], 'allowed' => [Policy::featureRequest()],
        ], config: new EvaluationConfig(injectionGuard: false));

        $this->assertSame(Verdict::Error, $result->branches['broken'][0]->verdict);
        $this->assertTrue($result->allows('allowed'));
        $this->assertCount(1, $result->classificationCalls);
    }

    public static function questionFailures(): array
    {
        return ['missing' => [true], 'wrong type' => [false]];
    }

    public function test_speculative_malformed_downstream_answer_is_discarded_without_affecting_allowed_sibling_or_usage(): void
    {
        $usage = new TextUsage(inputTokens: 37, outputTokens: 2);
        Classification::fake(function (ClassificationPrompt $prompt) use ($usage) {
            $answers = $this->passingAnswers($prompt);
            $answers['policy_1'] = new ChoiceAnswer('fail', ['pass' => 0.03, 'fail' => 0.92, 'unknown' => 0.05]);
            $answers['policy_2'] = new ChoiceAnswer('pass', ['pass' => -1, 'fail' => 2, 'unknown' => 0]);

            return new ClassificationResponse($answers, $usage, new Meta('typesafe', 'batch-model'));
        })->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'blocked' => [Policy::bugReport(), Policy::reproductionSteps()],
            'allowed' => [Policy::featureRequest()],
        ]);

        $this->assertSame(Verdict::Fail, $result->branches['blocked'][0]->verdict);
        $this->assertSame(Verdict::Skipped, $result->branches['blocked'][1]->verdict);
        $this->assertSame([], $result->branches['blocked'][1]->assessments);
        $this->assertTrue($result->allows('allowed'));
        $this->assertFalse($result->needsReview());
        $this->assertSame([], $result->branches['allowed'][0]->assessments[0]->usage);
        $this->assertCount(1, $result->classificationCalls);
        $this->assertSame($usage->toArray(), $result->classificationCalls[0]['usage']);
    }

    public function test_failed_guard_skips_later_guards_and_branches_but_keeps_every_speculative_request_record(): void
    {
        $requests = 0;
        $usage = new TextUsage(inputTokens: 23, outputTokens: 1);
        Classification::fake(function (ClassificationPrompt $prompt) use (&$requests, $usage) {
            $requests++;
            if ($prompt->asks('policy_0')) {
                $this->assertSame(['policy_0', 'policy_1'], array_keys($prompt->questions));

                return new ClassificationResponse([
                    'policy_1' => new BooleanAnswer(1.0), // Never becomes an assessment.
                    'policy_0' => new ChoiceAnswer('fail', ['pass' => 0.04, 'fail' => 0.93, 'unknown' => 0.03]),
                ], $usage, new Meta('typesafe', 'guard-model'));
            }
            $this->assertSame(['policy_2'], array_keys($prompt->questions));

            throw new \RuntimeException('private-provider-detail');
        })->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'bug' => [new Policy('bug', 'Check bug.', references: ['manual' => 'Documentation.'])],
        ], guards: [new Policy('first_guard', 'Check access.'), new Policy('later_guard', 'Check scope.')],
            config: new EvaluationConfig(injectionGuard: false, classifier: ['provider' => 'typesafe', 'model' => 'guard-model']));

        $this->assertSame(2, $requests);
        $this->assertSame(Verdict::Fail, $result->guards[0]->verdict);
        foreach ([$result->guards[1], $result->branches['bug'][0]] as $skipped) {
            $this->assertSame(Verdict::Skipped, $skipped->verdict);
            $this->assertSame([], $skipped->assessments);
        }
        $this->assertCount(2, $result->classificationCalls);
        $this->assertSame($usage->toArray(), $result->classificationCalls[0]['usage']);
        $this->assertSame(\RuntimeException::class, $result->classificationCalls[1]['error']);
        $this->assertSame([], $result->classificationCalls[1]['usage']);
        $this->assertSame(['original', 'original'], array_column($result->classificationCalls, 'sample'));
        $this->assertSame(['typesafe', 'typesafe'], array_column($result->classificationCalls, 'provider'));
        $this->assertSame(['guard-model', 'guard-model'], array_column($result->classificationCalls, 'model'));
        $this->assertStringNotContainsString('private-provider-detail', json_encode($result));
    }

    public function test_unknown_extra_answer_key_invalidates_the_entire_batch(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => new ClassificationResponse(
            $this->passingAnswers($prompt) + ['unrequested' => new BooleanAnswer(1.0)], new TextUsage, new Meta('typesafe', 'batch-model'),
        ))
            ->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'a' => [Policy::bugReport()], 'b' => [Policy::featureRequest()],
        ], config: new EvaluationConfig(injectionGuard: false));

        foreach ($result->branches as $branch) {
            $this->assertSame(Verdict::Error, $branch[0]->verdict);
        }
        $this->assertCount(1, $result->classificationCalls);
        $this->assertNotEmpty($result->classificationCalls[0]['error']);
    }

    public function test_reference_groups_are_chunked_at_400_questions_for_every_sample(): void
    {
        Paraphraser::fake([['sources' => [['id' => 'report', 'text' => 'Rewritten report.']]]])->preventStrayPrompts();
        $seen = [];
        Classification::fake(function (ClassificationPrompt $prompt) use (&$seen) {
            $seen[$prompt->state['sources']['report']][] = array_keys($prompt->questions);

            return $this->passingAnswers($prompt);
        })->preventStrayClassifications();
        $branches = [];
        for ($index = 0; $index < 401; $index++) {
            $branches['branch_'.$index] = [new Policy('same_id', 'Criterion '.$index)];
        }
        $result = app(TollTroll::class)->evaluate($this->context(), $branches,
            config: new EvaluationConfig(variants: 1, injectionGuard: false));

        $this->assertCount(2, $seen);
        foreach ($seen as $chunks) {
            $this->assertSame([400, 1], array_map('count', $chunks));
            $this->assertSame(array_map(fn ($i) => 'policy_'.$i, range(0, 399)), $chunks[0]);
            $this->assertSame(['policy_400'], $chunks[1]);
        }
        $this->assertCount(4, $result->classificationCalls);
        foreach (array_keys($branches) as $branch) {
            $this->assertTrue($result->allows($branch));
            $this->assertCount(2, $result->branches[$branch][0]->assessments);
        }
    }

    public function test_low_level_assessment_keeps_decision_key_and_single_request_usage(): void
    {
        $usage = new TextUsage(inputTokens: 19, outputTokens: 1);
        Classification::fake(function (ClassificationPrompt $prompt) use ($usage) {
            $this->assertSame(['decision'], array_keys($prompt->questions));

            return new ClassificationResponse($this->passingAnswers($prompt), $usage, new Meta('typesafe', 'single-model'));
        })->preventStrayClassifications();
        $assessment = app(TollTroll::class)->assess($this->context(), Policy::bugReport());

        $this->assertSame(Verdict::Pass, $assessment->verdict);
        $this->assertSame($usage->toArray(), $assessment->usage);
        $this->assertSame(0.91, $assessment->score);
    }

    private function context(): Context
    {
        return new Context('Triage reports.', ['report' => 'SOURCE_MARKER']);
    }

    private function passingAnswers(ClassificationPrompt $prompt): array
    {
        return array_fill_keys(array_reverse(array_keys($prompt->questions)),
            new ChoiceAnswer('pass', ['pass' => 0.91, 'fail' => 0.06, 'unknown' => 0.03]));
    }
}

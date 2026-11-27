<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\EvaluationConfig;
use Vos\TollTroll\Events\ReviewRequired;
use Vos\TollTroll\Policy;
use Vos\TollTroll\Tests\Fixtures\DomainPolicy;
use Vos\TollTroll\TollTroll;
use Vos\TollTroll\TollTrollServiceProvider;
use Vos\TollTroll\Verdict;

class TollTrollTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, TollTrollServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('toll-troll.variants', 3);
        Queue::fake()->serializeAndRestore();
        Event::fake([ReviewRequired::class]);
        Paraphraser::fake([$this->rewrite(1), $this->rewrite(2), $this->rewrite(3)])->preventStrayPrompts();
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answer(prompt: $prompt))->preventStrayClassifications();
    }

    public function test_crossing_a_bridge_uses_its_instructions_guards_and_configuration_and_keeps_its_name_in_jobs(): void
    {
        $bridge = new Bridge('support-webhook', 'Triage incoming support reports.', ['bug' => [Policy::bugReport()]],
            guards: [new Policy('in_scope', 'Pass for reports about this application.')],
            config: new EvaluationConfig(variants: 0, injectionGuard: false));
        $sources = ['report' => 'Saving a draft returns HTTP 500.'];
        $result = app(TollTroll::class)->cross($bridge, $sources);
        $this->assertSame('support-webhook', $result->bridge);
        $this->assertSame($bridge->instructions, $result->context->instructions);
        $this->assertSame($sources, $result->context->sources);
        $this->assertSame([], $result->variants);
        $this->assertSame(['in_scope'], array_map(fn ($guard) => $guard->policy->id, $result->guards));
        $this->assertTrue($result->allows('bug'));
        Paraphraser::assertNeverPrompted();
        app(TollTroll::class)->dispatch($result, ['bug' => fn ($evaluation) => new RecordDecision($evaluation)]);
        Queue::assertPushed(RecordDecision::class, fn ($job) => $job->evaluation->bridge === 'support-webhook');
        $second = app(TollTroll::class)->cross($bridge, ['report' => 'Different input.']);
        $this->assertNotSame($result->id, $second->id);
        $this->assertSame('support-webhook', $second->bridge);
        $this->assertSame($sources, $result->context->sources);
        $this->assertSame(3, config('toll-troll.variants'));
    }

    #[DataProvider('invalidBridges')]
    public function test_bridges_require_an_entrypoint_name_and_trusted_instructions(string $name, string $instructions): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Bridge($name, $instructions, ['bug' => [Policy::bugReport()]]);
    }

    public static function invalidBridges(): array
    {
        return [['', 'Triage.'], ['support/webhook', 'Triage.'], [str_repeat('a', 65), 'Triage.'], ['support', ' ']];
    }

    public function test_opt_in_paraphrasing_preserves_original_and_three_variants_and_queues_snapshot(): void
    {
        $context = $this->context();
        $result = app(TollTroll::class)->evaluate($context, [
            'bug' => [Policy::bugReport(), Policy::expectedAndActualBehavior(), Policy::reproductionSteps()],
            'feature' => [Policy::featureRequest()],
        ]);

        $this->assertTrue($result->allows('bug'));
        $this->assertTrue($result->allows('feature'));
        $this->assertFalse($result->needsReview());
        $this->assertCount(3, $result->variants);
        $this->assertSame(['original', 'variant_1', 'variant_2', 'variant_3'], array_column($result->guards[0]->assessments, 'sample'));
        $this->assertSame($context, $result->context);
        $this->assertSame('bug_report', $result->branches['bug'][0]->policy->id);
        $this->assertNotSame($result->actionKey('bug'), $result->actionKey('feature'));

        $dispatched = app(TollTroll::class)->dispatch($result, [
            'bug' => fn (Evaluation $evaluation) => new RecordDecision($evaluation),
        ]);

        $this->assertSame(['bug'], $dispatched);
        Queue::assertPushed(RecordDecision::class, function (RecordDecision $job) use ($result, $context) {
            $this->assertSame($context->sources, $job->evaluation->context->sources);
            $this->assertSame($context->fingerprint(), $job->evaluation->context->fingerprint());
            $this->assertSame($result->actionKey('bug'), $job->evaluation->actionKey('bug'));

            return $job->evaluation->allows('bug');
        });
        Event::assertNotDispatched(ReviewRequired::class);
    }

    #[DataProvider('blockedSamples')]
    public function test_a_single_suspicious_sample_blocks_every_action(string $text): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answer(
            $prompt->state['sources']['report'] === $text ? 'fail' : 'pass',
            $prompt,
        ));

        $result = app(TollTroll::class)->evaluate($this->context(), [
            'bug' => [Policy::bugReport()],
            'feature' => [Policy::featureRequest()],
        ]);

        $this->assertSame(Verdict::Fail, $result->guards[0]->verdict);
        $this->assertSame(Verdict::Skipped, $result->branches['bug'][0]->verdict);
        $this->assertSame([], app(TollTroll::class)->dispatch($result, [
            'bug' => fn () => throw new RuntimeException('A blocked factory must not execute.'),
            'feature' => fn () => throw new RuntimeException('A blocked factory must not execute.'),
        ]));
        Queue::assertNothingPushed();
        Event::assertDispatched(ReviewRequired::class, fn ($event) => $event->evaluation === $result);
    }

    public static function blockedSamples(): array
    {
        return [
            'original alone detects injection' => ['Saving a draft returns HTTP 500.'],
            'second paraphrase alone detects injection' => ['Rewrite 2: Saving a draft returns HTTP 500.'],
            'last paraphrase alone detects injection' => ['Rewrite 3: Saving a draft returns HTTP 500.'],
        ];
    }

    public function test_layered_branches_stop_on_failure_without_stopping_other_branches(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answers($prompt, fn ($key) => $key === 'policy_2' ? $this->answer('fail')['decision'] : $this->answer()['decision']
        ))->preventStrayClassifications();

        $result = app(TollTroll::class)->evaluate($this->context(), [
            'bug' => [Policy::bugReport(), Policy::expectedAndActualBehavior(), Policy::reproductionSteps()],
            'feature' => [Policy::featureRequest()],
        ]);

        $this->assertFalse($result->allows('bug'));
        $this->assertTrue($result->allows('feature'));
        $this->assertFalse($result->needsReview());
        $this->assertSame(Verdict::Skipped, $result->branches['bug'][2]->verdict);
        $this->assertSame([], $result->branches['bug'][2]->assessments);
        $this->assertSame('fail', $result->branches['bug'][1]->assessments[0]->choice);
        $this->assertSame(['feature'], app(TollTroll::class)->dispatch($result, [
            'bug' => fn () => throw new RuntimeException('Should not execute.'),
            'feature' => fn ($evaluation) => new RecordDecision($evaluation),
        ]));
        Queue::assertPushed(RecordDecision::class, 1);
    }

    public function test_original_business_failure_cannot_be_overruled_by_rewrites(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answers($prompt, fn ($key) => $this->answer($key === 'policy_1' && $prompt->state['sources'] === $this->context()->sources ? 'fail' : 'pass')['decision']
        ))->preventStrayClassifications();

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertTrue($result->guarded());
        $this->assertSame(Verdict::Unknown, $result->branches['bug'][0]->verdict);
        $this->assertTrue($result->needsReview());
        $this->assertSame([], app(TollTroll::class)->dispatch($result, ['bug' => fn ($evaluation) => new RecordDecision($evaluation)]));
        Queue::assertNothingPushed();
    }

    public function test_custom_guards_run_before_business_policies(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answers($prompt, fn ($key) => $this->answer($key === 'policy_1' ? 'unknown' : 'pass')['decision']
        ))->preventStrayClassifications();

        $result = app(TollTroll::class)->evaluate(
            $this->context(),
            ['bug' => [Policy::bugReport()]],
            guards: [new Policy('scope', 'The report concerns the supported application.')],
        );

        $this->assertSame(Verdict::Pass, $result->guards[0]->verdict);
        $this->assertSame(Verdict::Unknown, $result->guards[1]->verdict);
        $this->assertSame(Verdict::Skipped, $result->branches['bug'][0]->verdict);
        $this->assertFalse($result->allows('bug'));
    }

    public function test_multi_sample_aggregation_preserves_a_dissenting_sample_with_one_classifier(): void
    {
        config()->set('toll-troll.paraphrasers', [
            ['provider' => 'openai', 'model' => 'rewrite-a'],
            ['provider' => 'anthropic', 'model' => 'rewrite-b'],
        ]);
        config()->set('toll-troll.classifier', ['provider' => 'gemini', 'model' => 'judge']);
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answer(
            str_starts_with($prompt->state['sources']['report'], 'Rewrite 2:') ? 'fail' : 'pass', $prompt,
        ));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame(['rewrite-a', 'rewrite-b', 'rewrite-a'], array_column($result->variants, 'model'));
        $this->assertSame(['openai', 'anthropic', 'openai'], array_column($result->variants, 'provider'));
        $this->assertCount(4, $result->guards[0]->assessments);
        $this->assertSame(Verdict::Fail, $result->guards[0]->verdict);
        $this->assertSame(['judge'], array_values(array_unique(array_column($result->guards[0]->assessments, 'model'))));
        $this->assertCount(4, $result->classificationCalls);
        $this->assertFalse($result->allows('bug'));
        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->provider->name() === 'gemini' && $prompt->model === 'judge');
    }

    #[DataProvider('invalidAnswers')]
    public function test_invalid_classifier_outputs_fail_closed(ClassificationResponse $answer): void
    {
        Classification::fake(function (ClassificationPrompt $prompt) use ($answer) {
            $answers = $this->answers($prompt, fn () => $answer->answers['decision'] ?? null);
            if (array_key_exists('reason', $answer->answers)) {
                $answers['reason'] = $answer->answers['reason'];
            }

            return new ClassificationResponse($answers, $answer->usage, $answer->meta);
        })->preventStrayClassifications();

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame(Verdict::Error, $result->guards[0]->verdict);
        $this->assertNotNull($result->guards[0]->assessments[0]->error);
        $this->assertFalse($result->allows('bug'));
        $this->assertTrue($result->needsReview());
    }

    public static function invalidAnswers(): array
    {
        $cases = [
            'missing decision' => [],
            'wrong answer type' => ['decision' => new BooleanAnswer(1.0)],
            'scalar answer' => ['decision' => 'pass'],
            'unexpected answer' => ['decision' => new ChoiceAnswer('pass', ['pass' => 1.0, 'fail' => 0.0, 'unknown' => 0.0]), 'reason' => new BooleanAnswer(1.0)],
            'invalid choice' => ['decision' => new ChoiceAnswer('safe', ['pass' => 1.0, 'fail' => 0.0, 'unknown' => 0.0])],
            'choice contradicts distribution' => ['decision' => new ChoiceAnswer('pass', ['pass' => 0.1, 'fail' => 0.9, 'unknown' => 0.0])],
        ];
        foreach ([
            'missing probability' => ['pass' => 1.0, 'fail' => 0.0],
            'unexpected probability' => ['pass' => 1.0, 'fail' => 0.0, 'unknown' => 0.0, 'other' => 0.0],
            'nonnumeric probability' => ['pass' => 'safe', 'fail' => 0.0, 'unknown' => 0.0],
            'string probability' => ['pass' => '1.0', 'fail' => 0.0, 'unknown' => 0.0],
            'negative probability' => ['pass' => 1.0, 'fail' => -0.1, 'unknown' => 0.1],
            'probability above one' => ['pass' => 1.1, 'fail' => 0.0, 'unknown' => 0.0],
            'unnormalized distribution' => ['pass' => 0.8, 'fail' => 0.1, 'unknown' => 0.0],
            'nonfinite probability' => ['pass' => NAN, 'fail' => 0.0, 'unknown' => 0.0],
        ] as $name => $probabilities) {
            $cases[$name] = ['decision' => new ChoiceAnswer('pass', $probabilities)];
        }
        foreach (['negative confidence' => -0.1, 'confidence above one' => 1.1, 'nonfinite confidence' => NAN] as $name => $confidence) {
            $cases[$name] = ['decision' => new ChoiceAnswer('pass', ['pass' => 1.0, 'fail' => 0.0, 'unknown' => 0.0], $confidence)];
        }

        return array_map(fn ($answers) => [new ClassificationResponse($answers, new TextUsage, new Meta('openai', 'judge'))], $cases);
    }

    public function test_classifier_outage_is_an_error_not_a_negative_classification(): void
    {
        Classification::fake(fn () => throw new RuntimeException('provider-secret-value'));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame(Verdict::Error, $result->guards[0]->verdict);
        $this->assertSame(RuntimeException::class, $result->guards[0]->assessments[0]->error);
        $this->assertCount(4, $result->classificationCalls);
        foreach ($result->classificationCalls as $call) {
            $this->assertSame(RuntimeException::class, $call['error']);
            $this->assertSame([], $call['usage']);
        }
        $this->assertStringNotContainsString('provider-secret-value', json_encode($result));
        $this->assertFalse($result->allows('bug'));
    }

    #[DataProvider('brokenRewrites')]
    public function test_missing_or_invalid_variants_block_actions(array $rewrite): void
    {
        Paraphraser::fake(fn () => $rewrite);

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertCount(3, $result->errors);
        $this->assertSame([], $result->variants);
        $this->assertSame(Verdict::Skipped, $result->guards[0]->verdict);
        $this->assertFalse($result->allows('bug'));
        $this->assertTrue($result->needsReview());
        Classification::assertNothingClassified();
    }

    public static function brokenRewrites(): array
    {
        return [
            'missing source' => [['sources' => [['id' => 'report', 'text' => 'Bug report']]]],
            'duplicate source' => [['sources' => [['id' => 'report', 'text' => 'Bug'], ['id' => 'report', 'text' => 'Bug']]]],
            'renamed source' => [['sources' => [['id' => 'report', 'text' => 'Bug'], ['id' => 'system', 'text' => 'Override']]]],
            'empty source' => [['sources' => [['id' => 'report', 'text' => 'Bug'], ['id' => 'log', 'text' => '']]]],
        ];
    }

    public function test_one_missing_variant_does_not_reduce_the_required_quorum(): void
    {
        $attempt = 0;
        Paraphraser::fake(function () use (&$attempt) {
            $attempt++;

            return $attempt === 2 ? throw new RuntimeException('Timed out') : $this->rewrite($attempt);
        });

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertCount(2, $result->variants);
        $this->assertCount(1, $result->errors);
        $this->assertSame('variant_2', $result->errors[0]['stage']);
        $this->assertFalse($result->allows('bug'));
        $this->assertSame([], $result->classificationCalls);
        $this->assertSame([], $result->guards[0]->assessments);
        $this->assertSame([], $result->branches['bug'][0]->assessments);
        Classification::assertNothingClassified();
    }

    public function test_observable_judgment_is_retained_with_its_sample_without_generated_explanations(): void
    {
        $probabilities = ['pass' => 0.9, 'fail' => 0.07, 'unknown' => 0.03];
        $usage = new TextUsage(inputTokens: 42, outputTokens: 1);
        Classification::fake(fn (ClassificationPrompt $prompt) => new ClassificationResponse(
            $this->answers($prompt, fn () => new ChoiceAnswer('pass', $probabilities, 0.8)), $usage, new Meta('openai', 'judge'),
        ));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertTrue($result->allows('bug'));
        $assessment = $result->branches['bug'][0]->assessments[2];
        $this->assertSame('variant_2', $assessment->sample);
        $this->assertSame('pass', $assessment->choice);
        $this->assertSame($probabilities, $assessment->probabilities);
        $this->assertSame(0.8, $assessment->confidence);
        $this->assertSame(0.9, $assessment->score);
        $this->assertSame(0.07, $assessment->failProbability);
        $this->assertSame([], $assessment->usage);
        $this->assertCount(4, $result->classificationCalls);
        $this->assertSame(['original', 'variant_1', 'variant_2', 'variant_3'], array_column($result->classificationCalls, 'sample'));
        foreach ($result->classificationCalls as $call) {
            $this->assertSame($usage->toArray(), $call['usage']);
            $this->assertSame('openai', $call['provider']);
            $this->assertSame('judge', $call['model']);
            $this->assertArrayNotHasKey('error', $call);
        }
        foreach (['reason', 'evidence', 'missing'] as $field) {
            $this->assertArrayNotHasKey($field, get_object_vars($assessment));
        }
    }

    public function test_unknown_routes_are_rejected_before_any_job_is_queued(): void
    {
        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        try {
            app(TollTroll::class)->dispatch($result, [
                'bug' => fn ($evaluation) => new RecordDecision($evaluation),
                'typo' => fn ($evaluation) => new RecordDecision($evaluation),
            ]);
            $this->fail('Unknown route accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unknown branch: typo', $exception->getMessage());
            Queue::assertNothingPushed();
        }
    }

    public function test_actions_must_be_queued_jobs(): void
    {
        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->expectException(InvalidArgumentException::class);
        app(TollTroll::class)->dispatch($result, ['bug' => fn () => new \stdClass]);
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_classifier_and_variant_configuration_is_rejected(string $key, mixed $value): void
    {
        config()->set('toll-troll.'.$key, $value);

        $this->expectException(ValidationException::class);
        try {
            app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);
        } finally {
            Paraphraser::assertNeverPrompted();
            Classification::assertNothingClassified();
        }
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['variants', -1], ['classifier', []], ['classifier', [['provider' => null, 'model' => null]]], ['paraphrasers', []],
            'legacy classifier list' => ['classifiers', [['provider' => 'gemini', 'model' => 'legacy-judge']]],
            'legacy empty classifier list' => ['classifiers', []],
            'legacy null classifier list' => ['classifiers', null],
            ['injection_guard', ['pass' => 0.7, 'fail' => 0.7]],
            ['injection_guard', ['pass' => 0.3, 'fail' => 0.7]],
            ['injection_guard.pass', 1.01], ['injection_guard.fail', -0.01],
            ['injection_guard.pass', null],
            ['injection_guard.enabled', 'false'], ['injection_guard.enabled', null],
            ['injection_guard', null],
        ];
    }

    #[DataProvider('thresholdScores')]
    public function test_policy_thresholds_determine_verdicts_and_dispatch(?float $score, Verdict $expected): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answers($prompt, fn ($key) => $key === 'policy_0' ? $this->answer()['decision'] : $this->scoreAnswer($score)['decision']
        ))->preventStrayClassifications();
        $policy = Policy::bugReport()->withThresholds(pass: 0.86, fail: 0.18);

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [$policy]]);

        $this->assertSame($expected, $result->branches['bug'][0]->verdict);
        $this->assertSame($score, $result->branches['bug'][0]->assessments[0]->score);
        $this->assertSame(0.86, $result->branches['bug'][0]->policy->passThreshold);
        $this->assertSame(0.18, $result->branches['bug'][0]->policy->failThreshold);
        $this->assertSame($expected === Verdict::Unknown, $result->needsReview());
        $this->assertSame($expected === Verdict::Pass ? ['bug'] : [], app(TollTroll::class)->dispatch($result, [
            'bug' => fn ($evaluation) => new RecordDecision($evaluation),
        ]));
        Queue::assertPushed(RecordDecision::class, $expected === Verdict::Pass ? 1 : 0);
    }

    public static function thresholdScores(): array
    {
        return [
            'zero' => [0.0, Verdict::Fail],
            'below fail' => [0.1799, Verdict::Fail],
            'at fail' => [0.18, Verdict::Fail],
            'above fail' => [0.1801, Verdict::Unknown],
            'below pass' => [0.8599, Verdict::Unknown],
            'at pass' => [0.86, Verdict::Pass],
            'one' => [1.0, Verdict::Pass],
            'abstention' => [null, Verdict::Unknown],
        ];
    }

    #[DataProvider('asymmetricJudgments')]
    public function test_unknown_mass_is_not_failure_and_explicit_unknown_always_abstains(string $choice, array $probabilities, float $pass, float $fail, ?float $score, Verdict $expected): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->answers($prompt, fn () => new ChoiceAnswer($choice, $probabilities)))->preventStrayClassifications();
        $policy = Policy::bugReport()->withThresholds(pass: $pass, fail: $fail);
        $troll = app(TollTroll::class);
        $result = $troll->evaluate($this->context(), ['bug' => [$policy]], config: new EvaluationConfig(variants: 0, injectionGuard: false));
        $assessment = $result->branches['bug'][0]->assessments[0];

        $this->assertSame($expected, $assessment->verdict);
        $this->assertSame($score, $assessment->score);
        $this->assertSame($probabilities['fail'], $assessment->failProbability);
        $this->assertSame($choice, $assessment->choice);
        $this->assertSame($probabilities, $assessment->probabilities);
        $this->assertNull($assessment->confidence);
        $this->assertSame($expected === Verdict::Unknown, $result->needsReview());
        $this->assertSame([], $troll->dispatch($result, ['bug' => fn ($evaluation) => new RecordDecision($evaluation)]));
        Queue::assertNothingPushed();
    }

    public static function asymmetricJudgments(): array
    {
        return [
            'low pass with substantial unknown is not fail' => ['fail', ['pass' => 0.09, 'fail' => 0.46, 'unknown' => 0.45], 0.86, 0.18, 0.09, Verdict::Unknown],
            'high unknown with low pass abstains' => ['unknown', ['pass' => 0.05, 'fail' => 0.05, 'unknown' => 0.9], 0.86, 0.18, null, Verdict::Unknown],
            'explicit unknown despite passing probability' => ['unknown', ['pass' => 0.45, 'fail' => 0.05, 'unknown' => 0.5], 0.4, 0.2, null, Verdict::Unknown],
            'explicit unknown despite failing probability' => ['unknown', ['pass' => 0.05, 'fail' => 0.45, 'unknown' => 0.5], 0.8, 0.6, null, Verdict::Unknown],
            'below fail cutoff with unknown mass' => ['fail', ['pass' => 0.08, 'fail' => 0.8199, 'unknown' => 0.1001], 0.86, 0.18, 0.08, Verdict::Unknown],
            'at fail cutoff with unknown mass' => ['fail', ['pass' => 0.08, 'fail' => 1 - 0.18, 'unknown' => 0.1], 0.86, 0.18, 0.08, Verdict::Fail],
            'above fail cutoff with unknown mass' => ['fail', ['pass' => 0.08, 'fail' => 0.8201, 'unknown' => 0.0999], 0.86, 0.18, 0.08, Verdict::Fail],
        ];
    }

    public function test_policies_can_apply_different_thresholds_to_the_same_score(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->scoreAnswer(0.87, $prompt));
        $base = Policy::bugReport();
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'triage' => [$base->withThresholds(pass: 0.85, fail: 0.15)],
            'implement' => [new Policy('bug_report', $base->criteria, passThreshold: 0.9, failThreshold: 0.25)],
        ]);

        $this->assertTrue($result->allows('triage'));
        $this->assertFalse($result->allows('implement'));
        $this->assertSame(Verdict::Unknown, $result->branches['implement'][0]->verdict);
        $this->assertSame(0.8, $base->passThreshold);
        $this->assertSame(0.2, $base->failThreshold);
    }

    #[DataProvider('guardScores')]
    public function test_configured_injection_thresholds_preserve_single_variant_veto(?float $score, Verdict $expected): void
    {
        config()->set('toll-troll.injection_guard', ['pass' => 0.98, 'fail' => 0.15]);
        Classification::fake(fn (ClassificationPrompt $prompt) => $this->scoreAnswer(
            str_starts_with($prompt->state['sources']['report'], 'Rewrite 2:') ? $score : 1.0,
            $prompt,
        ));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame($expected, $result->guards[0]->verdict);
        $this->assertSame(0.98, $result->guards[0]->policy->passThreshold);
        $this->assertSame(0.15, $result->guards[0]->policy->failThreshold);
        $this->assertSame($score, $result->guards[0]->assessments[2]->score);
        $this->assertSame($expected === Verdict::Pass, $result->allows('bug'));
        app(TollTroll::class)->dispatch($result, ['bug' => fn ($evaluation) => new RecordDecision($evaluation)]);
        Queue::assertPushed(RecordDecision::class, $expected === Verdict::Pass ? 1 : 0);
    }

    public static function guardScores(): array
    {
        return [
            'at fail' => [0.15, Verdict::Fail],
            'just below pass' => [0.9799, Verdict::Unknown],
            'at pass' => [0.98, Verdict::Pass],
            'abstention' => [null, Verdict::Unknown],
        ];
    }

    #[DataProvider('invalidThresholds')]
    public function test_invalid_policy_thresholds_are_rejected(float $pass, float $fail): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::bugReport()->withThresholds($pass, $fail);
    }

    public static function invalidThresholds(): array
    {
        return [[0.5, 0.5], [0.4, 0.6], [1.01, 0.2], [0.8, -0.01], [NAN, 0.2], [0.8, INF]];
    }

    public function test_empty_branch_cannot_become_implicitly_actionable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TollTroll::class)->evaluate($this->context(), ['bug' => []]);
    }

    public function test_classification_keeps_sources_and_references_separate_from_structured_instructions(): void
    {
        $context = $this->context();
        $policy = new Policy('documented_behavior', 'Compare against documentation.', references: ['report' => 'Saving a draft returns HTTP 201.']);
        $assessment = app(TollTroll::class)->assess($context, $policy);
        $this->assertSame(Verdict::Pass, $assessment->verdict);
        Classification::assertClassified(function (ClassificationPrompt $prompt) use ($context, $policy) {
            $this->assertSame(['sources' => $context->sources, 'references' => $policy->references], $prompt->state);
            $this->assertSame(['decision'], array_keys($prompt->questions));
            $question = $prompt->questions['decision'];
            $this->assertInstanceOf(Choice::class, $question);
            $this->assertIsArray($question->instructions);
            $this->assertSame($context->instructions, $question->instructions['target_instructions']);
            $this->assertSame(['id' => $policy->id, 'criteria' => $policy->criteria], $question->instructions['policy']);
            $this->assertSame(['pass', 'fail', 'unknown'], array_keys($question->options));
            foreach ([...array_values($context->sources), ...array_values($policy->references)] as $text) {
                $this->assertStringNotContainsString($text, json_encode($question->instructions));
            }

            return true;
        });
    }

    public function test_dependent_classifications_receive_only_the_sample_not_previous_results(): void
    {
        $calls = 0;
        $samples = [$this->context()->sources];
        foreach ([1, 2, 3] as $variant) {
            $samples[] = array_column($this->rewrite($variant)['sources'], 'text', 'id');
        }
        Classification::fake(function (ClassificationPrompt $prompt) use (&$calls, $samples) {
            $this->assertSame(['sources'], array_keys($prompt->state));
            $this->assertContains($prompt->state['sources'], $samples);
            $this->assertSame(['policy_0', 'policy_1', 'policy_2'], array_keys($prompt->questions));
            foreach ($prompt->questions as $question) {
                $this->assertSame(['id', 'criteria'], array_keys($question->instructions['policy']));
            }
            $this->assertSame([], $prompt->attachments);
            $calls++;

            return $this->answer(prompt: $prompt);
        })->preventStrayClassifications();

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport(), Policy::reproductionSteps()]]);

        $this->assertTrue($result->allows('bug'));
        $this->assertSame(4, $calls);
    }

    public function test_injection_screening_includes_references_from_all_branches_and_custom_guards(): void
    {
        $result = app(TollTroll::class)->evaluate($this->context(), [
            'bug' => [new Policy('check', 'Check bug.', references: ['manual' => 'Bug documentation.'])],
            'feature' => [new Policy('check', 'Check feature.', references: ['manual' => 'Feature documentation.'])],
        ], guards: [new Policy('check', 'Check access.', references: ['manual' => 'Access documentation.'])]);
        $this->assertEqualsCanonicalizing(['Bug documentation.', 'Feature documentation.', 'Access documentation.'], array_values($result->guards[0]->policy->references));
        $this->assertSame(['manual' => 'Bug documentation.'], $result->branches['bug'][0]->policy->references);
        $this->assertSame(['manual' => 'Feature documentation.'], $result->branches['feature'][0]->policy->references);
        $this->assertSame(['manual' => 'Access documentation.'], $result->guards[1]->policy->references);
    }

    public function test_with_references_replaces_context_without_changing_the_original_policy(): void
    {
        $base = Policy::bugReport()->withThresholds(pass: 0.91, fail: 0.13);
        $references = ['manual' => 'Drafts must save.', 'code' => 'DraftController::store'];
        $policy = $base->withReferences($references);
        $this->assertNotSame($base, $policy);
        $this->assertSame([], $base->references);
        $this->assertSame(['bug_report', $base->criteria, 0.91, 0.13, $references],
            [$policy->id, $policy->criteria, $policy->passThreshold, $policy->failThreshold, $policy->references]);
        $replacement = $policy->withReferences(['manual' => 'Updated documentation.']);
        $this->assertSame(['manual' => 'Updated documentation.'], $replacement->references);
        $this->assertSame($references, $policy->references);
        $this->assertEquals($base, $policy->withReferences([]));
        $this->assertSame($references, $policy->withThresholds(pass: 0.87, fail: 0.12)->references);
    }

    public function test_with_references_validates_replacement_context(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::bugReport()->withReferences(['manual' => ' ']);
    }

    #[DataProvider('invalidReferences')]
    public function test_policy_references_require_named_resolved_utf8_text(array $references, string $exception): void
    {
        $this->expectException($exception);
        new Policy('test', 'Check.', references: $references);
    }

    public static function invalidReferences(): array
    {
        return [
            [['text without name'], InvalidArgumentException::class],
            [[' ' => 'text'], InvalidArgumentException::class],
            [['manual' => ' '], InvalidArgumentException::class],
            [['manual' => null], InvalidArgumentException::class],
            [['manual' => fn () => 'deferred'], InvalidArgumentException::class],
            [['manual' => "\xFF"], \JsonException::class],
        ];
    }

    public function test_custom_policy_data_is_frozen_before_inference_and_survives_queued_dispatch(): void
    {
        config()->set('toll-troll.variants', 0);
        $policy = new DomainPolicy;
        $original = Policy::from($policy);
        Classification::fake(function (ClassificationPrompt $prompt) use ($policy) {
            $this->assertStringNotContainsString($policy->internal, json_encode([$prompt->state, $prompt->questions]));
            $this->assertStringContainsString('Drafts must save.', json_encode($prompt->state));
            // Even changes during guard inference cannot alter this evaluation.
            $policy->pass = 0.99;
            $policy->documents = ['manual' => 'Changed after capture.'];

            return $this->scoreAnswer(0.9, $prompt);
        })->preventStrayClassifications();
        $troll = app(TollTroll::class);
        $result = $troll->evaluate($this->context(), ['bug' => [$policy]], guards: [new DomainPolicy(name: 'custom_guard')]);
        $this->assertTrue($result->allows('bug'));
        $this->assertEquals($original, $result->branches['bug'][0]->policy);
        $this->assertSame('custom_guard', $result->guards[1]->policy->id);
        $troll->dispatch($result, ['bug' => fn ($result) => new RecordDecision($result)]);
        Queue::assertPushed(RecordDecision::class, function ($job) use ($original) {
            $this->assertEquals($original, $job->evaluation->branches['bug'][0]->policy);
            $this->assertStringNotContainsString('PRIVATE_IMPLEMENTATION_MARKER', serialize($job));

            return true;
        });
    }

    #[DataProvider('customPolicyScores')]
    public function test_direct_assessment_uses_custom_thresholds(float $score, Verdict $verdict): void
    {
        Classification::fake(fn () => $this->scoreAnswer($score));
        $this->assertSame($verdict, app(TollTroll::class)->assess($this->context(), new DomainPolicy)->verdict);
    }

    public static function customPolicyScores(): array
    {
        return [[0.7, Verdict::Pass], [0.69, Verdict::Unknown], [0.1, Verdict::Fail], [0.11, Verdict::Unknown]];
    }

    #[DataProvider('invalidCustomPolicies')]
    public function test_invalid_custom_policies_are_rejected_before_inference(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            app(TollTroll::class)->evaluate($this->context(), ['bug' => [new DomainPolicy(...$arguments)]]);
        } finally {
            Classification::assertNothingClassified();
            Paraphraser::assertNeverPrompted();
        }
    }

    public static function invalidCustomPolicies(): array
    {
        return [[['name' => ' ']], [['rubric' => '']], [['pass' => NAN]], [['fail' => 0.8]], [['documents' => ['manual' => null]]]];
    }

    #[DataProvider('withoutAutomaticGuard')]
    public function test_disabling_injection_screening_preserves_explicit_guards_and_branch_decisions(?float $guardScore, float $branchScore, bool $allowed, bool $review): void
    {
        config()->set('toll-troll.variants', 0);
        config()->set('toll-troll.injection_guard.enabled', false);
        $calls = 0;
        Classification::fake(function (ClassificationPrompt $prompt) use (&$calls, $guardScore, $branchScore) {
            $calls++;

            return $this->answers($prompt, fn ($key, $question) => $this->scoreAnswer(
                $question->instructions['policy']['id'] === 'documented_behavior' ? $guardScore : $branchScore,
            )['decision']);
        })->preventStrayClassifications();
        $troll = app(TollTroll::class);
        $result = $troll->evaluate($this->context(), ['bug' => [Policy::bugReport()]],
            guards: $guardScore === null ? [] : [new DomainPolicy]);
        $this->assertCount($guardScore === null ? 0 : 1, $result->guards);
        $this->assertSame($allowed, $result->allows('bug'));
        $this->assertSame($review, $result->needsReview());
        if ($guardScore !== null) {
            $this->assertSame('documented_behavior', $result->guards[0]->policy->id);
            $this->assertSame(0.7, $result->guards[0]->policy->passThreshold);
        }
        $this->assertSame($allowed ? ['bug'] : [], $troll->dispatch($result, ['bug' => fn ($result) => new RecordDecision($result)]));
        $this->assertSame($guardScore !== null ? 2 : 1, $calls);
        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->asks('policy_0'));
    }

    public static function withoutAutomaticGuard(): array
    {
        return ['no guards pass' => [null, 0.9, true, false], 'branch fail' => [null, 0.1, false, false],
            'branch unknown' => [null, 0.5, false, true], 'custom guard pass' => [0.75, 0.9, true, false],
            'custom guard fail' => [0.05, 0.9, false, true], 'custom guard unknown' => [0.5, 0.9, false, true]];
    }

    public function test_rewrite_failure_still_blocks_when_no_guards_are_configured(): void
    {
        config()->set('toll-troll.injection_guard.enabled', false);
        Paraphraser::fake(fn () => throw new RuntimeException('Rewrite failed.'));
        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);
        $this->assertFalse($result->guarded());
        $this->assertFalse($result->allows('bug'));
        $this->assertTrue($result->needsReview());
        Classification::assertNothingClassified();
    }

    public function test_bridge_classifier_and_timeout_apply_to_every_guard_and_branch_without_changing_defaults(): void
    {
        config()->set('toll-troll.classifier', ['provider' => 'openai', 'model' => 'default']);
        config()->set('toll-troll.timeout', 47);
        $defaults = config('toll-troll');
        $config = new EvaluationConfig(variants: 0, classifier: ['provider' => 'gemini', 'model' => 'bridge-judge'], classificationTimeout: 31);
        $troll = app(TollTroll::class);
        $prompts = [];
        Classification::fake(function (ClassificationPrompt $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return $this->answer(prompt: $prompt);
        })->preventStrayClassifications();
        $bridge = new Bridge('shared-configuration', $this->context()->instructions,
            ['custom' => [new DomainPolicy], 'built-in' => [Policy::bugReport()]], guards: [new Policy('scope', 'Check scope.')], config: $config);
        $result = $troll->cross($bridge, $this->context()->sources);
        $this->assertTrue($result->allows('custom'));
        $this->assertTrue($result->allows('built-in'));
        $this->assertCount(2, $result->guards);
        $this->assertSame([], $result->variants);
        foreach ([...$result->guards, ...$result->branches['custom'], ...$result->branches['built-in']] as $policy) {
            $this->assertSame(['bridge-judge'], array_column($policy->assessments, 'model'));
            $this->assertSame(['gemini'], array_column($policy->assessments, 'provider'));
        }
        foreach ($prompts as $prompt) {
            $this->assertSame(31, $prompt->timeout);
            $this->assertSame('gemini', $prompt->provider->name());
            $this->assertSame('bridge-judge', $prompt->model);
        }
        $this->assertSame($defaults, config('toll-troll'));
        $prompts = [];
        $next = $troll->evaluate($this->context(), ['custom' => [new DomainPolicy]]);
        $this->assertCount(1, $next->guards);
        $this->assertCount(3, $next->variants);
        $this->assertSame(array_fill(0, 4, 'default'), array_column($next->branches['custom'][0]->assessments, 'model'));
        foreach ($prompts as $prompt) {
            $this->assertSame(47, $prompt->timeout);
        }
    }

    public function test_null_engine_fields_select_sdk_defaults_and_rewrites_keep_separate_settings(): void
    {
        $troll = app(TollTroll::class);
        $sdk = $troll->assess($this->context(), Policy::bugReport());
        config()->set('toll-troll.classifier', ['provider' => 'gemini', 'model' => 'global-judge']);
        $result = $troll->evaluate($this->context(), ['custom' => [new DomainPolicy]], config: new EvaluationConfig(
            variants: 1, paraphrasers: [['provider' => 'openai', 'model' => 'rewrite-only']], paraphraseTimeout: 9,
            classifier: ['provider' => null, 'model' => null], classificationTimeout: 31,
            injectionPassThreshold: 0.93,
        ));
        $this->assertTrue($result->allows('custom'));
        $this->assertSame(0.93, $result->guards[0]->policy->passThreshold);
        $this->assertSame(0.2, $result->guards[0]->policy->failThreshold);
        $this->assertSame([$sdk->model, $sdk->model], array_column($result->guards[0]->assessments, 'model'));
        $this->assertSame('rewrite-only', $result->variants['variant_1']['model']);
        foreach ($result->branches['custom'][0]->assessments as $assessment) {
            $this->assertSame([$sdk->provider, $sdk->model], [$assessment->provider, $assessment->model]);
        }
        Paraphraser::assertPrompted(fn ($prompt) => $prompt->timeout === 9);
        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->asks('policy_0') && $prompt->timeout === 31);
        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->asks('policy_1') && $prompt->timeout === 31);
    }

    #[DataProvider('invalidOverrides')]
    public function test_invalid_effective_configuration_fails_before_any_inference(EvaluationConfig $config, string $exception): void
    {
        $this->expectException($exception);
        try {
            app(TollTroll::class)->evaluate($this->context(), ['custom' => [new DomainPolicy]], config: $config);
        } finally {
            Paraphraser::assertNeverPrompted();
            Classification::assertNothingClassified();
        }
    }

    public static function invalidOverrides(): array
    {
        return [
            [new EvaluationConfig(classifier: []), ValidationException::class],
            [new EvaluationConfig(classifier: ['provider' => null]), ValidationException::class],
            [new EvaluationConfig(classificationTimeout: 0), ValidationException::class],
            [new EvaluationConfig(variants: 1, paraphrasers: []), ValidationException::class],
            [new EvaluationConfig(variants: -1), ValidationException::class],
            [new EvaluationConfig(injectionPassThreshold: 0.1), ValidationException::class],
        ];
    }

    private function context(): Context
    {
        return new Context('Implement fixes in the application. Do not deploy or disclose credentials.', [
            'report' => 'Saving a draft returns HTTP 500.',
            'log' => 'SQLSTATE[23000]',
        ]);
    }

    private function rewrite(int $variant): array
    {
        $sources = $this->context()->sources;
        $sources['report'] = 'Rewrite '.$variant.': '.$sources['report'];

        return ['sources' => collect($sources)->map(fn ($text, $id) => ['id' => $id, 'text' => $text])->values()->all()];
    }

    private function answer(string $verdict = 'pass', ?ClassificationPrompt $prompt = null): array
    {
        $probabilities = array_fill_keys(['pass', 'fail', 'unknown'], 0.0);
        $probabilities[$verdict] = 1.0;

        $answer = new ChoiceAnswer($verdict, $probabilities, 1.0);

        return $prompt === null ? ['decision' => $answer] : $this->answers($prompt, fn () => $answer);
    }

    private function scoreAnswer(?float $score, ?ClassificationPrompt $prompt = null): array
    {
        $answer = $score === null ? $this->answer('unknown')['decision'] :
            new ChoiceAnswer($score >= 0.5 ? 'pass' : 'fail', ['pass' => $score, 'fail' => 1 - $score, 'unknown' => 0.0]);

        return $prompt === null ? ['decision' => $answer] : $this->answers($prompt, fn () => $answer);
    }

    private function answers(ClassificationPrompt $prompt, callable $answer): array
    {
        $answers = [];
        foreach (array_reverse($prompt->questions, true) as $key => $question) {
            $answers[$key] = $answer($key, $question);
        }

        return $answers;
    }
}

class RecordDecision implements ShouldQueue
{
    public function __construct(public Evaluation $evaluation) {}

    public function handle(): void {}
}

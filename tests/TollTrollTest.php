<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Vos\TollTroll\Agents\Classifier;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\EvaluationConfig;
use Vos\TollTroll\Events\ReviewRequired;
use Vos\TollTroll\Policy;
use Vos\TollTroll\PolicyConfig;
use Vos\TollTroll\Tests\Fixtures\AttributedPolicy;
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
        Classifier::fake(fn () => $this->answer())->preventStrayPrompts();
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
        Classifier::fake(fn (string $prompt) => $this->answer(
            json_decode($prompt, true)['sources']['report'] === $text ? 'fail' : 'pass',
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

    public function test_layered_branches_stop_on_missing_information_without_stopping_other_branches(): void
    {
        Classifier::fake([
            ...array_fill(0, 8, $this->answer()), // Guard and bug detection pass.
            ...array_fill(0, 4, $this->answer('fail', missing: ['Expected behavior'])),
            ...array_fill(0, 4, $this->answer()), // Independent feature branch passes.
        ])->preventStrayPrompts();

        $result = app(TollTroll::class)->evaluate($this->context(), [
            'bug' => [Policy::bugReport(), Policy::expectedAndActualBehavior(), Policy::reproductionSteps()],
            'feature' => [Policy::featureRequest()],
        ]);

        $this->assertFalse($result->allows('bug'));
        $this->assertTrue($result->allows('feature'));
        $this->assertFalse($result->needsReview());
        $this->assertSame(Verdict::Skipped, $result->branches['bug'][2]->verdict);
        $this->assertSame([], $result->branches['bug'][2]->assessments);
        $this->assertSame(['Expected behavior'], $result->branches['bug'][1]->assessments[0]->missing);
        $this->assertSame(['feature'], app(TollTroll::class)->dispatch($result, [
            'bug' => fn () => throw new RuntimeException('Should not execute.'),
            'feature' => fn ($evaluation) => new RecordDecision($evaluation),
        ]));
        Queue::assertPushed(RecordDecision::class, 1);
    }

    public function test_original_business_failure_cannot_be_overruled_by_rewrites(): void
    {
        Classifier::fake([
            ...array_fill(0, 4, $this->answer()),
            $this->answer('fail'),
            ...array_fill(0, 3, $this->answer()),
        ])->preventStrayPrompts();

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertTrue($result->guarded());
        $this->assertSame(Verdict::Unknown, $result->branches['bug'][0]->verdict);
        $this->assertTrue($result->needsReview());
        $this->assertSame([], app(TollTroll::class)->dispatch($result, ['bug' => fn ($evaluation) => new RecordDecision($evaluation)]));
        Queue::assertNothingPushed();
    }

    public function test_custom_guards_run_before_business_policies(): void
    {
        Classifier::fake([
            ...array_fill(0, 4, $this->answer()),
            ...array_fill(0, 4, $this->answer('unknown')),
        ])->preventStrayPrompts();

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

    public function test_provider_ensemble_preserves_a_dissenting_classifier(): void
    {
        config()->set('toll-troll.paraphrasers', [
            ['provider' => 'openai', 'model' => 'rewrite-a'],
            ['provider' => 'anthropic', 'model' => 'rewrite-b'],
        ]);
        config()->set('toll-troll.classifiers', [
            ['provider' => 'openai', 'model' => 'judge-a'],
            ['provider' => 'anthropic', 'model' => 'judge-b'],
        ]);
        Classifier::fake(fn ($prompt, $attachments, $provider, $model) => $this->answer($model === 'judge-b' ? 'fail' : 'pass'));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame(['rewrite-a', 'rewrite-b', 'rewrite-a'], array_column($result->variants, 'model'));
        $this->assertSame(['openai', 'anthropic', 'openai'], array_column($result->variants, 'provider'));
        $this->assertCount(8, $result->guards[0]->assessments);
        $this->assertSame(Verdict::Fail, $result->guards[0]->verdict);
        $this->assertSame(['judge-a', 'judge-b'], array_values(array_unique(array_column($result->guards[0]->assessments, 'model'))));
        $this->assertFalse($result->allows('bug'));
    }

    #[DataProvider('invalidAnswers')]
    public function test_invalid_classifier_outputs_fail_closed(array $answer): void
    {
        Classifier::fake(fn () => $answer);

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame(Verdict::Error, $result->guards[0]->verdict);
        $this->assertNotNull($result->guards[0]->assessments[0]->error);
        $this->assertFalse($result->allows('bug'));
        $this->assertTrue($result->needsReview());
    }

    public static function invalidAnswers(): array
    {
        $answer = ['score' => 1, 'reason' => 'Matches.', 'evidence' => [], 'missing' => []];

        return [
            'nonnumeric score' => [array_replace($answer, ['score' => 'safe'])],
            'empty score' => [array_replace($answer, ['score' => ''])],
            'string score' => [array_replace($answer, ['score' => '0.99'])],
            'score below zero' => [array_replace($answer, ['score' => -0.01])],
            'score above one' => [array_replace($answer, ['score' => 1.01])],
            'missing score' => [array_diff_key($answer, ['score' => true])],
            'missing evidence field' => [array_diff_key($answer, ['evidence' => true])],
            'invented source' => [array_replace($answer, ['evidence' => [['source' => 'invented', 'quote' => 'HTTP 500']]])],
            'invented quotation' => [array_replace($answer, ['evidence' => [['source' => 'report', 'quote' => 'HTTP 200']]])],
        ];
    }

    public function test_classifier_outage_is_an_error_not_a_negative_classification(): void
    {
        Classifier::fake(fn () => throw new RuntimeException('provider-secret-value'));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertSame(Verdict::Error, $result->guards[0]->verdict);
        $this->assertSame(RuntimeException::class, $result->guards[0]->assessments[0]->error);
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
    }

    public function test_valid_evidence_is_retained_with_its_sample(): void
    {
        Classifier::fake(fn () => $this->answer(evidence: [['source' => 'log', 'quote' => 'SQLSTATE[23000]']]));

        $result = app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);

        $this->assertTrue($result->allows('bug'));
        $this->assertSame([['source' => 'log', 'quote' => 'SQLSTATE[23000]']], $result->branches['bug'][0]->assessments[2]->evidence);
        $this->assertSame('variant_2', $result->branches['bug'][0]->assessments[2]->sample);
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
    public function test_empty_ensembles_and_invalid_variant_counts_are_rejected(string $key, mixed $value): void
    {
        config()->set('toll-troll.'.$key, $value);

        $this->expectException(ValidationException::class);
        app(TollTroll::class)->evaluate($this->context(), ['bug' => [Policy::bugReport()]]);
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['variants', -1], ['classifiers', []], ['paraphrasers', []],
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
        Classifier::fake([
            ...array_fill(0, 4, $this->answer()),
            ...array_fill(0, 4, array_replace($this->answer(), ['score' => $score])),
        ])->preventStrayPrompts();
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

    public function test_policies_can_apply_different_thresholds_to_the_same_score(): void
    {
        Classifier::fake(fn () => array_replace($this->answer(), ['score' => 0.87]));
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
        Classifier::fake(fn (string $prompt) => array_replace($this->answer(), [
            'score' => str_starts_with(json_decode($prompt, true)['sources']['report'], 'Rewrite 2:') ? $score : 1,
        ]));

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

    #[DataProvider('referenceEvidence')]
    public function test_reference_evidence_cannot_be_misattributed_to_a_source(array $evidence, Verdict $expected): void
    {
        Classifier::fake(fn () => $this->answer(evidence: [$evidence]));
        $policy = new Policy('documented_behavior', 'Compare against documentation.', references: ['report' => 'Saving a draft returns HTTP 201.']);
        $assessment = app(TollTroll::class)->assess($this->context(), $policy);
        $this->assertSame($expected, $assessment->verdict);
        $this->assertSame($expected === Verdict::Pass ? [$evidence] : [], $assessment->evidence);
    }

    public static function referenceEvidence(): array
    {
        return [
            'reference quote' => [['kind' => 'reference', 'source' => 'report', 'quote' => 'HTTP 201'], Verdict::Pass],
            'reference attributed to source' => [['kind' => 'source', 'source' => 'report', 'quote' => 'HTTP 201'], Verdict::Error],
            'omitted kind means source' => [['source' => 'report', 'quote' => 'HTTP 201'], Verdict::Error],
            'source attributed to reference' => [['kind' => 'reference', 'source' => 'report', 'quote' => 'HTTP 500'], Verdict::Error],
            'invented reference' => [['kind' => 'reference', 'source' => 'other', 'quote' => 'HTTP 201'], Verdict::Error],
            'invalid kind' => [['kind' => 'trusted', 'source' => 'report', 'quote' => 'HTTP 201'], Verdict::Error],
        ];
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
        Classifier::fake(function (string $prompt) use ($policy) {
            $this->assertStringNotContainsString($policy->internal, $prompt);
            $this->assertStringContainsString('Drafts must save.', $prompt);
            // Even changes during guard inference cannot alter this evaluation.
            $policy->pass = 0.99;
            $policy->documents = ['manual' => 'Changed after capture.'];

            return ['score' => 0.9, 'reason' => 'Fixture.', 'evidence' => [], 'missing' => []];
        })->preventStrayPrompts();
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
        Classifier::fake(fn () => ['score' => $score, 'reason' => 'Fixture.', 'evidence' => [], 'missing' => []]);
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
            Classifier::assertNeverPrompted();
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
        Classifier::fake(array_map(fn ($score) => ['score' => $score, 'reason' => 'Fixture.', 'evidence' => [], 'missing' => []],
            $guardScore === null ? [$branchScore] : [$guardScore, $branchScore]))->preventStrayPrompts();
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
        Classifier::assertPromptedTimes($guardScore !== null && $guardScore >= 0.7 ? 2 : 1);
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
        Classifier::assertNeverPrompted();
    }

    #[DataProvider('classificationOverrides')]
    public function test_classification_settings_resolve_per_policy_without_changing_defaults(?PolicyConfig $run, ?PolicyConfig $policy, array $models, int $timeout): void
    {
        config()->set('toll-troll.classifiers', [['provider' => 'openai', 'model' => 'default']]);
        $defaults = config('toll-troll');
        $config = new EvaluationConfig(variants: 0, paraphrasers: [], injectionGuard: false, classification: $run,
            policies: $policy === null ? [] : ['documented_behavior' => $policy]);
        $troll = app(TollTroll::class);
        $result = $troll->evaluate($this->context(), ['custom' => [new AttributedPolicy], 'built-in' => [Policy::bugReport()]], config: $config);
        $this->assertTrue($result->allows('custom'));
        $this->assertSame([], $result->guards);
        $this->assertSame([], $result->variants);
        $this->assertSame($models, array_column($result->branches['custom'][0]->assessments, 'model'));
        $this->assertSame($run?->classifiers === null ? ['default'] : ['run'], array_column($result->branches['built-in'][0]->assessments, 'model'));
        Classifier::assertPrompted(fn ($prompt) => $prompt->agent->policy->id === 'documented_behavior' && $prompt->timeout === $timeout);
        $this->assertSame($defaults, config('toll-troll'));
        $next = $troll->evaluate($this->context(), ['custom' => [new AttributedPolicy]]);
        $this->assertCount(1, $next->guards);
        $this->assertCount(3, $next->variants);
        $this->assertCount(8, $next->branches['custom'][0]->assessments);
    }

    public static function classificationOverrides(): array
    {
        $run = new PolicyConfig(classifiers: [['provider' => 'openai', 'model' => 'run']], timeout: 29);

        return [
            'attribute' => [null, null, ['attribute-a', 'attribute-b'], 17],
            'run replaces attribute ensemble' => [$run, null, ['run'], 29],
            'policy timeout inherits run models' => [$run, new PolicyConfig(timeout: 11), ['run'], 11],
            'policy models inherit attribute timeout' => [null, new PolicyConfig(classifiers: [['provider' => 'openai', 'model' => 'policy']]), ['policy'], 17],
            'policy models override run models' => [$run, new PolicyConfig(classifiers: [['provider' => 'openai', 'model' => 'policy']]), ['policy'], 29],
        ];
    }

    public function test_null_engine_fields_select_sdk_defaults_and_rewrites_keep_separate_settings(): void
    {
        $troll = app(TollTroll::class);
        $sdk = $troll->assess($this->context(), Policy::bugReport());
        $result = $troll->evaluate($this->context(), ['custom' => [new AttributedPolicy]], config: new EvaluationConfig(
            variants: 1, paraphrasers: [['provider' => 'openai', 'model' => 'rewrite-only']], paraphraseTimeout: 9,
            classification: new PolicyConfig(classifiers: [['provider' => null, 'model' => null]], timeout: 31),
            injectionPassThreshold: 0.93,
            policies: ['no_prompt_injection' => new PolicyConfig(classifiers: [['provider' => 'openai', 'model' => 'guard-only']], timeout: 7)],
        ));
        $this->assertTrue($result->allows('custom'));
        $this->assertSame(0.93, $result->guards[0]->policy->passThreshold);
        $this->assertSame(0.2, $result->guards[0]->policy->failThreshold);
        $this->assertSame(['guard-only', 'guard-only'], array_column($result->guards[0]->assessments, 'model'));
        $this->assertSame('rewrite-only', $result->variants['variant_1']['model']);
        foreach ($result->branches['custom'][0]->assessments as $assessment) {
            $this->assertSame([$sdk->provider, $sdk->model], [$assessment->provider, $assessment->model]);
        }
        Paraphraser::assertPrompted(fn ($prompt) => $prompt->timeout === 9);
        Classifier::assertPrompted(fn ($prompt) => $prompt->agent->policy->id === 'no_prompt_injection' && $prompt->timeout === 7);
        Classifier::assertPrompted(fn ($prompt) => $prompt->agent->policy->id === 'documented_behavior' && $prompt->timeout === 31);
    }

    #[DataProvider('invalidOverrides')]
    public function test_invalid_effective_configuration_fails_before_any_inference(EvaluationConfig $config, string $exception): void
    {
        $this->expectException($exception);
        try {
            app(TollTroll::class)->evaluate($this->context(), ['custom' => [new AttributedPolicy]], config: $config);
        } finally {
            Paraphraser::assertNeverPrompted();
            Classifier::assertNeverPrompted();
        }
    }

    public static function invalidOverrides(): array
    {
        return [
            [new EvaluationConfig(classification: new PolicyConfig(classifiers: [])), ValidationException::class],
            [new EvaluationConfig(classification: new PolicyConfig(timeout: 0)), ValidationException::class],
            [new EvaluationConfig(variants: 1, paraphrasers: []), ValidationException::class],
            [new EvaluationConfig(variants: -1), ValidationException::class],
            [new EvaluationConfig(injectionPassThreshold: 0.1), ValidationException::class],
            [new EvaluationConfig(policies: ['typo' => new PolicyConfig(timeout: 9)]), InvalidArgumentException::class],
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

    private function answer(string $verdict = 'pass', array $evidence = [], array $missing = []): array
    {
        $score = match ($verdict) {
            'pass' => 1,
            'fail' => 0,
            'unknown' => null,
        };

        return ['score' => $score, 'reason' => 'Fixture decision.', 'evidence' => $evidence, 'missing' => $missing];
    }
}

class RecordDecision implements ShouldQueue
{
    public function __construct(public Evaluation $evaluation) {}

    public function handle(): void {}
}

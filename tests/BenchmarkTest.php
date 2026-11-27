<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Benchmark;
use Vos\TollTroll\Context;
use Vos\TollTroll\Events\ReviewRequired;
use Vos\TollTroll\Policy;
use Vos\TollTroll\Tests\Fixtures\DomainPolicy;
use Vos\TollTroll\TollTroll;
use Vos\TollTroll\TollTrollServiceProvider;

class BenchmarkTest extends TestCase
{
    private string $directory;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, TollTrollServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/toll-troll-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config()->set('ai.providers.local', ['driver' => 'openai-compatible', 'url' => 'https://inference.example.test/v1', 'models' => ['text' => ['default' => 'fixture']]]);
        config()->set('ai.providers.local-classification', ['driver' => 'typesafe', 'key' => 'fixture-key', 'url' => 'https://classification.example.test/v1']);
        Http::preventStrayRequests();
        Bus::fake();
        Event::fake([ReviewRequired::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_default_gate_uses_only_original_and_does_not_require_a_paraphraser(): void
    {
        $this->assertFalse(Route::has('toll-troll-annotations.index'));
        $this->assertSame([], Event::dispatch(new AnnotationRequested(
            'support', 'ticket-1', new Context('Triage reports.', ['report' => 'Save crashes.']), Policy::bugReport(),
        )));
        $this->assertSame(0, config('toll-troll.variants'));
        config()->set('toll-troll.paraphrasers', []);
        Paraphraser::fake()->preventStrayPrompts();
        Classification::fake(fn (ClassificationPrompt $prompt) => array_fill_keys(array_keys($prompt->questions),
            new ChoiceAnswer('pass', ['pass' => 0.94, 'fail' => 0.04, 'unknown' => 0.02])))->preventStrayClassifications();
        $result = app(TollTroll::class)->evaluate(new Context('Triage reports.', ['report' => 'Save crashes.']), ['bug' => [Policy::bugReport()]]);
        $this->assertTrue($result->allows('bug'));
        $this->assertSame([], $result->variants);
        $this->assertCount(1, $result->guards[0]->assessments);
        $this->assertSame([], Event::dispatch(new EvaluationReviewRequested('support', 'ticket-1', $result)));
        Paraphraser::assertNeverPrompted();
    }

    public function test_group_weights_thresholds_and_errors_are_not_vote_counts_or_zero_scores(): void
    {
        $bench = app(Benchmark::class);
        $groups = ['original' => [['score' => 0.875, 'failProbability' => 0.125]],
            'repeated' => [['score' => 0.25, 'failProbability' => 0.75], ['score' => 0.5, 'failProbability' => 0.5]],
            'paraphrased' => [['score' => 0.125, 'failProbability' => 0.875]]];
        $profile = ['aggregation' => 'weighted', 'weights' => ['original' => 1, 'repeated' => 1, 'paraphrased' => 2], 'pass' => 0.8, 'fail' => 0.2];
        $decision = $bench->score($groups, $profile);
        $this->assertSame(0.375, $decision['score']);
        $this->assertSame('unknown', $decision['verdict']);
        $this->assertSame('pass', $bench->score($groups, array_replace($profile, ['pass' => 0.375]))['verdict']);
        $this->assertSame('fail', $bench->score($groups, array_replace($profile, ['fail' => 0.375]))['verdict']);
        $this->assertSame('fail', $bench->score($groups, array_replace($profile, ['aggregation' => 'veto']))['verdict']);
        $groups['paraphrased'] = [['score' => null, 'failProbability' => null, 'error' => 'ProviderFailure']];
        $this->assertSame(['verdict' => 'error', 'score' => null], $bench->score($groups, $profile));
        $groups['paraphrased'] = [['score' => null, 'failProbability' => 0]];
        $this->assertSame(['verdict' => 'unknown', 'score' => null], $bench->score($groups, $profile));
        $profile['weights'] = ['original' => 1];
        $this->assertSame('pass', $bench->score($groups, $profile)['verdict']);
    }

    #[DataProvider('referenceSets')]
    public function test_command_collects_private_reports_without_labels_in_prompts_and_replays_without_inference(array $references): void
    {
        Http::fake(['https://inference.example.test/v1/chat/completions' => function ($request) {
            $this->assertStringNotContainsString('ground-truth-marker', json_encode($request->data()));
            $this->assertStringNotContainsString('historical-prediction-marker', json_encode($request->data()));
            $input = json_decode($request['messages'][1]['content'], true);
            $sources = $input['sources'];
            $this->assertArrayNotHasKey('references', $input);
            $this->assertStringNotContainsString('REFERENCE_MARKER', $request['messages'][0]['content']);
            $answer = ['sources' => [['id' => 'report', 'text' => 'Rewritten '.$sources['report']]]];

            return Http::response(['model' => $request['model'], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode($answer)]]]]);
        }, 'https://classification.example.test/v1/systemone' => function ($request) use ($references) {
            $this->assertStringNotContainsString('ground-truth-marker', json_encode($request->data()));
            $this->assertStringNotContainsString('historical-prediction-marker', json_encode($request->data()));
            $this->assertSame($references, $request['state']['references'] ?? []);
            $this->assertStringNotContainsString('REFERENCE_MARKER', json_encode($request['questions']));
            $fail = $request['model'] === 'other-model' || str_starts_with($request['state']['sources']['report'], 'Rewritten');

            return Http::response(['model' => $request['model'], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
                'answers' => ['decision' => ['type' => 'choice', 'choice' => $fail ? 'fail' : 'pass',
                    'probabilities' => ['pass' => $fail ? 0.1 : 0.9, 'fail' => $fail ? 0.9 : 0.1, 'unknown' => 0], 'confidence' => 0.8]]]);
        }]);
        $this->writeSuite(references: $references);
        $case = ['id' => 'ground-truth-marker', 'expected' => 'fail', 'instructions' => 'Triage reports.', 'sources' => ['report' => 'Save crashes.'],
            'prediction' => ['verdict' => 'pass', 'assessments' => [['reason' => 'historical-prediction-marker']]],
            'policy' => ['id' => 'no_prompt_injection', 'criteria' => Policy::noPromptInjection()->criteria]];
        if ($references !== []) {
            $case['policy']['references'] = $references;
        }
        $oversizedText = $references === [] ? str_repeat('🧌', 1001) : str_repeat('x', 4001 - array_sum(array_map('strlen', $references)));
        $oversized = array_replace($case, ['id' => 'oversized', 'sources' => ['report' => $oversizedText]]);
        file_put_contents($this->directory.'/cases.jsonl', json_encode($oversized)."\n".json_encode($case)."\n");
        $args = ['suite' => $this->directory.'/suite.php', 'dataset' => $this->directory.'/cases.jsonl'];
        $this->artisan('toll-troll:benchmark', $args + ['--dry-run' => true])->expectsOutputToContain('1 records; 1 skipped; at most 9 SDK prompt calls')->assertSuccessful();
        Http::assertNothingSent();
        $this->artisan('toll-troll:benchmark', $args + ['--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        Http::assertSentCount(9);
        $captured = json_decode(file_get_contents($this->directory.'/capture.json'), true);
        $this->assertSame(2, $captured['format']);
        $this->assertSame(0.1, $captured['records'][0]['models']['fixture']['original'][0]['failProbability']);
        $this->assertSame($case['policy'], $captured['capture']['policy']);
        $this->assertSame([['id' => 'oversized', 'reason' => $references === [] ? 'source_size_limit' : 'context_size_limit']], $captured['skipped']);
        $this->assertSame(0600, fileperms($this->directory.'/capture.json') & 0777);
        $this->assertSame('pass', $captured['summary']['fixture']['original']['decisions'][0]['verdict']);
        $this->assertSame('fail', $captured['summary']['comparison']['original']['decisions'][0]['verdict']);
        $this->assertSame('fail', $captured['summary']['fixture']['veto']['decisions'][0]['verdict']);
        $this->assertSame(50, $captured['summary']['fixture']['veto']['input_tokens']);
        $this->assertSame(1, $captured['summary']['fixture']['original']['false_passes']);
        $this->assertSame(0, $captured['summary']['fixture']['veto']['false_passes']);
        // Change only the threshold and prove the original verdict is recomputed.
        $this->writeSuite(0.95, $references);
        Http::fake();
        $this->artisan('toll-troll:benchmark', ['suite' => $this->directory.'/suite.php', '--replay' => $this->directory.'/capture.json', '--output' => $this->directory.'/replayed.json'])->assertSuccessful();
        $replayed = json_decode(file_get_contents($this->directory.'/replayed.json'), true);
        $this->assertSame('unknown', $replayed['summary']['fixture']['original']['decisions'][0]['verdict']);
        $this->assertSame($captured['records'], $replayed['records']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        Event::assertNotDispatched(ReviewRequired::class);
    }

    public static function referenceSets(): array
    {
        return ['legacy without references' => [[]], 'resolved references' => [['manual' => 'REFERENCE_MARKER: Drafts must save.']]];
    }

    #[DataProvider('changedReferenceInputs')]
    public function test_changed_references_reject_replay_and_annotated_labels(bool $replay): void
    {
        $this->writeSuite(references: ['manual' => 'Original documentation.']);
        $case = ['id' => 'ticket', 'expected' => 'pass', 'instructions' => 'Triage.', 'sources' => ['report' => 'Save crashes.'],
            'policy' => ['id' => 'no_prompt_injection', 'criteria' => Policy::noPromptInjection()->criteria, 'references' => ['manual' => 'Original documentation.']]];
        file_put_contents($this->directory.'/cases.jsonl', json_encode($case)."\n");
        $args = ['suite' => $this->directory.'/suite.php', 'dataset' => $this->directory.'/cases.jsonl'];
        $classifications = 0;
        Classification::fake(function () use (&$classifications) {
            $classifications++;

            return ['decision' => new ChoiceAnswer('pass', ['pass' => 1, 'fail' => 0, 'unknown' => 0])];
        })->preventStrayClassifications();
        Paraphraser::fake([['sources' => [['id' => 'report', 'text' => 'Save fails.']]]]);
        $this->artisan('toll-troll:benchmark', $args + ['--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        $this->writeSuite(references: ['manual' => 'Changed documentation.']);
        Classification::fake()->preventStrayClassifications();
        Paraphraser::fake()->preventStrayPrompts();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($replay ? 'matching capture' : 'references do not match');
        try {
            $this->artisan('toll-troll:benchmark', $args + ['--output' => $this->directory.'/new.json', '--yes' => true]
                + ($replay ? ['--replay' => $this->directory.'/capture.json'] : []))->run();
        } finally {
            $this->assertSame(8, $classifications);
            Classification::assertNotClassified(fn (ClassificationPrompt $prompt) => $prompt->state['references'] === ['manual' => 'Changed documentation.']);
            Paraphraser::assertPromptedTimes(1);
            $this->assertFileDoesNotExist($this->directory.'/new.json');
        }
    }

    public static function changedReferenceInputs(): array
    {
        return ['capture replay' => [true], 'annotated dataset' => [false]];
    }

    public function test_combined_reference_size_limit_includes_rewrites_and_accepts_exact_boundary(): void
    {
        $this->writeSuite(references: ['manual' => '🧌']);
        $case = ['id' => 'ticket', 'expected' => 'pass', 'instructions' => 'Triage.', 'sources' => ['report' => 'Save crashes.']];
        file_put_contents($this->directory.'/cases.jsonl', json_encode($case)."\n");
        Classification::fake(fn () => ['decision' => new ChoiceAnswer('pass', ['pass' => 1, 'fail' => 0, 'unknown' => 0])])->preventStrayClassifications();
        Paraphraser::fake([['sources' => [['id' => 'report', 'text' => 'Save crashes.!']]]]);
        $args = ['suite' => $this->directory.'/suite.php', 'dataset' => $this->directory.'/cases.jsonl', '--max-bytes' => 17];
        $this->artisan('toll-troll:benchmark', $args + ['--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        $report = json_decode(file_get_contents($this->directory.'/capture.json'), true);
        $this->assertSame([], $report['skipped']);
        $this->assertSame('OverflowException', $report['records'][0]['rewrites'][0]['error']);
        $this->assertSame('error', $report['summary']['fixture']['veto']['decisions'][0]['verdict']);
        $this->assertSame('pass', $report['summary']['fixture']['original']['decisions'][0]['verdict']);
    }

    public function test_command_accepts_custom_policy_value_objects(): void
    {
        file_put_contents($this->directory.'/suite.php', <<<'PHP'
            <?php
            return [
                'policy' => new \Vos\TollTroll\Tests\Fixtures\DomainPolicy,
                'models' => ['classifier_1' => ['provider' => 'openai', 'model' => 'benchmark-override']],
                'timeout' => 11,
                'profiles' => ['custom' => ['aggregation' => 'unanimous', 'weights' => ['original' => 1], 'pass' => 0.7, 'fail' => 0.1]],
            ];
            PHP);
        file_put_contents($this->directory.'/cases.jsonl', json_encode(['id' => 'ticket', 'expected' => 'pass', 'instructions' => 'Triage.',
            'sources' => ['report' => 'Save crashes.'], 'policy' => ['id' => 'documented_behavior',
                'criteria' => 'Pass when reported behavior contradicts the documented contract.', 'references' => ['manual' => 'Drafts must save.']]])."\n");
        $classifications = 0;
        Classification::fake(function (ClassificationPrompt $prompt) use (&$classifications) {
            $classifications++;
            $this->assertSame(['manual' => 'Drafts must save.'], $prompt->state['references']);

            return ['decision' => new ChoiceAnswer('pass', ['pass' => 0.75, 'fail' => 0.25, 'unknown' => 0])];
        })->preventStrayClassifications();
        $args = ['suite' => $this->directory.'/suite.php', 'dataset' => $this->directory.'/cases.jsonl'];
        $this->artisan('toll-troll:benchmark', $args + ['--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        $report = json_decode(file_get_contents($this->directory.'/capture.json'), true);
        $this->assertSame(1, $report['summary']['classifier_1']['custom']['correct']);
        $this->assertSame(['classifier_1' => ['provider' => 'openai', 'model' => 'benchmark-override']], $report['capture']['models']);
        $this->assertSame(11, $report['capture']['timeout']);
        $this->assertSame('benchmark-override', $report['records'][0]['models']['classifier_1']['original'][0]['model']);
        $this->assertStringNotContainsString('PRIVATE_IMPLEMENTATION_MARKER', json_encode($report));
        $this->artisan('toll-troll:benchmark', $args + ['--replay' => $this->directory.'/capture.json', '--output' => $this->directory.'/replay.json'])->assertSuccessful();
        $this->assertSame(1, $classifications);
        Http::assertNothingSent();
    }

    public function test_benchmark_uses_global_classifier_and_timeout_unless_explicit_suite_settings_override_them(): void
    {
        config()->set('toll-troll.classifier', ['provider' => 'gemini', 'model' => 'global-judge']);
        config()->set('toll-troll.timeout', 19);
        $benchmark = app(Benchmark::class);
        $input = ['policy' => new DomainPolicy,
            'profiles' => ['original' => ['aggregation' => 'unanimous', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2]]];
        $defaults = $benchmark->suite($input);
        $this->assertSame(['classifier_1' => ['provider' => 'gemini', 'model' => 'global-judge']], $defaults['models']);
        $this->assertSame(19, $defaults['timeout']);
        // Explicit benchmark models do not consult obsolete global classifier settings.
        config()->set('toll-troll.classifiers', [['provider' => 'openai', 'model' => 'legacy-judge']]);
        $explicit = $benchmark->suite($input + [
            'models' => ['comparison' => ['provider' => null, 'model' => null]], 'timeout' => 37,
        ]);
        $this->assertSame(['comparison' => ['provider' => null, 'model' => null]], $explicit['models']);
        $this->assertSame(37, $explicit['timeout']);
        $this->assertNotSame($benchmark->captureSpecification($defaults), $benchmark->captureSpecification($explicit));
    }

    public function test_benchmark_rejects_legacy_global_classifier_list_when_defaulting_models_before_inference(): void
    {
        config()->set('toll-troll.classifiers', [['provider' => 'gemini', 'model' => 'legacy-judge']]);
        Classification::fake()->preventStrayClassifications();
        Paraphraser::fake()->preventStrayPrompts();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Replace toll-troll.classifiers');
        try {
            app(Benchmark::class)->suite([
                'policy' => Policy::bugReport(),
                'profiles' => ['original' => ['aggregation' => 'unanimous', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2]],
            ]);
        } finally {
            Classification::assertNothingClassified();
            Paraphraser::assertNeverPrompted();
            Http::assertNothingSent();
        }
    }

    #[DataProvider('obsoleteSuiteConfigs')]
    public function test_benchmark_rejects_obsolete_suite_config_even_with_explicit_models(mixed $config): void
    {
        Classification::fake()->preventStrayClassifications();
        Paraphraser::fake()->preventStrayPrompts();
        try {
            app(Benchmark::class)->suite([
                'policy' => Policy::bugReport(), 'config' => $config,
                'models' => ['explicit' => ['provider' => 'typesafe', 'model' => 'judge']],
                'profiles' => ['original' => ['aggregation' => 'unanimous', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2]],
            ]);
            $this->fail('Obsolete suite config was silently ignored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['config'], array_keys($exception->errors()));
        } finally {
            Classification::assertNothingClassified();
            Paraphraser::assertNeverPrompted();
            Http::assertNothingSent();
        }
    }

    public static function obsoleteSuiteConfigs(): array
    {
        return ['null is still present' => [null], 'empty config is still present' => [[]], 'old settings' => [['timeout' => 17]]];
    }

    public function test_unknown_probability_mass_is_not_failure_support_and_replay_agrees(): void
    {
        file_put_contents($this->directory.'/suite.php', <<<'PHP'
            <?php
            return [
                'policy' => \Vos\TollTroll\Policy::bugReport(),
                'models' => ['fixture' => ['provider' => 'typesafe', 'model' => 'fixture']],
                'profiles' => [
                    'weighted' => ['aggregation' => 'weighted', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2],
                    'unanimous' => ['aggregation' => 'unanimous', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2],
                    'veto' => ['aggregation' => 'veto', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2],
                ],
            ];
            PHP);
        file_put_contents($this->directory.'/cases.jsonl', json_encode([
            'id' => 'uncertain', 'expected' => 'unknown', 'instructions' => 'Triage.', 'sources' => ['report' => 'Save behaves oddly.'],
        ])."\n");
        // Fail is the modal choice, but unknown mass means 1 - P(pass) is not P(fail).
        $classifications = 0;
        Classification::fake(function () use (&$classifications) {
            $classifications++;

            return ['decision' => new ChoiceAnswer('fail', ['pass' => 0.1, 'fail' => 0.46, 'unknown' => 0.44])];
        })->preventStrayClassifications();
        $this->artisan('toll-troll:benchmark', ['suite' => $this->directory.'/suite.php', 'dataset' => $this->directory.'/cases.jsonl',
            '--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        $captured = json_decode(file_get_contents($this->directory.'/capture.json'), true);
        $assessment = $captured['records'][0]['models']['fixture']['original'][0];
        $this->assertSame(0.1, $assessment['score']);
        $this->assertSame(0.46, $assessment['failProbability']);
        foreach ($captured['summary']['fixture'] as $summary) {
            $this->assertSame('unknown', $summary['decisions'][0]['verdict']);
        }
        $this->artisan('toll-troll:benchmark', ['suite' => $this->directory.'/suite.php', '--replay' => $this->directory.'/capture.json',
            '--output' => $this->directory.'/replay.json'])->assertSuccessful();
        $replayed = json_decode(file_get_contents($this->directory.'/replay.json'), true);
        $this->assertSame($captured['summary'], $replayed['summary']);
        $this->assertSame(1, $classifications);
        Http::assertNothingSent();
    }

    public function test_scalar_format_one_captures_cannot_be_replayed(): void
    {
        $this->writeSuite();
        $suite = app(Benchmark::class)->suite(require $this->directory.'/suite.php');
        file_put_contents($this->directory.'/legacy.json', json_encode([
            'format' => 1, 'complete' => true, 'capture' => app(Benchmark::class)->captureSpecification($suite), 'records' => [],
        ]));
        Classification::fake()->preventStrayClassifications();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('matching capture');
        try {
            $this->artisan('toll-troll:benchmark', ['suite' => $this->directory.'/suite.php', '--replay' => $this->directory.'/legacy.json'])->run();
        } finally {
            Classification::assertNothingClassified();
            Http::assertNothingSent();
        }
    }

    private function writeSuite(float $pass = 0.8, array $references = []): void
    {
        file_put_contents($this->directory.'/suite.php', '<?php return '.var_export([
            'models' => ['fixture' => ['provider' => 'local-classification', 'model' => 'fixture'], 'comparison' => ['provider' => 'local-classification', 'model' => 'other-model']],
            'paraphraser' => ['provider' => 'local', 'model' => 'fixture'],
            'repeats' => 2, 'variants' => 1,
            'profiles' => [
                'original' => ['aggregation' => 'weighted', 'weights' => ['original' => 1], 'pass' => $pass, 'fail' => 0.2],
                'veto' => ['aggregation' => 'veto', 'weights' => ['original' => 1, 'repeated' => 1, 'paraphrased' => 1], 'pass' => $pass, 'fail' => 0.2],
            ],
        ], true).' + ["policy" => new \\Vos\\TollTroll\\Policy("no_prompt_injection", \\Vos\\TollTroll\\Policy::noPromptInjection()->criteria, references: '.var_export($references, true).')];');
    }
}

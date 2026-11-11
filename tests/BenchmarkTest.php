<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Vos\TollTroll\Agents\Classifier;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Benchmark;
use Vos\TollTroll\Context;
use Vos\TollTroll\Events\ReviewRequired;
use Vos\TollTroll\Policy;
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
        Classifier::fake(fn () => ['score' => 1, 'reason' => 'Clear.', 'evidence' => [], 'missing' => []]);
        $result = app(TollTroll::class)->evaluate(new Context('Triage reports.', ['report' => 'Save crashes.']), ['bug' => [Policy::bugReport()]]);
        $this->assertTrue($result->allows('bug'));
        $this->assertSame([], $result->variants);
        $this->assertCount(1, $result->guards[0]->assessments);
        Paraphraser::assertNeverPrompted();
    }

    public function test_group_weights_thresholds_and_errors_are_not_vote_counts_or_zero_scores(): void
    {
        $bench = app(Benchmark::class);
        $groups = ['original' => [['score' => 0.875]], 'repeated' => [['score' => 0.25], ['score' => 0.5]], 'paraphrased' => [['score' => 0.125]]];
        $profile = ['aggregation' => 'weighted', 'weights' => ['original' => 1, 'repeated' => 1, 'paraphrased' => 2], 'pass' => 0.8, 'fail' => 0.2];
        $decision = $bench->score($groups, $profile);
        $this->assertSame(0.375, $decision['score']);
        $this->assertSame('unknown', $decision['verdict']);
        $this->assertSame('pass', $bench->score($groups, array_replace($profile, ['pass' => 0.375]))['verdict']);
        $this->assertSame('fail', $bench->score($groups, array_replace($profile, ['fail' => 0.375]))['verdict']);
        $this->assertSame('fail', $bench->score($groups, array_replace($profile, ['aggregation' => 'veto']))['verdict']);
        $groups['paraphrased'] = [['score' => null, 'error' => 'ProviderFailure']];
        $this->assertSame(['verdict' => 'error', 'score' => null], $bench->score($groups, $profile));
        $groups['paraphrased'] = [['score' => null]];
        $this->assertSame(['verdict' => 'unknown', 'score' => null], $bench->score($groups, $profile));
        $profile['weights'] = ['original' => 1];
        $this->assertSame('pass', $bench->score($groups, $profile)['verdict']);
    }

    #[DataProvider('referenceSets')]
    public function test_command_collects_private_reports_without_labels_in_prompts_and_replays_without_inference(array $references): void
    {
        Http::fake(['https://inference.example.test/v1/chat/completions' => function ($request) use ($references) {
            $this->assertStringNotContainsString('ground-truth-marker', json_encode($request->data()));
            $input = json_decode($request['messages'][1]['content'], true);
            $sources = $input['sources'];
            $rewrite = isset($request['response_format']['json_schema']['schema']['properties']['sources']);
            $this->assertSame($rewrite ? [] : $references, $input['references'] ?? []);
            $this->assertStringNotContainsString('REFERENCE_MARKER', $request['messages'][0]['content']);
            $answer = $rewrite ? ['sources' => [['id' => 'report', 'text' => 'Rewritten '.$sources['report']]]] : [
                'score' => $request['model'] === 'other-model' || str_starts_with($sources['report'], 'Rewritten') ? 0.1 : 0.9,
                'reason' => 'Fixture.', 'evidence' => [], 'missing' => [],
            ];

            return Http::response(['model' => $request['model'], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode($answer)]]]]);
        }]);
        $this->writeSuite(references: $references);
        $case = ['id' => 'ground-truth-marker', 'expected' => 'fail', 'instructions' => 'Triage reports.', 'sources' => ['report' => 'Save crashes.'],
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
        Classifier::fake(fn () => ['score' => 1, 'reason' => 'Fixture.', 'evidence' => [], 'missing' => []]);
        Paraphraser::fake([['sources' => [['id' => 'report', 'text' => 'Save fails.']]]]);
        $this->artisan('toll-troll:benchmark', $args + ['--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        $this->writeSuite(references: ['manual' => 'Changed documentation.']);
        Classifier::fake()->preventStrayPrompts();
        Paraphraser::fake()->preventStrayPrompts();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($replay ? 'matching capture' : 'references do not match');
        try {
            $this->artisan('toll-troll:benchmark', $args + ['--output' => $this->directory.'/new.json', '--yes' => true]
                + ($replay ? ['--replay' => $this->directory.'/capture.json'] : []))->run();
        } finally {
            // SDK fakes retain the first collection's recorded prompts across fake() calls.
            Classifier::assertPromptedTimes(8);
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
        Classifier::fake(fn () => ['score' => 1, 'reason' => 'Fixture.', 'evidence' => [], 'missing' => []]);
        Paraphraser::fake([['sources' => [['id' => 'report', 'text' => 'Save crashes.!']]]]);
        $args = ['suite' => $this->directory.'/suite.php', 'dataset' => $this->directory.'/cases.jsonl', '--max-bytes' => 17];
        $this->artisan('toll-troll:benchmark', $args + ['--yes' => true, '--output' => $this->directory.'/capture.json'])->assertSuccessful();
        $report = json_decode(file_get_contents($this->directory.'/capture.json'), true);
        $this->assertSame([], $report['skipped']);
        $this->assertSame('OverflowException', $report['records'][0]['rewrites'][0]['error']);
        $this->assertSame('error', $report['summary']['fixture']['veto']['decisions'][0]['verdict']);
        $this->assertSame('pass', $report['summary']['fixture']['original']['decisions'][0]['verdict']);
    }

    private function writeSuite(float $pass = 0.8, array $references = []): void
    {
        file_put_contents($this->directory.'/suite.php', '<?php return '.var_export([
            'models' => ['fixture' => ['provider' => 'local', 'model' => 'fixture'], 'comparison' => ['provider' => 'local', 'model' => 'other-model']],
            'paraphraser' => ['provider' => 'local', 'model' => 'fixture'],
            'repeats' => 2, 'variants' => 1,
            'profiles' => [
                'original' => ['aggregation' => 'weighted', 'weights' => ['original' => 1], 'pass' => $pass, 'fail' => 0.2],
                'veto' => ['aggregation' => 'veto', 'weights' => ['original' => 1, 'repeated' => 1, 'paraphrased' => 1], 'pass' => $pass, 'fail' => 0.2],
            ],
        ], true).' + ["policy" => new \\Vos\\TollTroll\\Policy("no_prompt_injection", \\Vos\\TollTroll\\Policy::noPromptInjection()->criteria, references: '.var_export($references, true).')];');
    }
}

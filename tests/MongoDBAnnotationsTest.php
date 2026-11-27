<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use MongoDB\Laravel\MongoDBServiceProvider;
use Orchestra\Testbench\TestCase;
use Vos\TollTroll\Agents\AnnotationAdvisor;
use Vos\TollTroll\Annotations\AnalyzeAnnotations;
use Vos\TollTroll\Annotations\Annotation;
use Vos\TollTroll\Annotations\AnnotationAnalysis;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Annotations\AnnotationServiceProvider;
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Annotations\PolicyDeployed;
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\Policy;
use Vos\TollTroll\PolicyResult;
use Vos\TollTroll\TollTrollServiceProvider;
use Vos\TollTroll\Verdict;

class MongoDBAnnotationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MongoDBServiceProvider::class, AiServiceProvider::class, TollTrollServiceProvider::class, AnnotationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'mongodb');
        $app['config']->set('database.connections.mongodb', [
            'driver' => 'mongodb', 'dsn' => getenv('MONGODB_URI'),
            'database' => 'toll_troll_test_'.getmypid(),
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        if (! getenv('MONGODB_URI') || ! class_exists(MongoDBServiceProvider::class)) {
            $this->markTestSkipped('Install mongodb/laravel-mongodb and set MONGODB_URI to a test replica set.');
        }
        parent::setUp();
        $migration = require __DIR__.'/../database/mongodb-migrations/2026_11_27_000000_create_toll_troll_annotation_collections.php';
        $migration->down();
        $migration->up();
        Http::preventStrayRequests();
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $dataset === 'support');
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::connection()->getDatabase()->drop();
        }
        parent::tearDown();
    }

    public function test_capture_review_navigation_export_and_pruning(): void
    {
        $this->travelTo(now()->startOfSecond());
        for ($i = 1; $i <= 18; $i++) {
            $event = new AnnotationRequested('support', 'case-'.$i, new Context('Triage.', ['report' => 'Report '.$i]), Policy::bugReport());
            Event::dispatch($event);
            Event::dispatch($event);
            app(Annotation::class)->where('sample_key', 'case-'.$i)->update(['created_at' => now()->subDays(31)->addSeconds($i)]);
        }
        Event::dispatch(new AnnotationRequested('private', 'private-case', new Context('Secret.', ['report' => 'Private.']), Policy::bugReport()));
        $this->assertSame(18, app(Annotation::class)->where('dataset', 'support')->count());
        $case = app(Annotation::class)->where('sample_key', 'case-1')->sole();
        $this->assertSame(0, $case->version);
        $this->assertSame('Report 1', $case->context['sources']['report']);
        $this->assertStringNotContainsString('Report 1', DB::table('toll_troll_annotations')->where('id', $case->id)->value('context'));
        $base = '/toll-troll/annotations/support';
        $this->get('/toll-troll/annotations')->assertOk()->assertSee('support')->assertDontSee('private-case');
        $page = $this->get($base)->assertOk()->assertSee('case-1')->assertDontSee('case-16');
        $this->assertSame('case-1', $page['selected']->sample_key);
        $this->assertSame('case-16', $this->get($base.'?page=2')->assertOk()['selected']->sample_key);
        $url = $base.'/'.$case->id;
        $this->post($url, ['label' => 'fail', 'note' => 'Private note.', 'version' => 0])->assertRedirect($url);
        $this->post($url, ['label' => 'pass', 'version' => 0])->assertStatus(409);
        $this->assertSame('fail', $case->fresh()->label);
        $this->assertSame(1, DB::table('toll_troll_annotation_reviews')->count());
        $this->assertStringNotContainsString('Private note.', DB::table('toll_troll_annotation_reviews')->value('note'));
        $moved = $this->get($url)->assertOk();
        $this->assertSame(2, $moved['cases']->currentPage());
        $this->assertSame(['case-17', 'case-18', 'case-1'], $moved['cases']->getCollection()->pluck('sample_key')->all());
        $this->get('/toll-troll/annotations/private/'.$case->id)->assertForbidden();
        $export = json_decode(trim($this->get($base.'/export/'.$case->policy_hash)->assertOk()->streamedContent()), true);
        $this->assertSame('fail', $export['expected']);
        $this->assertSame('Private note.', $export['note']);
        $this->assertSame('Report 1', $export['sources']['report']);

        $last = app(Annotation::class)->where('sample_key', 'case-18')->sole();
        $this->post($base.'/'.$last->id, ['label' => 'pass', 'version' => 0])->assertRedirect();
        Event::dispatch(new AnnotationRequested('support', 'recent', new Context('Triage.', ['report' => 'New report.']), Policy::bugReport()));
        $recent = app(Annotation::class)->where('sample_key', 'recent')->sole();
        $this->post($base.'/'.$recent->id, ['label' => 'unknown', 'version' => 0])->assertRedirect();

        $model = app(Annotation::class)::class;
        $this->artisan('model:prune', ['--model' => [$model]])->assertSuccessful();
        $this->assertSame(20, app(Annotation::class)->count());
        config()->set('toll-troll-annotations.retention_days', 30);
        $this->artisan('model:prune', ['--model' => [$model], '--chunk' => 7])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['private-case', 'recent'], app(Annotation::class)->pluck('sample_key')->all());
        $this->assertSame([$recent->id], DB::table('toll_troll_annotation_reviews')->pluck('annotation_id')->all());
        Http::assertNothingSent();
    }

    public function test_evaluation_capture_with_analysis_is_atomic(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        $evaluation = new Evaluation('run-1', new Context('Triage.', ['report' => 'Save fails.']), [], [], [
            'bug' => [new PolicyResult(Policy::bugReport(), Verdict::Fail, [])],
            'feature' => [new PolicyResult(Policy::featureRequest(), Verdict::Pass, [])],
        ], []);
        $event = new EvaluationReviewRequested('support', 'ticket-1', $evaluation);
        Event::dispatch($event);
        $this->assertSame(2, app(Annotation::class)->count());
        $this->assertSame(2, DB::table('toll_troll_annotation_policy_revisions')->count());
        $this->assertSame('run-1', app(Annotation::class)->first()->prediction['evaluation_id']);
        app(Annotation::class)->newQuery()->delete();
        DB::table('toll_troll_annotation_policy_revisions')->delete();

        $model = app(Annotation::class)::class;
        $count = 0;
        $model::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('write failure');
            }
        });
        try {
            Event::dispatch($event);
            $this->fail('Expected capture failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('write failure', $error->getMessage());
            $this->assertSame(0, app(Annotation::class)->count());
            $this->assertSame(0, DB::table('toll_troll_annotation_policy_revisions')->count());
        } finally {
            $model::flushEventListeners();
        }
    }

    public function test_advice_is_encrypted_cached_dismissible_and_retired_on_deployment(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        config()->set('toll-troll-annotations.analysis.min_reviewed', 3);
        config()->set('toll-troll-annotations.analysis.min_disagreements', 3);
        for ($i = 0; $i < 3; $i++) {
            Event::dispatch(new AnnotationRequested('support', 'case-'.$i, new Context('Triage.', ['report' => 'Report '.$i]), Policy::bugReport()));
            app(Annotation::class)->where('sample_key', 'case-'.$i)->firstOrFail()->update([
                'prediction' => ['verdict' => 'fail'], 'label' => 'pass', 'version' => 1, 'reviewed_at' => now(),
            ]);
        }
        $suggestion = ['category' => 'policy_wording', 'finding' => 'Accept prose reports.', 'change' => 'Clarify required facts.',
            'validation' => 'Benchmark held-out reports.', 'annotation_ids' => app(Annotation::class)->pluck('id')->all()];
        AnnotationAdvisor::fake(fn () => ['suggestions' => [$suggestion]])->preventStrayPrompts();
        app(AnalyzeAnnotations::class)->analyze('support');
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        AnnotationAdvisor::assertPromptedTimes(1);
        $analysis = app(AnnotationAnalysis::class)->sole();
        $this->assertSame([$suggestion], $analysis->suggestions);
        $this->assertStringNotContainsString($suggestion['finding'], DB::table('toll_troll_annotation_analyses')->value('suggestions'));
        $this->get('/toll-troll/annotations')->assertOk()->assertSee($suggestion['finding']);
        $dismiss = route('toll-troll-annotations.advice.dismiss', ['support', $analysis->id, AnnotationAnalysis::suggestionKey($suggestion)]);
        foreach ([1, 2] as $attempt) {
            $this->post($dismiss)->assertRedirect();
        }
        $this->assertSame(1, DB::table('toll_troll_annotation_advice_dismissals')->count());
        $this->get('/toll-troll/annotations')->assertOk()->assertDontSee($suggestion['finding']);
        $this->actingAs(new GenericUser(['id' => 8]));
        $this->get('/toll-troll/annotations')->assertOk()->assertSee($suggestion['finding']);
        Event::dispatch(new PolicyDeployed('support', Policy::bugReport()->withThresholds(0.9, 0.3)));
        $this->get('/toll-troll/annotations')->assertOk()->assertDontSee($suggestion['finding']);
        $analysis->delete();
        $this->assertSame(0, DB::table('toll_troll_annotation_advice_dismissals')->count());
        Http::assertNothingSent();
    }
}

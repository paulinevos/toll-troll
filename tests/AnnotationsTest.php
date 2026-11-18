<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Vos\TollTroll\Agents\AnnotationAdvisor;
use Vos\TollTroll\Agents\Classifier;
use Vos\TollTroll\Agents\Paraphraser;
use Vos\TollTroll\Annotations\Annotation;
use Vos\TollTroll\Annotations\AnnotationAnalysis;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Annotations\AnnotationServiceProvider;
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Annotations\PolicyDeployed;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\EvaluationConfig;
use Vos\TollTroll\Events\ReviewRequired;
use Vos\TollTroll\Policy;
use Vos\TollTroll\Tests\Fixtures\DomainPolicy;
use Vos\TollTroll\TollTroll;
use Vos\TollTroll\TollTrollServiceProvider;

class AnnotationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, TollTrollServiceProvider::class, AnnotationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Http::preventStrayRequests();
    }

    public function test_identical_entrypoint_rules_have_separate_bridge_labels_exports_and_authorized_switching(): void
    {
        $policy = Policy::bugReport();
        $context = new Context('Triage.', ['report' => 'Save fails.']);
        foreach (['support-webhook', 'feedback-form', 'private-entrypoint'] as $name) {
            $bridge = new Bridge($name, $context->instructions, ['bug' => [$policy]]);
            $event = new AnnotationRequested($bridge, 'ticket-42', $context, $policy);
            Event::dispatch($event);
            Event::dispatch($event);
        }
        $this->assertSame(3, Annotation::count());
        $support = Annotation::where('dataset', 'support-webhook')->sole();
        $feedback = Annotation::where('dataset', 'feedback-form')->sole();
        $this->assertSame($support->policy_hash, $feedback->policy_hash);
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn ($user, $name) => $name !== 'private-entrypoint');
        $base = '/toll-troll/annotations';
        $this->get($base)->assertOk()->assertSee('Bridges')->assertSee('support-webhook')->assertSee('feedback-form')->assertDontSee('private-entrypoint');
        $page = $this->get($base.'/support-webhook')->assertOk()->assertSee('Switch bridge')->assertDontSee('private-entrypoint');
        $this->assertStringContainsString('href="'.route('toll-troll-annotations.show', 'feedback-form').'"', $page->getContent());
        $this->post($base.'/support-webhook/'.$support->id, ['label' => 'pass', 'version' => 0])->assertRedirect();
        $this->assertNull($feedback->fresh()->label);
        $this->get($base.'/feedback-form/export/'.$feedback->policy_hash)->assertNotFound();
        $this->post($base.'/feedback-form/'.$feedback->id, ['label' => 'fail', 'version' => 0])->assertRedirect();
        foreach ([$support, $feedback] as $case) {
            $export = $this->get($base.'/'.$case->dataset.'/export/'.$case->policy_hash)->assertOk()->streamedContent();
            $rows = explode("\n", trim($export));
            $this->assertCount(1, $rows);
            $this->assertSame($case->dataset, json_decode($rows[0], true)['bridge']);
            $this->assertSame($case->fresh()->label, json_decode($rows[0], true)['expected']);
        }
        $this->get($base.'/feedback-form/'.$support->id)->assertNotFound();
        $this->post($base.'/feedback-form/'.$support->id, ['label' => 'fail', 'version' => 1])->assertNotFound();
        $this->get($base.'/private-entrypoint')->assertForbidden();
        $this->assertSame('pass', $support->fresh()->label);
    }

    public function test_bridge_evaluations_cannot_be_captured_under_another_entrypoint(): void
    {
        $bridge = new Bridge('support-webhook', 'Triage.', ['bug' => [Policy::bugReport()]],
            config: new EvaluationConfig(variants: 0, injectionGuard: false));
        Classifier::fake([['score' => 1, 'reason' => 'Bug.', 'evidence' => [], 'missing' => []]])->preventStrayPrompts();
        $evaluation = app(TollTroll::class)->cross($bridge, ['report' => 'Save fails.']);
        $event = new EvaluationReviewRequested($bridge, 'ticket-42', $evaluation);
        Event::dispatch($event);
        Event::dispatch($event);
        $case = Annotation::sole();
        $this->assertSame('support-webhook', $case->dataset);
        $this->assertSame($evaluation->id, $case->prediction['evaluation_id']);
        $this->expectException(\InvalidArgumentException::class);
        new EvaluationReviewRequested('feedback-form', 'ticket-42', $evaluation);
    }

    #[DataProvider('referenceSets')]
    public function test_capture_is_idempotent_and_reviews_are_encrypted_versioned_and_exportable(array $references): void
    {
        $policy = new Policy('bug_report', Policy::bugReport()->criteria, references: $references);
        $event = new AnnotationRequested('support', 'ticket-42', new Context('Triage reports.', ['report' => '<script>alert("injection")</script>']), $policy);
        if ($references === []) {
            // An existing snapshot from before the references property was introduced.
            $legacy = ['id' => $policy->id, 'criteria' => $policy->criteria, 'passThreshold' => 0.8, 'failThreshold' => 0.2];
            $hash = hash('sha256', json_encode($legacy));
            Annotation::create(['dataset' => 'support', 'sample_key' => 'ticket-42', 'context' => (array) $event->context,
                'policy' => $legacy, 'policy_hash' => $hash,
                'fingerprint' => hash('sha256', json_encode(['support', 'ticket-42', $event->context->fingerprint(), $hash]))]);
        }
        Event::dispatch($event);
        Event::dispatch($event);
        $this->assertSame(1, Annotation::count());
        $case = Annotation::firstOrFail();
        $this->assertStringNotContainsString('<script>', DB::table('toll_troll_annotations')->value('context'));
        $this->assertSame($event->context->sources, $case->context['sources']);
        $this->assertSame($references, $case->policy['references'] ?? []);
        $this->assertStringNotContainsString('REFERENCE_MARKER', DB::table('toll_troll_annotations')->value('policy'));
        $this->assertNull($case->label);
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $user->id === 7 && $dataset === 'support');
        $url = '/toll-troll/annotations/support/'.$case->id;
        $page = $this->get($url)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false)->assertHeader('Cache-Control', 'no-store, private');
        foreach ($references as $name => $text) {
            $page->assertSee($name)->assertSee($text)->assertDontSee($name, false)->assertDontSee($text, false);
        }
        preg_match_all('/<script>(.*?)<\/script>/s', $page->getContent(), $scripts);
        $this->assertCount(1, $scripts[1]);
        $csp = $page->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'sha256-".base64_encode(hash('sha256', $scripts[1][0], true))."'", $csp);
        $this->assertStringNotContainsString("script-src 'unsafe-inline'", $csp);
        $this->post($url, ['label' => 'unknown', 'note' => 'Need more context.', 'version' => 0])->assertRedirect($url);
        $this->assertSame('unknown', $case->fresh()->label);
        $this->assertSame('Need more context.', $case->fresh()->note);
        $this->assertStringNotContainsString('Need more context.', DB::table('toll_troll_annotation_reviews')->value('note'));
        // A stale reviewer cannot overwrite the first label or append a phantom revision.
        $this->post($url, ['label' => 'pass', 'version' => 0])->assertStatus(409);
        $this->assertSame(1, DB::table('toll_troll_annotation_reviews')->count());
        Event::dispatch($event);
        $this->assertSame('unknown', $case->fresh()->label);
        $this->post($url, ['label' => 'fail', 'version' => 1])->assertRedirect();
        $this->assertSame(2, $case->fresh()->version);
        $this->assertSame(['unknown', 'fail'], DB::table('toll_troll_annotation_reviews')->orderBy('version')->pluck('label')->all());
        Event::dispatch(new AnnotationRequested('support', 'unreviewed', $event->context, $event->policy));
        Event::dispatch(new AnnotationRequested('support', 'different-policy', $event->context, Policy::featureRequest()));
        $response = $this->get('/toll-troll/annotations/support/export/'.$case->policy_hash)->assertOk();
        $lines = explode("\n", trim($response->streamedContent()));
        $this->assertCount(1, $lines);
        $export = json_decode($lines[0], true);
        $this->assertSame('fail', $export['expected']);
        $this->assertSame($event->context->sources, $export['sources']);
        $this->assertSame($event->policy->criteria, $export['policy']['criteria']);
        $this->assertSame($references, $export['policy']['references'] ?? []);
        // Changing only the reference content creates a pending revision, not a relabeled old case.
        Event::dispatch(new AnnotationRequested('support', 'ticket-42', $event->context,
            new Policy($policy->id, $policy->criteria, references: ['manual' => 'Updated documentation.'])));
        $revision = Annotation::where('sample_key', 'ticket-42')->where('id', '!=', $case->id)->sole();
        $this->assertNull($revision->label);
        $this->assertNotSame($case->policy_hash, $revision->policy_hash);
        $this->assertSame('fail', $case->fresh()->label);
        Http::assertNothingSent();
    }

    public static function referenceSets(): array
    {
        return ['legacy' => [[]], 'references' => [['<b>manual</b>' => 'REFERENCE_MARKER: <script>alert("reference")</script>']]];
    }

    public function test_custom_policy_annotations_freeze_only_contract_data(): void
    {
        $policy = new DomainPolicy;
        $event = new AnnotationRequested('support', 'ticket-1', new Context('Triage.', ['report' => 'Save crashes.']), $policy);
        $policy->documents = ['manual' => 'Changed after event construction.'];
        Event::dispatch($event);
        $case = Annotation::sole();
        $this->assertSame(['id' => 'documented_behavior', 'criteria' => $policy->rubric, 'passThreshold' => 0.7,
            'failThreshold' => 0.1, 'references' => ['manual' => 'Drafts must save.']], $case->policy);
        Event::dispatch(new AnnotationRequested('support', 'ticket-1', $event->context, $event->policy));
        $this->assertSame(1, Annotation::count());
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => true);
        $this->post('/toll-troll/annotations/support/'.$case->id, ['version' => 0, 'label' => 'pass'])->assertRedirect();
        $export = $this->get('/toll-troll/annotations/support/export/'.$case->policy_hash)->assertOk()->streamedContent();
        $this->assertSame($case->policy, json_decode($export, true)['policy']);
        $this->assertStringNotContainsString($policy->internal, $export);
    }

    public function test_troll_patrol_captures_each_prediction_and_human_corrections_without_actions(): void
    {
        Queue::fake();
        Event::fake([ReviewRequired::class]);
        config()->set('toll-troll.variants', 1);
        Paraphraser::fake([['sources' => [['id' => 'report', 'text' => 'Rewritten: Save fails.']]]])->preventStrayPrompts();
        Classifier::fake(array_map(fn ($score) => ['score' => $score, 'reason' => '<script>prediction-marker</script>',
            'evidence' => [['source' => 'report', 'quote' => 'Save fails.']], 'missing' => ['Environment']],
            [1, 1, 0, 0, 1, 1, null, null, 2, 2]))->preventStrayPrompts();
        $patrol = require __DIR__.'/../examples/troll-patrol.php';
        $evaluation = $patrol('patrol', 'ticket-1', new Context('Triage.', ['report' => 'Save fails.']), [
            'bug' => [Policy::bugReport(), Policy::expectedAndActualBehavior()],
            'other' => [Policy::bugReport()], // Same policy, different branch/prediction.
            'maybe' => [Policy::featureRequest()],
            'broken' => [Policy::reproductionSteps()],
        ]);
        $cases = Annotation::all();
        $this->assertCount(6, $cases);
        $this->assertEqualsCanonicalizing(['pass', 'fail', 'skipped', 'pass', 'unknown', 'error'], $cases->map(fn ($case) => $case->prediction['verdict'])->all());
        $this->assertSame(6, $cases->whereNull('label')->count());
        foreach (DB::table('toll_troll_annotations')->pluck('prediction') as $encrypted) {
            $this->assertStringNotContainsString('prediction-marker', $encrypted);
            $this->assertStringNotContainsString($evaluation->id, $encrypted);
        }

        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $dataset === 'patrol');
        foreach ($cases as $case) {
            $prediction = $case->prediction;
            $url = '/toll-troll/annotations/patrol/'.$case->id;
            $page = $this->get($url)->assertOk()->assertSee('Recorded prediction')->assertSee($evaluation->id);
            $this->assertSame(0, preg_match('/type="radio"[^>]*\schecked/', $page->getContent()));
            $this->assertSame($evaluation->variants['variant_1']['sources'], $prediction['variants']['variant_1']['sources']);
            if (! in_array($prediction['verdict'], ['error', 'skipped'])) {
                $page->assertSee('&lt;script&gt;prediction-marker&lt;/script&gt;', false)->assertDontSee('<script>prediction-marker</script>', false);
                $this->assertCount(2, $prediction['assessments']);
                $this->assertSame(['original', 'variant_1'], array_column($prediction['assessments'], 'sample'));
                $this->post($url, ['label' => $prediction['verdict'], 'version' => 0])->assertRedirect();
                $this->get($url)->assertSee('Agrees with prediction');
                $correction = $prediction['verdict'] === 'pass' ? 'fail' : 'pass';
                $this->post($url, ['label' => $correction, 'version' => 1])->assertRedirect();
                $this->get($url)->assertSee('Disagrees with prediction');
            } else {
                $page->assertSee($prediction['verdict'] === 'error' ? 'Prediction failed.' : 'Not evaluated:');
                $this->post($url, ['label' => 'unknown', 'version' => 0])->assertRedirect();
                $this->get($url)->assertDontSee('Agrees with prediction')->assertDontSee('Disagrees with prediction');
            }
            $this->assertSame($prediction, $case->fresh()->prediction);
        }

        Event::dispatch(new EvaluationReviewRequested('patrol', 'ticket-1', $evaluation));
        $this->assertSame(6, Annotation::count());
        $this->assertSame(0, Annotation::whereNull('label')->count());
        $bug = $cases->first(fn ($case) => $case->prediction['branch'] === 'bug' && $case->prediction['verdict'] === 'fail');
        $response = $this->get('/toll-troll/annotations/patrol/export/'.$bug->policy_hash)->assertOk();
        $exported = array_map(fn ($line) => json_decode($line, true), explode("\n", trim($response->streamedContent())));
        $this->assertCount(2, $exported);
        $byBranch = array_column($exported, null, 'id');
        $this->assertSame('pass', $byBranch[$bug->id]['expected']);
        $this->assertSame('fail', $byBranch[$bug->id]['prediction']['verdict']);
        $this->assertSame($evaluation->id, $byBranch[$bug->id]['prediction']['evaluation_id']);

        Event::dispatch(new EvaluationReviewRequested('patrol', 'ticket-1', new Evaluation('another-run', $evaluation->context,
            $evaluation->variants, $evaluation->guards, $evaluation->branches, $evaluation->errors)));
        $this->assertSame(12, Annotation::count());
        $this->assertSame(6, Annotation::whereNull('label')->count());
        $this->get('/toll-troll/annotations/private/'.$bug->id)->assertForbidden();
        $this->get('/toll-troll/annotations/private/export/'.$bug->policy_hash)->assertForbidden();
        Event::assertNotDispatched(ReviewRequired::class);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_evaluation_capture_keeps_paraphrase_errors_and_is_atomic(): void
    {
        config()->set('toll-troll.variants', 1);
        Paraphraser::fake(fn () => throw new \RuntimeException('secret provider detail'))->preventStrayPrompts();
        Classifier::fake()->preventStrayPrompts();
        $evaluation = app(TollTroll::class)->evaluate(new Context('Triage.', ['report' => 'Save fails.']), ['bug' => [Policy::bugReport()]]);
        $event = new EvaluationReviewRequested('patrol', 'ticket-2', $evaluation);
        Event::dispatch($event);
        $this->assertSame(2, Annotation::count());
        foreach (Annotation::all() as $case) {
            $this->assertSame('skipped', $case->prediction['verdict']);
            $this->assertSame([], $case->prediction['assessments']);
            $this->assertSame([['stage' => 'variant_1', 'error' => \RuntimeException::class]], $case->prediction['errors']);
        }
        Classifier::assertNeverPrompted();
        Annotation::query()->delete();
        $count = 0;
        Annotation::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('write failure');
            }
        });
        try {
            Event::dispatch($event);
            $this->fail('Expected capture failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('write failure', $error->getMessage());
            $this->assertSame(0, Annotation::count());
        } finally {
            Annotation::flushEventListeners();
        }
    }

    public function test_case_navigation_crosses_pages_and_tracks_pending_first_order_after_saving(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $dataset === 'support');
        $ids = [];
        for ($i = 1; $i <= 18; $i++) {
            Event::dispatch(new AnnotationRequested('support', 'case-'.$i, new Context('Triage.', ['report' => 'Example report.']), Policy::bugReport()));
            $case = Annotation::where('sample_key', 'case-'.$i)->firstOrFail();
            $ids[$i] = sprintf('00000000-0000-4000-8000-%012d', $i);
            // Equal timestamps exercise the ID tiebreaker. The oldest case is already reviewed.
            $case->update(['id' => $ids[$i], 'created_at' => now()->startOfDay()->addSeconds($i === 18 ? -1 : intdiv($i, 2)), 'label' => $i === 18 ? 'pass' : null]);
        }
        Event::dispatch(new AnnotationRequested('private', 'not-in-this-queue', new Context('Triage.', ['report' => 'Private.']), Policy::bugReport()));
        $base = '/toll-troll/annotations/support';
        $visit = function (string $url, string $expectedKey) {
            $page = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML($page->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            $this->assertSame($expectedKey, $xpath->evaluate('string(//h2[@class="case-title"])'));
            $this->assertSame($expectedKey, $xpath->evaluate('string(//a[@aria-current="page"]/strong)'));

            return [
                'prev' => $xpath->evaluate('string(//nav[@class="case-navigation"]/a[@rel="prev"]/@href)'),
                'next' => $xpath->evaluate('string(//nav[@class="case-navigation"]/a[@rel="next"]/@href)'),
            ];
        };
        $first = $visit($base, 'case-1');
        $this->assertSame('', $first['prev']);
        $visit($first['next'], 'case-2');
        $boundary = $visit($base.'/'.$ids[15], 'case-15');
        $secondPage = $visit($boundary['next'], 'case-16');
        $visit($secondPage['prev'], 'case-15');
        $last = $visit($base.'/'.$ids[18].'?page=1', 'case-18');
        $this->assertSame('', $last['next']);
        $visit($last['prev'], 'case-17');

        $this->post($base.'/'.$ids[1], ['label' => 'fail', 'version' => 0])->assertRedirect($base.'/'.$ids[1]);
        $moved = $visit($base.'/'.$ids[1], 'case-1');
        $this->assertSame('', $moved['next']);
        $visit($moved['prev'], 'case-18');
        $visit($base, 'case-2');
    }

    public function test_every_route_requires_authenticated_dataset_authorization(): void
    {
        Event::dispatch(new AnnotationRequested('private-team', 'secret-ticket', new Context('Private instructions.', ['report' => 'private source']), Policy::bugReport()));
        $case = Annotation::firstOrFail();
        $base = '/toll-troll/annotations';
        $this->get($base)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 7]));
        // No permissive gate is registered by the package.
        $this->get($base)->assertOk()->assertDontSee('private-team');
        $this->get($base.'/private-team/'.$case->id)->assertForbidden();
        $this->post($base.'/private-team/'.$case->id, ['label' => 'pass', 'version' => 0])->assertForbidden();
        $this->get($base.'/private-team/export/'.$case->policy_hash)->assertForbidden();
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $dataset === 'public-team');
        $this->get($base.'/public-team/'.$case->id)->assertNotFound();
        $this->post($base.'/public-team/'.$case->id, ['label' => 'pass', 'version' => 0])->assertNotFound();
        $this->assertNull($case->fresh()->label);
    }

    public function test_retention_is_opt_in_and_prunes_by_capture_age_with_review_history(): void
    {
        $this->travelTo(now()->startOfSecond());
        $cutoff = now()->subDays(30);
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => true);
        $ids = [];
        foreach (['expired-reviewed' => -1, 'boundary-pending' => 0, 'retained-reviewed' => 1, 'retained-pending' => 60] as $key => $seconds) {
            Event::dispatch(new AnnotationRequested('support', $key, new Context('Triage.', ['report' => 'Save fails.']), Policy::bugReport()));
            $case = Annotation::where('sample_key', $key)->sole();
            $case->update(['created_at' => $cutoff->copy()->addSeconds($seconds)]);
            $ids[$key] = $case->id;
            if (str_ends_with($key, '-reviewed')) {
                $this->post('/toll-troll/annotations/support/'.$case->id, ['label' => 'pass', 'version' => 0])->assertRedirect();
            }
        }

        $this->assertNull(config('toll-troll-annotations.retention_days'));
        $args = ['--model' => [Annotation::class], '--chunk' => 1];
        $this->artisan('model:prune', $args)->assertSuccessful();
        $this->assertSame(4, Annotation::count());
        $this->assertSame(2, DB::table('toll_troll_annotation_reviews')->count());

        config()->set('toll-troll-annotations.retention_days', 30);
        $this->artisan('model:prune', $args + ['--pretend' => true])->expectsOutputToContain('2 [')->assertSuccessful();
        $this->assertSame(4, Annotation::count());
        $this->assertSame(2, DB::table('toll_troll_annotation_reviews')->count());
        $this->artisan('model:prune', $args)->assertSuccessful();
        $this->assertEqualsCanonicalizing(['retained-reviewed', 'retained-pending'], Annotation::pluck('sample_key')->all());
        $this->assertSame([$ids['retained-reviewed']], DB::table('toll_troll_annotation_reviews')->pluck('annotation_id')->all());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidRetentionPeriods')]
    public function test_invalid_retention_configuration_does_not_delete_annotations(mixed $days): void
    {
        Event::dispatch(new AnnotationRequested('support', 'keep', new Context('Triage.', ['report' => 'Save fails.']), Policy::bugReport()));
        config()->set('toll-troll-annotations.retention_days', $days);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retention_days');
        try {
            $this->artisan('model:prune', ['--model' => [Annotation::class]])->run();
        } finally {
            $this->assertSame(1, Annotation::count());
        }
    }

    public static function invalidRetentionPeriods(): array
    {
        return ['negative' => [-1], 'zero' => [0], 'text' => ['30 days'], 'boolean' => [false]];
    }

    public function test_account_cleanup_can_delete_all_key_snapshots_without_touching_other_cases(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => true);
        foreach ([
            ['support', 'account-a:turn-1', Policy::bugReport()],
            ['support', 'account-a:turn-1', Policy::featureRequest()],
            ['support', 'account-b:turn-1', Policy::bugReport()],
            ['other', 'account-a:turn-1', Policy::bugReport()],
        ] as [$dataset, $key, $policy]) {
            Event::dispatch(new AnnotationRequested($dataset, $key, new Context('Triage.', ['report' => 'Save fails.']), $policy));
        }
        foreach (Annotation::all() as $case) {
            $this->post('/toll-troll/annotations/'.$case->dataset.'/'.$case->id, ['label' => 'pass', 'version' => 0])->assertRedirect();
        }

        $deleted = Annotation::where('dataset', 'support')->whereIn('sample_key', ['account-a:turn-1'])->delete();
        $this->assertSame(2, $deleted);
        $this->assertSame(1, Annotation::where('dataset', 'support')->where('sample_key', 'account-b:turn-1')->count());
        $this->assertSame(1, Annotation::where('dataset', 'other')->where('sample_key', 'account-a:turn-1')->count());
        $this->assertEqualsCanonicalizing(Annotation::pluck('id')->all(), DB::table('toll_troll_annotation_reviews')->pluck('annotation_id')->all());
    }

    public function test_analysis_is_opt_in_cached_encrypted_authorized_and_invalidated_by_reviews(): void
    {
        $cases = $this->analysisCases(30, 5);
        $ids = $cases->take(3)->pluck('id')->all();
        $suggestion = ['category' => 'policy_wording', 'finding' => '<script>advice-marker</script>',
            'change' => 'Explicitly accept reports with embedded reproduction steps.',
            'validation' => 'Compare false failures on a separate held-out set.', 'annotation_ids' => $ids];
        AnnotationAdvisor::fake(fn () => ['suggestions' => [$suggestion]])->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        AnnotationAdvisor::assertNeverPrompted();
        $this->assertSame(0, AnnotationAnalysis::count());

        config()->set('toll-troll-annotations.analysis.enabled', true);
        config()->set('toll-troll.classifiers', [['provider' => 'anthropic', 'model' => 'classifier-model']]);
        $this->artisan('toll-troll:analyze-annotations', ['dataset' => 'support'])->assertSuccessful();
        $analysis = AnnotationAnalysis::sole();
        $this->assertSame(30, $analysis->reviewed_count);
        $this->assertSame(5, $analysis->disagreement_count);
        $this->assertSame([$suggestion], $analysis->suggestions);
        $this->assertStringNotContainsString('advice-marker', DB::table('toll_troll_annotation_analyses')->value('suggestions'));
        AnnotationAdvisor::assertPrompted(function ($prompt) use ($cases) {
            $data = json_decode($prompt->prompt, true);

            return $prompt->provider->name() === 'anthropic' && $prompt->model === 'classifier-model'
                && count($data['cases']) === 30 && $data['policy'] === $cases->first()->policy;
        });
        $base = '/toll-troll/annotations';
        $this->get($base)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => false);
        $this->get($base)->assertOk()->assertDontSee('advice-marker');
        $this->get($base.'/support')->assertForbidden();
        Gate::define('review-toll-troll-annotations', fn ($user, $dataset) => $dataset === 'support');
        foreach ([$base, $base.'/support'] as $url) {
            $this->get($url)->assertOk()->assertSee('The Toll Troll has analyzed your annotations and offers this advice:')
                ->assertSee('&lt;script&gt;advice-marker&lt;/script&gt;', false)->assertDontSee('<script>advice-marker', false)
                ->assertSee(route('toll-troll-annotations.show', ['support', $ids[0]]), false);
        }
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        AnnotationAdvisor::assertPromptedTimes(1);
        $this->post($base.'/support/'.$ids[0], ['label' => 'pass', 'note' => 'Reconsidered.', 'version' => 1])->assertRedirect();
        $this->get($base.'/support')->assertDontSee('advice-marker');
        // Provider overrides are independent of the classifier ensemble.
        config()->set('toll-troll-annotations.analysis.provider', 'openai');
        config()->set('toll-troll-annotations.analysis.model', 'advisor-model');
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        AnnotationAdvisor::assertPrompted(fn ($prompt) => $prompt->provider->name() === 'openai' && $prompt->model === 'advisor-model');
        $this->get($base.'/support')->assertSee('advice-marker');
        $cases->last()->delete();
        $this->get($base.'/support')->assertDontSee('advice-marker');
        Http::assertNothingSent();
    }

    #[DataProvider('analysisEligibility')]
    public function test_analysis_requires_enough_distinct_comparable_cases_and_repeated_disagreements(int $count, int $disagreements, string $variation, bool $eligible): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        $cases = $this->analysisCases($count, $disagreements);
        if ($variation === 'duplicates') {
            foreach ($cases->skip(1) as $case) {
                $case->update(['context' => $cases->first()->context]);
            }
        } elseif ($variation === 'blind') {
            $cases->last()->update(['prediction' => null]);
        } elseif ($variation === 'error') {
            $cases->last()->update(['prediction' => ['verdict' => 'error']]);
        } elseif ($variation === 'pending') {
            $cases->last()->update(['label' => null]);
        } elseif ($variation === 'revision') {
            $cases->last()->update(['policy_hash' => str_repeat('a', 64)]);
        } elseif ($variation === 'dataset') {
            $cases->last()->update(['dataset' => 'other']);
        }
        AnnotationAdvisor::fake([['suggestions' => []]])->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations', ['dataset' => 'support'])->assertSuccessful();
        AnnotationAdvisor::assertPromptedTimes($eligible ? 1 : 0);
        $this->assertSame($eligible ? 1 : 0, AnnotationAnalysis::count());
        if ($eligible) {
            // A considered "no advice" result is cached too.
            $this->artisan('toll-troll:analyze-annotations', ['dataset' => 'support'])->assertSuccessful();
            AnnotationAdvisor::assertPromptedTimes(1);
            $this->actingAs(new GenericUser(['id' => 7]));
            Gate::define('review-toll-troll-annotations', fn () => true);
            $this->get('/toll-troll/annotations/support')->assertOk()->assertDontSee('offers this advice:');
        }
    }

    public static function analysisEligibility(): array
    {
        return [
            'minimum' => [30, 5, '', true], 'too few' => [29, 5, '', false],
            'too few disagreements' => [30, 4, '', false], 'rate below cutoff' => [60, 5, '', false],
            'rate at cutoff' => [50, 5, '', true], 'repeated contexts' => [30, 30, 'duplicates', false],
            'blind case' => [30, 5, 'blind', false], 'provider error' => [30, 5, 'error', false],
            'pending' => [30, 5, 'pending', false], 'different revision' => [30, 5, 'revision', false],
            'different dataset' => [30, 5, 'dataset', false],
        ];
    }

    public function test_analysis_rejects_invented_duplicate_and_agreeing_evidence_and_handles_provider_failure(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        $cases = $this->analysisCases(30, 5);
        $suggestion = ['category' => 'thresholds', 'finding' => 'Insufficiently supported.', 'change' => 'Change cutoff.',
            'validation' => 'Benchmark.', 'annotation_ids' => []];
        $ids = $cases->pluck('id')->all();
        AnnotationAdvisor::fake([['suggestions' => [
            array_replace($suggestion, ['annotation_ids' => [$ids[0], $ids[1], 'invented']]),
            array_replace($suggestion, ['annotation_ids' => [$ids[0], $ids[0], $ids[0]]]),
            array_replace($suggestion, ['annotation_ids' => [$ids[0], $ids[1], $ids[29]]]),
        ]]])->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $this->assertSame([], AnnotationAnalysis::sole()->suggestions);
        AnnotationAnalysis::query()->delete();
        AnnotationAdvisor::fake(fn () => throw new \RuntimeException('private annotation content'))->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->expectsOutputToContain('RuntimeException')->doesntExpectOutputToContain('private annotation content')->assertFailed();
        $this->assertSame(0, AnnotationAnalysis::count());
    }

    public function test_analysis_limits_the_recent_window_and_hides_results_when_inputs_change_during_inference(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        config()->set('toll-troll-annotations.analysis.max_cases', 30);
        $cases = $this->analysisCases(35, 35);
        foreach ($cases as $index => $case) {
            $case->update(['reviewed_at' => now()->startOfDay()->addSeconds($index)]);
        }
        $expectedIds = $cases->skip(5)->pluck('id')->sort()->values()->all();
        AnnotationAdvisor::fake(function ($prompt) use ($expectedIds, $cases) {
            $input = json_decode($prompt, true);
            $this->assertSame($expectedIds, collect($input['cases'])->pluck('id')->sort()->values()->all());
            $cases->last()->increment('version');

            return ['suggestions' => [['category' => 'domain_context', 'finding' => 'Missing reference material.',
                'change' => 'Supply the documented draft lifecycle.', 'validation' => 'Benchmark held-out reports.',
                'annotation_ids' => $cases->slice(5, 3)->pluck('id')->all()]]];
        })->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $this->assertSame(30, AnnotationAnalysis::sole()->reviewed_count);
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => true);
        $this->get('/toll-troll/annotations')->assertOk()->assertDontSee('offers this advice:');
    }

    public function test_advice_dismissal_is_per_suggestion_and_reviewer_and_survives_reanalysis(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        $cases = $this->analysisCases(30, 5);
        $first = ['category' => 'policy_wording', 'finding' => 'Accept sufficient prose reports.', 'change' => 'Clarify required facts.',
            'validation' => 'Benchmark prose and numbered reports.', 'annotation_ids' => $cases->take(3)->pluck('id')->all()];
        $second = array_replace($first, ['category' => 'classifier', 'finding' => 'Compare classifiers with wording held fixed.']);
        AnnotationAdvisor::fake(fn () => ['suggestions' => [$first, $second]])->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $analysis = AnnotationAnalysis::sole();
        $dismiss = route('toll-troll-annotations.advice.dismiss', ['support', $analysis->id, AnnotationAnalysis::suggestionKey($first)]);
        $dashboard = '/toll-troll/annotations';
        $this->post($dismiss)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => false);
        $this->post($dismiss)->assertForbidden();
        Gate::define('review-toll-troll-annotations', fn () => true);
        $this->get($dashboard)->assertSee($first['finding'])->assertSee($second['finding'])
            ->assertSee('using your ground-truth set')->assertSee('1 / 2')
            ->assertSee('name="dashboard" value="1"', false)->assertSee('id="shortcuts-enabled"', false);
        $this->get($dashboard.'/support')->assertSee('name="dashboard" value="0"', false);
        $this->post(str_replace('/support/', '/other/', $dismiss))->assertNotFound();
        $this->post(str_replace(AnnotationAnalysis::suggestionKey($first), str_repeat('0', 64), $dismiss))->assertNotFound();
        foreach ([1, 2] as $attempt) {
            $this->post($dismiss, ['dashboard' => true])->assertRedirect($dashboard)->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get($dashboard)->assertDontSee($first['finding'])->assertSee($second['finding'])->assertSee('1 / 1');
        $this->get($dashboard.'/support')->assertDontSee($first['finding'])->assertSee($second['finding']);
        $this->actingAs(new GenericUser(['id' => 8]));
        $this->get($dashboard)->assertSee($first['finding'])->assertSee($second['finding']);
        $this->actingAs(new GenericUser(['id' => 7]));
        $this->post($dashboard.'/support/'.$cases->last()->id, ['label' => 'fail', 'version' => 1])->assertRedirect();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $this->get($dashboard)->assertDontSee($first['finding'])->assertSee($second['finding']);
        $this->post(route('toll-troll-annotations.advice.dismiss', ['support', $analysis->id, AnnotationAnalysis::suggestionKey($second)]))->assertRedirect($dashboard.'/support');
        $this->get($dashboard)->assertDontSee('offers this advice:');
        // Deleting sensitive analysis also removes its associated dismissals.
        $analysis->delete();
        $this->assertSame(0, DB::table('toll_troll_annotation_advice_dismissals')->count());
    }

    public function test_new_policy_revisions_retire_only_the_matching_gate_and_do_not_reactivate_on_late_captures(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        $this->analysisCases(30, 5);
        $this->analysisCases(30, 5, 'support', new Policy('other_gate', 'Require an environment.'));
        $this->analysisCases(30, 5, 'other');
        AnnotationAdvisor::fake(function ($prompt) {
            $input = json_decode($prompt, true);

            return ['suggestions' => [['category' => 'policy_wording', 'finding' => 'Advice for '.$input['policy']['id'],
                'change' => 'Clarify wording.', 'validation' => 'Benchmark held-out ground truth.',
                'annotation_ids' => collect($input['cases'])->filter(fn ($case) => $case['expected'] !== $case['prediction']['verdict'])->take(3)->pluck('id')->all()]]];
        })->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => true);
        $page = '/toll-troll/annotations/support';
        $this->get($page)->assertSee('Advice for bug_report')->assertSee('Advice for other_gate');
        $next = Policy::bugReport()->withThresholds(0.9, 0.3);
        Event::dispatch(new AnnotationRequested('support', 'new-revision', new Context('Triage.', ['report' => 'A new report.']), $next));
        $this->get($page)->assertDontSee('Advice for bug_report')->assertSee('Advice for other_gate');
        $this->get('/toll-troll/annotations/other')->assertSee('Advice for bug_report');
        Event::dispatch(new AnnotationRequested('support', 'late-old-revision', new Context('Triage.', ['report' => 'A delayed report.']), Policy::bugReport()));
        Annotation::where('sample_key', 'new-revision')->delete();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $this->get($page)->assertDontSee('Advice for bug_report')->assertSee('Advice for other_gate');
        AnnotationAdvisor::assertPromptedTimes(3);

        // Explicit deployments support rollbacks and take precedence over capture order.
        Event::dispatch(new PolicyDeployed('support', Policy::bugReport()));
        $this->get($page)->assertSee('Advice for bug_report');
        Event::dispatch(new PolicyDeployed('support', $next->withReferences(['domain' => 'Approved reference facts.'])));
        Event::dispatch(new AnnotationRequested('support', 'unseen-old-rollout', new Context('Triage.', ['report' => 'Late worker.']), Policy::bugReport()->withThresholds(0.85, 0.25)));
        $this->get($page)->assertDontSee('Advice for bug_report')->assertSee('Advice for other_gate');
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        AnnotationAdvisor::assertPromptedTimes(3);
    }

    public function test_a_deployment_during_analysis_hides_its_late_result_without_more_inference(): void
    {
        config()->set('toll-troll-annotations.analysis.enabled', true);
        $cases = $this->analysisCases(30, 5);
        AnnotationAdvisor::fake(function () use ($cases) {
            Event::dispatch(new PolicyDeployed('support', Policy::bugReport()->withThresholds(0.9, 0.3)));

            return ['suggestions' => [['category' => 'policy_wording', 'finding' => 'Late advice for the retired policy.',
                'change' => 'Clarify wording.', 'validation' => 'Benchmark held-out ground truth.',
                'annotation_ids' => $cases->take(3)->pluck('id')->all()]]];
        })->preventStrayPrompts();
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        $this->assertCount(1, AnnotationAnalysis::sole()->suggestions);
        $this->actingAs(new GenericUser(['id' => 7]));
        Gate::define('review-toll-troll-annotations', fn () => true);
        $this->get('/toll-troll/annotations')->assertOk()->assertDontSee('Late advice')->assertDontSee('offers this advice:');
        $this->artisan('toll-troll:analyze-annotations')->assertSuccessful();
        AnnotationAdvisor::assertPromptedTimes(1);
    }

    private function analysisCases(int $count, int $disagreements, string $dataset = 'support', ?Policy $policy = null): Collection
    {
        $cases = collect();
        for ($i = 0; $i < $count; $i++) {
            $key = ($policy?->id ?? 'report').'-'.$i;
            Event::dispatch(new AnnotationRequested($dataset, $key,
                new Context('Triage reports.', ['report' => 'Report '.$i.': saving a draft crashes.']), $policy ?? Policy::bugReport()));
            $case = Annotation::where('dataset', $dataset)->where('sample_key', $key)->sole();
            $case->update(['label' => $i < $disagreements ? 'pass' : 'fail', 'version' => 1, 'reviewed_at' => now(),
                'prediction' => ['evaluation_id' => 'run-'.$i, 'branch' => 'bug', 'verdict' => 'fail', 'assessments' => [], 'variants' => [], 'errors' => []]]);
            $cases->push($case);
        }

        return $cases;
    }
}

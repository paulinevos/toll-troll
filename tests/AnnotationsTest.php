<?php

namespace Vos\TollTroll\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Vos\TollTroll\Annotations\Annotation;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Annotations\AnnotationServiceProvider;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTrollServiceProvider;

class AnnotationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TollTrollServiceProvider::class, AnnotationServiceProvider::class];
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
}

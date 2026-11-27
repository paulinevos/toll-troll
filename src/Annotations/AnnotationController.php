<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AnnotationController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'"];

    private function authorize(Request $request, ?string $dataset = null): void
    {
        abort_unless($request->user(), 403);
        if ($dataset !== null) {
            Gate::forUser($request->user())->authorize('review-toll-troll-annotations', [$dataset]);
        }
    }

    public function index(Request $request)
    {
        $this->authorize($request);

        return $this->view($request, []);
    }

    public function show(Request $request, string $dataset, ?string $id = null)
    {
        $this->authorize($request, $dataset);
        $query = app(Annotation::class)->where('dataset', $dataset);
        $selected = $id === null ? null : (clone $query)->findOrFail($id);
        $pending = (clone $query)->whereNull('label')->count();
        $page = LengthAwarePaginator::resolveCurrentPage();
        if ($selected) {
            // Locate the case in the pending-first queue, including after a save moves it.
            $before = (clone $query)->when($selected->label === null,
                fn ($query) => $query->whereNull('label'), fn ($query) => $query->whereNotNull('label'))
                ->where(function ($query) use ($selected) {
                    $query->where('created_at', '<', $selected->created_at)
                        ->orWhere(fn ($query) => $query->where('created_at', $selected->created_at)->where('id', '<', $selected->id));
                })->count();
            $before += $selected->label === null ? 0 : $pending;
            $page = intdiv($before, 15) + 1;
        }
        // Slice the two queue segments separately, without SQL expressions or loading the full dataset.
        $offset = ($page - 1) * 15;
        $ordered = (clone $query)->orderBy('created_at')->orderBy('id');
        $items = $offset < $pending ? (clone $ordered)->whereNull('label')->offset($offset)->limit(15)->get() : collect();
        if ($items->count() < 15) {
            $items = $items->concat((clone $ordered)->whereNotNull('label')->offset(max(0, $offset - $pending))->limit(15 - $items->count())->get());
        }
        $cases = new LengthAwarePaginator($items, (clone $query)->count(), 15, $page, ['path' => route('toll-troll-annotations.show', $dataset)]);
        $selected ??= $request->query('edge') === 'last' ? $cases->last() : $cases->first();
        $previousUrl = $nextUrl = null;
        if ($selected) {
            $position = $cases->getCollection()->search(fn ($case) => $case->id === $selected->id);
            $previousUrl = $position > 0
                ? route('toll-troll-annotations.show', [$dataset, $cases[$position - 1]->id])
                : ($cases->onFirstPage() ? null : route('toll-troll-annotations.show', [$dataset, 'page' => $cases->currentPage() - 1, 'edge' => 'last']));
            $nextUrl = $position < $cases->count() - 1
                ? route('toll-troll-annotations.show', [$dataset, $cases[$position + 1]->id])
                : ($cases->hasMorePages() ? route('toll-troll-annotations.show', [$dataset, 'page' => $cases->currentPage() + 1]) : null);
        }

        return $this->view($request, compact('dataset', 'cases', 'selected', 'pending', 'previousUrl', 'nextUrl'));
    }

    private function view(Request $request, array $data)
    {
        $bridges = app(Annotation::class)->newQuery()->groupBy('dataset')->orderBy('dataset')->pluck('dataset')
            ->filter(fn ($name) => Gate::forUser($request->user())->allows('review-toll-troll-annotations', [$name]));
        $advice = (isset($data['dataset']) ? collect([$data['dataset']]) : $bridges)
            ->flatMap(fn ($name) => app(AnalyzeAnnotations::class)->advice($name, (string) $request->user()->getAuthIdentifier()))->values();
        $shortcutsScript = file_get_contents(__DIR__.'/../../resources/js/annotations.js');
        $headers = self::HEADERS;
        // Only this package-owned script may execute; source context remains escaped.
        $headers['Content-Security-Policy'] .= "; script-src 'sha256-".base64_encode(hash('sha256', $shortcutsScript, true))."'";

        return response()->view('toll-troll-annotations::review', $data + compact('bridges', 'advice', 'shortcutsScript'), 200, $headers);
    }

    public function store(Request $request, string $dataset, string $id)
    {
        $this->authorize($request, $dataset);
        $input = $request->validate(['label' => ['required', 'in:pass,fail,unknown'], 'note' => ['nullable', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:0']]);

        DB::transaction(function () use ($request, $dataset, $id, $input) {
            $case = app(Annotation::class)->where('dataset', $dataset)->findOrFail($id);
            $case->fill(['label' => $input['label'], 'note' => $input['note'] ?? null, 'reviewer_id' => (string) $request->user()->getAuthIdentifier(),
                'version' => (int) $input['version'] + 1, 'reviewed_at' => now()]);
            // getDirty() supplies encrypted database values; the conditional update prevents lost reviews.
            $changed = app(Annotation::class)->whereKey($id)->where('version', $input['version'])->update($case->getDirty());
            abort_unless($changed === 1, 409, 'Another reviewer saved this case. Reload before labeling it.');
            DB::table('toll_troll_annotation_reviews')->insert([
                'annotation_id' => $id, 'version' => $case->version, 'label' => $case->label,
                'note' => $case->getAttributes()['note'], 'reviewer_id' => $case->reviewer_id, 'created_at' => now(),
            ]);
        });

        return redirect()->route('toll-troll-annotations.show', [$dataset, $id])->with('status', 'Ground truth saved.')->withHeaders(self::HEADERS);
    }

    public function dismissAdvice(Request $request, string $dataset, string $analysisId, string $suggestionKey)
    {
        $this->authorize($request, $dataset);
        abort_unless(config('toll-troll-annotations.analysis.enabled'), 404);
        $analysis = app(AnnotationAnalysis::class)->where('dataset', $dataset)->findOrFail($analysisId);
        abort_unless(collect($analysis->suggestions)->contains(fn ($suggestion) => AnnotationAnalysis::suggestionKey($suggestion) === $suggestionKey), 404);

        DB::table('toll_troll_annotation_advice_dismissals')->upsert([
            'analysis_id' => $analysis->id, 'reviewer_id' => (string) $request->user()->getAuthIdentifier(), 'suggestion_key' => $suggestionKey,
        ], ['analysis_id', 'reviewer_id', 'suggestion_key'], ['suggestion_key']);

        return ($request->boolean('dashboard')
            ? redirect()->route('toll-troll-annotations.index')
            : redirect()->route('toll-troll-annotations.show', $dataset))->withHeaders(self::HEADERS);
    }

    public function export(Request $request, string $dataset, string $hash)
    {
        $this->authorize($request, $dataset);
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $hash), 404);
        $query = app(Annotation::class)->where('dataset', $dataset)->where('policy_hash', $hash)->whereNotNull('label');
        abort_unless((clone $query)->exists(), 404);

        return response()->streamDownload(function () use ($query) {
            foreach ($query->orderBy('id')->cursor() as $case) {
                echo json_encode(['id' => $case->id, 'bridge' => $case->dataset, 'expected' => $case->label, 'instructions' => $case->context['instructions'],
                    'sources' => $case->context['sources'], 'policy' => $case->policy, 'note' => $case->note,
                    ...($case->prediction === null ? [] : ['prediction' => $case->prediction]),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            }
        }, $dataset.'-'.$hash.'.jsonl', self::HEADERS + ['Content-Type' => 'application/x-ndjson']);
    }
}

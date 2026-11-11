<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Http\Request;
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
        $datasets = Annotation::query()->distinct()->pluck('dataset')->filter(fn ($dataset) => Gate::forUser($request->user())->allows('review-toll-troll-annotations', [$dataset]));

        return $this->view(compact('datasets'));
    }

    public function show(Request $request, string $dataset, ?string $id = null)
    {
        $this->authorize($request, $dataset);
        $selected = $id === null ? null : Annotation::where('dataset', $dataset)->findOrFail($id);
        $page = null;
        if ($selected) {
            // Locate the case in the pending-first queue, including after a save moves it.
            $rank = $selected->label === null ? 0 : 1;
            $before = Annotation::where('dataset', $dataset)->where(function ($query) use ($selected, $rank) {
                $query->whereRaw('CASE WHEN label IS NULL THEN 0 ELSE 1 END < ?', [$rank])
                    ->orWhere(function ($query) use ($selected, $rank) {
                        $query->whereRaw('CASE WHEN label IS NULL THEN 0 ELSE 1 END = ?', [$rank])
                            ->where(function ($query) use ($selected) {
                                $query->where('created_at', '<', $selected->created_at)
                                    ->orWhere(fn ($query) => $query->where('created_at', $selected->created_at)->where('id', '<', $selected->id));
                            });
                    });
            })->count();
            $page = intdiv($before, 15) + 1;
        }
        $cases = Annotation::where('dataset', $dataset)->orderByRaw('CASE WHEN label IS NULL THEN 0 ELSE 1 END')->orderBy('created_at')->orderBy('id')
            ->paginate(15, ['*'], 'page', $page)->withPath(route('toll-troll-annotations.show', $dataset));
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
        $pending = Annotation::where('dataset', $dataset)->whereNull('label')->count();

        return $this->view(compact('dataset', 'cases', 'selected', 'pending', 'previousUrl', 'nextUrl'));
    }

    private function view(array $data)
    {
        $shortcutsScript = file_get_contents(__DIR__.'/../../resources/js/annotations.js');
        $headers = self::HEADERS;
        // Only this package-owned script may execute; source context remains escaped.
        $headers['Content-Security-Policy'] .= "; script-src 'sha256-".base64_encode(hash('sha256', $shortcutsScript, true))."'";

        return response()->view('toll-troll-annotations::review', $data + compact('shortcutsScript'), 200, $headers);
    }

    public function store(Request $request, string $dataset, string $id)
    {
        $this->authorize($request, $dataset);
        $input = $request->validate(['label' => ['required', 'in:pass,fail,unknown'], 'note' => ['nullable', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:0']]);

        DB::transaction(function () use ($request, $dataset, $id, $input) {
            $case = Annotation::where('dataset', $dataset)->findOrFail($id);
            $case->fill(['label' => $input['label'], 'note' => $input['note'] ?? null, 'reviewer_id' => (string) $request->user()->getAuthIdentifier(),
                'version' => (int) $input['version'] + 1, 'reviewed_at' => now()]);
            // getDirty() supplies encrypted database values; the conditional update prevents lost reviews.
            $changed = Annotation::whereKey($id)->where('version', $input['version'])->update($case->getDirty());
            abort_unless($changed === 1, 409, 'Another reviewer saved this case. Reload before labeling it.');
            DB::table('toll_troll_annotation_reviews')->insert([
                'annotation_id' => $id, 'version' => $case->version, 'label' => $case->label,
                'note' => $case->getAttributes()['note'], 'reviewer_id' => $case->reviewer_id, 'created_at' => now(),
            ]);
        });

        return redirect()->route('toll-troll-annotations.show', [$dataset, $id])->with('status', 'Ground truth saved.')->withHeaders(self::HEADERS);
    }

    public function export(Request $request, string $dataset, string $hash)
    {
        $this->authorize($request, $dataset);
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $hash), 404);
        $query = Annotation::where('dataset', $dataset)->where('policy_hash', $hash)->whereNotNull('label');
        abort_unless($query->exists(), 404);

        return response()->streamDownload(function () use ($query) {
            foreach ($query->orderBy('id')->cursor() as $case) {
                echo json_encode(['id' => $case->id, 'expected' => $case->label, 'instructions' => $case->context['instructions'],
                    'sources' => $case->context['sources'], 'policy' => $case->policy, 'note' => $case->note], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            }
        }, $dataset.'-'.$hash.'.jsonl', self::HEADERS + ['Content-Type' => 'application/x-ndjson']);
    }
}

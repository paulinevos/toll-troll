<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Annotations · TollTroll</title>
    <link rel="icon" type="image/svg+xml" href="{{ $iconDataUri }}">
    @include('toll-troll-annotations::styles')
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header>
    <div class="header-inner">
        <a class="brand" href="{{ route('toll-troll-annotations.index') }}" aria-label="TollTroll annotation sets"><img src="{{ $iconDataUri }}" alt="" width="44" height="44"><span>TollTroll</span></a>
        <span class="header-section">Annotations</span>
        @isset($dataset)<a class="back-link" href="{{ route('toll-troll-annotations.index') }}">All datasets <span aria-hidden="true">↗</span></a>@endisset
    </div>
</header>
<main id="main" tabindex="-1">
    @if(isset($datasets))
        <div class="intro">
            <div><span class="eyebrow">Ground truth</span><h1>Annotation sets</h1><p class="muted">Select a dataset to review its cases.</p></div>
            <span class="tag">{{ $datasets->count() }} {{ \Illuminate\Support\Str::plural('dataset', $datasets->count()) }}</span>
        </div>
        <div class="dataset-list">
            @forelse($datasets as $name)
                <a class="panel dataset-card" href="{{ route('toll-troll-annotations.show', $name) }}">
                    <span class="eyebrow">Dataset</span><h2>{{ $name }}</h2><span class="dataset-action">Open review queue <span aria-hidden="true">→</span></span>
                </a>
            @empty
                <div class="panel empty"><span class="empty-mark" aria-hidden="true">＋</span><h2>No annotation sets available</h2><p class="muted">Dispatch an <code>AnnotationRequested</code> event and authorize this reviewer for its dataset.</p></div>
            @endforelse
        </div>
    @else
        <div class="intro">
            <div><span class="eyebrow">Dataset</span><h1>{{ $dataset }}</h1></div>
            <div class="counts"><span class="tag pending">{{ $pending }} pending</span><span class="muted small">{{ $cases->total() }} total cases</span></div>
        </div>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert" id="review-error">{{ $errors->first() }}</div>@endif
        <div class="layout">
            <aside>
                <h2 class="eyebrow queue-heading">Review queue</h2>
                <nav class="queue" aria-label="Annotation cases">
                    @foreach($cases as $case)
                        <a class="case" href="{{ route('toll-troll-annotations.show', [$dataset, $case->id, 'page' => $cases->currentPage()]) }}" @if($selected?->id === $case->id) aria-current="page" @endif>
                            <strong>{{ $case->sample_key }}</strong><span class="tag {{ $case->label ?? 'pending' }}">{{ ucfirst($case->label ?? 'pending') }}</span>
                        </a>
                    @endforeach
                </nav>
                <nav class="page-links" aria-label="Queue pages">
                    @if($cases->previousPageUrl())<a href="{{ $cases->previousPageUrl() }}">← Previous</a>@endif
                    @if($cases->nextPageUrl())<a href="{{ $cases->nextPageUrl() }}">Next →</a>@endif
                </nav>
            </aside>
            <section aria-label="Review case">
                @if($selected)
                    <nav class="case-navigation" aria-label="Previous and next case">
                        @if($previousUrl)<a href="{{ $previousUrl }}" rel="prev">← Previous case <kbd data-shortcuts hidden>Q / U</kbd></a>@else<span class="muted" aria-disabled="true">← Previous case</span>@endif
                        @if($nextUrl)<a href="{{ $nextUrl }}" rel="next">Next case <kbd data-shortcuts hidden>E / O</kbd> →</a>@else<span class="muted" aria-disabled="true">Next case →</span>@endif
                    </nav>
                    <div class="panel context-panel">
                        <div class="panel-heading"><span class="eyebrow">Case details</span><span class="tag {{ $selected->label ?? 'pending' }}">{{ ucfirst($selected->label ?? 'pending') }}</span></div>
                        <h2 class="case-title">{{ $selected->sample_key }}</h2>
                        <div class="policy-block"><h3>Policy <code>{{ $selected->policy['id'] }}</code></h3><p class="policy">{{ $selected->policy['criteria'] }}</p></div>
                        <details open><summary>Trusted target instructions</summary><pre>{{ $selected->context['instructions'] }}</pre></details>
                        @foreach($selected->context['sources'] as $source => $text)
                            <div class="sources"><div class="source-heading"><h3>{{ $source }}</h3><span class="eyebrow">Untrusted source</span></div><pre>{{ $text }}</pre></div>
                        @endforeach
                        @if($selected->policy['references'] ?? [])
                            <div class="policy-block"><h3>Policy references</h3><p class="policy small">Domain context, not instructions or facts supplied by the reporter.</p></div>
                            @foreach($selected->policy['references'] as $name => $text)
                                <div class="sources"><div class="source-heading"><h3>{{ $name }}</h3><span class="eyebrow">Reference data</span></div><pre>{{ $text }}</pre></div>
                            @endforeach
                        @endif
                    </div>
                    <form class="panel review-panel" method="post" action="{{ route('toll-troll-annotations.store', [$dataset, $selected->id]) }}" data-saved-label="{{ $selected->label }}" data-saved-note="{{ $selected->note }}">
                        @csrf
                        <input type="hidden" name="version" value="{{ $selected->version }}">
                        <div class="panel-heading"><h2>Review</h2><span class="muted small">Revision {{ $selected->version }}</span></div>
                        <fieldset class="choices" @if($errors->has('label')) aria-describedby="review-error" @endif><legend>Does this context satisfy the policy?</legend>
                            @foreach(['pass' => 'Meets the criteria', 'fail' => 'Violates the criteria', 'unknown' => 'Insufficient evidence'] as $value => $help)
                                <label class="choice {{ $value }}"><span><input type="radio" name="label" value="{{ $value }}" required @checked(old('label', $selected->label) === $value)> <strong>{{ ucfirst($value) }}</strong> <kbd data-shortcuts hidden>{{ ['pass' => 'A / J', 'fail' => 'S / K', 'unknown' => 'D / L'][$value] }}</kbd></span><small>{{ $help }}</small></label>
                            @endforeach
                        </fieldset>
                        <div class="note-heading"><label for="note">Reviewer note <span class="muted">(optional)</span></label><span class="muted small" id="note-help">Up to 2,000 characters</span></div>
                        <textarea id="note" name="note" maxlength="2000" rows="3" aria-describedby="note-help{{ $errors->has('note') ? ' review-error' : '' }}" @if($errors->has('note')) aria-invalid="true" @endif placeholder="Explain your label for future reviewers.">{{ old('note', $selected->note) }}</textarea>
                        <div class="form-footer"><button type="submit">Save ground truth <span aria-hidden="true">→</span></button><span class="muted small">Saves a label. Does not dispatch work.</span></div>
                        <div class="shortcut-help" data-shortcuts hidden>
                            <div class="shortcut-settings"><label><input id="shortcuts-enabled" type="checkbox" checked> Keyboard shortcuts</label><span id="review-draft" role="status"></span></div>
                            <p class="muted">Keys select, never save. <kbd>Ctrl / ⌘ + Enter</kbd> saves. Letter shortcuts pause while typing notes. Navigation warns before discarding unsaved changes.</p>
                        </div>
                    </form>
                    @if($selected->label !== null)
                        <div class="export-row"><a href="{{ route('toll-troll-annotations.export', [$dataset, $selected->policy_hash]) }}">Download reviewed JSONL <span aria-hidden="true">↓</span></a><span class="muted small">This policy revision only</span></div>
                    @endif
                @else
                    <div class="panel empty"><span class="empty-mark" aria-hidden="true">＋</span><h2>No cases yet</h2><p class="muted">Cases appear here when your application dispatches <code>AnnotationRequested</code>.</p></div>
                @endif
            </section>
        </div>
    @endif
</main>
<script>{!! $shortcutsScript !!}</script>
</body>
</html>

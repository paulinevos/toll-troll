@php
    $verdict = $annotation->prediction['verdict'] ?? null;
    $status = $annotation->label === null ? 'pending'
        : (in_array($verdict, ['pass', 'fail', 'unknown'], true)
            ? ($annotation->label === $verdict ? 'agree' : 'disagree')
            : ($verdict !== null || $annotation->label === 'unknown' ? 'unknown' : 'annotated'));
    $text = ['pending' => 'Pending', 'agree' => 'Annotated · Agrees', 'disagree' => 'Annotated · Disagrees',
        'unknown' => 'Annotated · Unknown', 'annotated' => 'Annotated'][$status];
    $description = $annotation->label === null ? 'Not annotated yet'
        : 'Human label: '.ucfirst($annotation->label).($verdict === null ? ' · No recorded prediction'
            : ' · Prediction: '.ucfirst($verdict).(in_array($verdict, ['error', 'skipped'], true) ? ' · Agreement unknown' : ''));
@endphp
<span class="tag annotation-status {{ $status }}" role="img" aria-label="{{ $text }} · {{ $description }}">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9" />
        @if($status === 'agree' || $status === 'annotated')
            <path d="m8 12 3 3 5-6" />
        @elseif($status === 'disagree')
            <path d="m9 9 6 6m0-6-6 6" />
        @elseif($status === 'unknown')
            <path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 1.5-2.5 2-2.5 3M12 16h.01" />
        @else
            <path d="M12 7v5l3 2" />
        @endif
    </svg>
    @unless($iconOnly ?? false){{ $text }}@endunless
</span>

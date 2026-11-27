<section class="panel" aria-labelledby="prediction-heading">
    <div class="panel-heading"><h2 id="prediction-heading">Recorded prediction</h2><span class="tag {{ $prediction['verdict'] }}">{{ ucfirst($prediction['verdict']) }}</span></div>
    <p class="policy small">{{ $prediction['branch'] === null ? 'Guard' : 'Branch: '.$prediction['branch'] }} · Evaluation <code>{{ $prediction['evaluation_id'] }}</code></p>
    @if(in_array($prediction['verdict'], ['error', 'skipped'], true))
        <p class="policy">{{ $prediction['verdict'] === 'error' ? 'Prediction failed. There is no classification to agree with.' : 'Not evaluated: an earlier prerequisite or evaluation failure blocked this policy.' }} You can still supply the expected label.</p>
    @elseif($label === null)
        <p class="policy">Awaiting your expected label. The prediction is never used to preselect it.</p>
    @else
        <p class="policy"><strong>{{ $label === $prediction['verdict'] ? 'Agrees with prediction' : 'Disagrees with prediction' }}</strong> · Human: {{ ucfirst($label) }} · Predicted: {{ ucfirst($prediction['verdict']) }}</p>
    @endif
    @foreach($prediction['assessments'] as $assessment)
        <div class="sources">
            <div class="source-heading"><h3>{{ $assessment['sample'] }}</h3><span class="tag {{ $assessment['verdict'] }}">{{ ucfirst($assessment['verdict']) }}</span></div>
            <pre>{{ $assessment['provider'] ?? 'SDK default' }} / {{ $assessment['model'] ?? 'SDK default' }}
@if(isset($assessment['choice'])){{ "\nChoice: ".ucfirst($assessment['choice']) }}@endif
@if(!empty($assessment['probabilities']))@foreach($assessment['probabilities'] as $candidate => $probability){{ "\n".ucfirst($candidate).': '.number_format($probability * 100, 1).'%' }}@endforeach
@elseif(isset($assessment['score'])){{ "\nLegacy score: ".$assessment['score'] }}@endif
@if(isset($assessment['confidence'])){{ "\nConfidence: ".number_format($assessment['confidence'] * 100, 1).'%' }}@endif
@if(!empty($assessment['error'])){{ "\nError: ".$assessment['error'] }}@endif</pre>
        </div>
    @endforeach
    @foreach($prediction['variants'] as $name => $variant)
        @foreach($variant['sources'] as $source => $text)
            <div class="sources"><div class="source-heading"><h3>{{ $name }} · {{ $source }}</h3><span class="eyebrow">Assessed rewrite</span></div><pre>{{ $text }}</pre></div>
        @endforeach
    @endforeach
    @foreach($prediction['errors'] as $error)
        <p class="policy small">Evaluation error at {{ $error['stage'] }}: {{ $error['error'] }}</p>
    @endforeach
</section>

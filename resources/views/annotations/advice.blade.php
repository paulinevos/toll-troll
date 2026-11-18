@if($advice->isNotEmpty())
    <section class="troll-advice" aria-label="Toll Troll advice" aria-roledescription="carousel" data-advice-carousel>
        <img class="advice-troll" src="{{ $iconDataUri }}" alt="" width="64" height="64">
        <div class="advice-content">
            <p class="advice-intro">The Toll Troll has analyzed your annotations and offers this advice:</p>
            <div class="advice-bubble">
                @foreach($advice as $card)
                    @php($analysis = $card['analysis'])
                    @php($suggestion = $card['suggestion'])
                    <article data-advice-card role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ $loop->count }}" aria-current="{{ $loop->first ? 'true' : 'false' }}">
                        <div class="advice-heading">
                            <div><span class="eyebrow">{{ $analysis->dataset }}</span><h2>{{ ucfirst(str_replace('_', ' ', $suggestion['category'])) }}</h2></div>
                            <form method="post" action="{{ route('toll-troll-annotations.advice.dismiss', [$analysis->dataset, $analysis->id, $card['key']]) }}">
                                @csrf
                                <input type="hidden" name="dashboard" value="{{ !isset($dataset) ? '1' : '0' }}">
                                <button class="advice-dismiss" type="submit" aria-label="Dismiss this advice for you" title="Dismiss this advice for you">×</button>
                            </form>
                        </div>
                        <p class="muted small advice-meta">{{ $analysis->reviewed_count }} distinct reviewed contexts · {{ $analysis->disagreement_count }} disagreements · Policy {{ substr($analysis->policy_hash, 0, 8) }}</p>
                        <div class="advice-suggestion">
                        <p>{{ $suggestion['finding'] }}</p>
                        <p><strong>Try:</strong> {{ $suggestion['change'] }}</p>
                        <p><strong>Benchmark:</strong> Compare the current configuration with this suggested improvement using your ground-truth set. Keep evaluation cases held out from tuning.</p>
                        <p><strong>Check:</strong> {{ $suggestion['validation'] }}</p>
                        <p class="small">Supporting cases:
                            @foreach(array_unique($suggestion['annotation_ids']) as $id)
                                <a href="{{ route('toll-troll-annotations.show', [$analysis->dataset, $id]) }}">{{ substr($id, -8) }}</a>{{ $loop->last ? '' : ', ' }}
                            @endforeach
                        </p>
                        </div>
                    </article>
                @endforeach
                <div class="advice-footer">
                    <p class="muted small advice-caution">An experiment, not a proven improvement.<br>Nothing has been changed automatically.</p>
                    <div class="advice-navigation" aria-label="Browse advice" data-advice-controls hidden>
                        <div class="advice-nav-key"><button type="button" data-advice-prev aria-label="Previous advice" aria-keyshortcuts="j">←</button><kbd data-advice-key>J</kbd></div>
                        <output data-advice-position aria-live="polite" aria-atomic="true">1 / {{ $advice->count() }}</output>
                        <div class="advice-nav-key"><button type="button" data-advice-next aria-label="Next advice" aria-keyshortcuts="k">→</button><kbd data-advice-key>K</kbd></div>
                    </div>
                </div>
                <p class="muted small advice-keyboard-help" data-advice-help hidden>J / K browse advice outside the review form.</p>
                @if(!isset($dataset) && $advice->count() > 1)
                    <label class="muted small advice-keyboard-help" data-shortcuts hidden><input id="shortcuts-enabled" type="checkbox" checked> Keyboard shortcuts</label>
                @endif
            </div>
        </div>
    </section>
@endif

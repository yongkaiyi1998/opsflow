@props(['fallback', 'explanation' => null, 'generateUrl', 'actionable' => false])
<section class="card detail-section"><div class="card-body">
    <div class="detail-section-heading d-flex flex-wrap justify-content-between gap-3">
        <div><h2>Why am I approving this?</h2><p>Authoritative route facts with an optional plain-language AI explanation.</p></div>
        @if (config('ai.enabled') && $actionable)<form method="POST" action="{{ $generateUrl }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">{{ $explanation ? 'Refresh explanation' : 'Explain with AI' }}</button></form>@endif
    </div>
    <div class="alert alert-light border mb-3"><strong>{{ $fallback->headline }}</strong><p class="mb-2 mt-1">{{ $fallback->explanation }}</p><ul class="mb-0">@foreach ($fallback->keyPoints as $point)<li>{{ $point }}</li>@endforeach</ul></div>
    @if (session('workflowExplanationError'))<div class="alert alert-warning mb-3">{{ session('workflowExplanationError') }}</div>@endif
    @if ($explanation)<div class="record-ai-panel"><span class="record-ai-kicker">AI explanation · Advisory</span><h3>{{ $explanation->headline }}</h3><p>{{ $explanation->explanation }}</p><ul class="mb-0">@foreach ($explanation->keyPoints as $point)<li>{{ $point }}</li>@endforeach</ul></div>@endif
</div></section>

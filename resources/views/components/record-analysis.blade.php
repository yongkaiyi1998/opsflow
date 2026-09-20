@props(['analysis' => null, 'systemChecks' => [], 'generateUrl'])

<section class="card detail-section" aria-labelledby="review-assistance-heading">
    <div class="card-body">
        <div class="detail-section-heading">
            <div>
                <h2 id="review-assistance-heading">Review assistance</h2>
                <p>Deterministic checks and optional AI context are kept separate.</p>
            </div>
            @if (config('ai.enabled'))
                <form method="POST" action="{{ $generateUrl }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary" type="submit">
                        {{ $analysis ? 'Refresh AI analysis' : 'Generate AI analysis' }}
                    </button>
                </form>
            @endif
        </div>

        @if (session('aiAnalysisError'))
            <div class="alert alert-light border small" role="alert">{{ session('aiAnalysisError') }}</div>
        @endif

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="h-100 border rounded-3 p-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <h3 class="h6 mb-0">System Checks</h3>
                        <span class="badge text-bg-light">Deterministic</span>
                    </div>
                    @forelse ($systemChecks as $check)
                        <div class="border-top pt-2 mt-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge {{ ($check['severity_label'] ?? '') === 'IMPORTANT' ? 'text-bg-danger' : 'text-bg-warning' }}">{{ str($check['severity_label'] ?? 'REVIEW')->lower()->title() }}</span>
                                <strong class="small">{{ $check['title'] }}</strong>
                            </div>
                            <p class="small text-secondary mb-0 mt-1">{{ $check['message'] }}</p>
                        </div>
                    @empty
                        <p class="small text-secondary mb-0">No configured system checks currently require attention.</p>
                    @endforelse
                </div>
            </div>

            @if ($analysis)
                <div class="col-lg-7">
                    <div class="h-100 border rounded-3 p-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <h3 class="h6 mb-0">AI Summary</h3>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge text-bg-light">Advisory</span>
                                @if ($analysis->stale)<span class="badge text-bg-warning">Out of date</span>@endif
                            </div>
                        </div>
                        @if ($analysis->stale)
                            <p class="small text-warning-emphasis">The authoritative record changed after this analysis. Refresh it before relying on the summary.</p>
                        @endif
                        @if ($analysis->headline)<strong class="d-block mb-2">{{ $analysis->headline }}</strong>@endif
                        <ul class="small mb-0 ps-3">
                            @foreach ($analysis->bullets as $bullet)<li class="mb-1">{{ $bullet }}</li>@endforeach
                        </ul>
                    </div>
                </div>

                <div class="col-12">
                    <div class="border rounded-3 p-3">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <h3 class="h6 mb-0">AI Observations</h3>
                            <span class="badge text-bg-light">Advisory, not a decision</span>
                        </div>
                        @forelse ($analysis->flags as $flag)
                            @php
                                $flagBadge = match ($flag['severity_label']) {
                                    'IMPORTANT' => 'text-bg-danger',
                                    'REVIEW' => 'text-bg-warning',
                                    default => 'text-bg-light',
                                };
                            @endphp
                            <div class="border-top pt-2 mt-2">
                                <div class="d-flex flex-wrap align-items-center gap-2"><span class="badge {{ $flagBadge }}">{{ str($flag['severity_label'])->lower()->title() }}</span><strong class="small">{{ $flag['title'] }}</strong></div>
                                <p class="small text-secondary mb-0 mt-1">{{ $flag['explanation'] }}</p>
                            </div>
                        @empty
                            <p class="small text-secondary mb-0">The AI analysis returned no advisory observations.</p>
                        @endforelse
                    </div>
                </div>
            @elseif (config('ai.enabled'))
                <div class="col-lg-7">
                    <div class="h-100 border rounded-3 p-3 d-flex flex-column justify-content-center">
                        <h3 class="h6">AI Summary and Observations</h3>
                        <p class="small text-secondary mb-0">Generate optional advisory context from the authoritative record. Approval and business actions do not depend on it.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>

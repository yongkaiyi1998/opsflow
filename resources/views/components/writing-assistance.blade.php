@props(['suggestion' => null])
@if (session('writingAssistanceError'))<div class="alert alert-warning mt-3">{{ session('writingAssistanceError') }}</div>@endif
@if ($suggestion)
<div class="alert alert-light border mt-3 writing-assistance" data-writing-assistance>
    <div class="d-flex justify-content-between gap-3"><div><strong>{{ $suggestion['label'] }}</strong><p class="mb-0 mt-1" data-writing-draft>{{ $suggestion['draft_text'] }}</p></div><button class="btn-close" type="button" aria-label="Ignore suggestion" data-writing-ignore></button></div>
    <div class="d-flex gap-2 mt-3"><button class="btn btn-sm btn-primary" type="button" data-writing-apply data-target="{{ $suggestion['target'] }}">Apply suggestion</button><button class="btn btn-sm btn-outline-secondary" type="button" data-writing-ignore>Ignore</button></div>
</div>
@once
@push('scripts')<script>document.addEventListener('click', event => { const apply = event.target.closest('[data-writing-apply]'); const ignore = event.target.closest('[data-writing-ignore]'); const panel = event.target.closest('[data-writing-assistance]'); if (apply && panel) { const field = document.getElementById(apply.dataset.target); if (field) { field.value = panel.querySelector('[data-writing-draft]').textContent.trim(); field.focus(); panel.remove(); } } if (ignore && panel) panel.remove(); });</script>@endpush
@endonce
@endif

@props(['suggestion' => null])

@if (session('categorySuggestionError'))
    <div class="alert alert-light border small mt-3 mb-0" role="status">
        {{ session('categorySuggestionError') }}
    </div>
@elseif (is_array($suggestion))
    <div class="border rounded-3 bg-body-tertiary p-3 mt-3" role="status">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <span class="text-uppercase text-secondary fw-semibold small">AI suggestion</span>
                    @if (! empty($suggestion['confidence_label']))
                        <span class="badge text-bg-light">{{ str($suggestion['confidence_label'])->lower()->title() }}</span>
                    @endif
                </div>
                <strong class="d-block">{{ $suggestion['category_name'] ?? 'Suggested category' }}</strong>
                <p class="small text-secondary mb-0 mt-1">{{ $suggestion['rationale'] ?? '' }}</p>
            </div>
            <button
                class="btn btn-sm btn-outline-primary apply-category-suggestion"
                type="button"
                data-category-id="{{ $suggestion['category_id'] ?? '' }}"
                data-category-target="{{ $suggestion['target'] ?? '' }}"
            >Use suggestion</button>
        </div>
    </div>
@endif

@once
    @push('scripts')
        <script>
        document.addEventListener('click', (event) => {
            const button = event.target.closest('.apply-category-suggestion');
            if (!button) return;

            const categorySelect = document.getElementById(button.dataset.categoryTarget);
            if (!categorySelect) return;

            categorySelect.value = button.dataset.categoryId;
            categorySelect.dispatchEvent(new Event('change', { bubbles: true }));
            categorySelect.focus();
        });
        </script>
    @endpush
@endonce

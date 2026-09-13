@props(['instance'])

<section {{ $attributes->merge(['class' => 'card border-0 shadow-sm mb-4']) }} aria-labelledby="approval-timeline-{{ $instance->id }}">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div><h2 class="h5" id="approval-timeline-{{ $instance->id }}">Approval timeline</h2><p class="text-secondary mb-3">Workflow version {{ $instance->workflowVersion->version }} · {{ $instance->workflowRuleGroup->name }}</p></div>
            <span class="badge text-bg-secondary align-self-start">{{ str($instance->status->value)->replace('_', ' ')->title() }}</span>
        </div>
        <ol class="list-group list-group-numbered mb-4">
            @foreach ($instance->steps as $step)
                <li class="list-group-item d-flex justify-content-between align-items-start"><div class="ms-2 me-auto"><div class="fw-semibold">{{ $step->name }}</div>@forelse ($step->assignments as $assignment)<small class="text-secondary d-block">{{ $assignment->approver->name }} · {{ str($assignment->status->value)->replace('_', ' ')->title() }}</small>@empty<small class="text-secondary">Assigned when this step becomes active.</small>@endforelse</div><span class="badge text-bg-light">{{ str($step->status->value)->replace('_', ' ')->title() }}</span></li>
            @endforeach
        </ol>
        <div class="vstack gap-3">
            @forelse ($instance->actions as $action)
                <div class="border-start border-3 ps-3">
                    <div>
                        <strong>{{ str($action->action->value)->replace('_', ' ')->title() }}</strong>
                        @if ($action->actor)
                            by {{ $action->actor->name }}
                        @else
                            by the system
                        @endif
                    </div>
                    <div class="small text-secondary">{{ $action->created_at->format('j M Y H:i') }}@if ($action->step) · {{ $action->step->name }}@endif</div>
                    @if ($action->comment)<div class="mt-1">{{ $action->comment }}</div>@endif
                </div>
            @empty
                <p class="text-secondary mb-0">No approval actions have been recorded.</p>
            @endforelse
        </div>
    </div>
</section>

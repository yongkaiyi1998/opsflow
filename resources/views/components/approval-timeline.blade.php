@props(['instance'])

<section {{ $attributes->merge(['class' => 'card detail-section approval-history']) }} aria-labelledby="approval-timeline-{{ $instance->id }}">
    <div class="card-body">
        <div class="detail-section-heading">
            <div><h2 id="approval-timeline-{{ $instance->id }}">Approval timeline</h2><p>Workflow version {{ $instance->workflowVersion->version }} · {{ $instance->workflowRuleGroup->name }}</p></div>
            <x-status-badge :status="$instance->status" class="align-self-start" />
        </div>
        <ol class="approval-steps">
            @foreach ($instance->steps as $step)
                <li class="approval-step approval-step-{{ strtolower($step->status->value) }}">
                    <span class="approval-step-marker" aria-hidden="true"></span>
                    <div class="approval-step-copy"><strong>{{ $step->name }}</strong>@forelse ($step->assignments as $assignment)<small>{{ $assignment->approver->name }} · {{ str($assignment->status->value)->replace('_', ' ')->title() }}</small>@empty<small>Assigned when this step becomes active.</small>@endforelse</div>
                    <x-status-badge :status="$step->status" />
                </li>
            @endforeach
        </ol>
        <div class="approval-events" aria-label="Approval activity">
            @forelse ($instance->actions as $action)
                <div class="approval-event approval-event-{{ strtolower($action->action->value) }}">
                    <span class="approval-event-marker" aria-hidden="true"></span>
                    <div class="approval-event-content">
                        <div class="approval-event-title"><strong>{{ str($action->action->value)->replace('_', ' ')->title() }}</strong><span>{{ $action->created_at->format('j M Y, H:i') }}</span></div>
                        <p>@if ($action->actor){{ $action->actor->name }}@else The system @endif @if ($action->step)<span> · {{ $action->step->name }}</span>@endif</p>
                        @if ($action->comment)<blockquote>{{ $action->comment }}</blockquote>@endif
                    </div>
                </div>
            @empty
                <x-empty-state title="No approval activity" description="Actions will appear here when the approval process begins." />
            @endforelse
        </div>
    </div>
</section>

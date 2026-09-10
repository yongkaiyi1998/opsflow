<?php

namespace App\Http\Requests;

use App\Models\ApprovalAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('approval_assignment');

        if (! $assignment instanceof ApprovalAssignment) {
            return false;
        }

        $ability = match (true) {
            $this->routeIs('approval-assignments.approve') => 'approve',
            $this->routeIs('approval-assignments.reject') => 'reject',
            $this->routeIs('approval-assignments.request-changes') => 'requestChanges',
            default => null,
        };

        return $ability !== null && ($this->user()?->can($ability, $assignment) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $requiresComment = $this->routeIs(
            'approval-assignments.reject',
            'approval-assignments.request-changes',
        );

        return [
            'comment' => [Rule::requiredIf($requiresComment), 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $comment = $this->string('comment')->trim()->toString();

        $this->merge(['comment' => $comment === '' ? null : $comment]);
    }
}

<?php

namespace App\Http\Requests;

use App\Models\ApprovalAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssistApprovalCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $assignment = $this->route('approval_assignment');
        $action = $this->route('writing_action');

        return $assignment instanceof ApprovalAssignment && in_array($action, ['request_changes', 'reject'], true) && ($this->user()?->can($action === 'request_changes' ? 'requestChanges' : 'reject', $assignment) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:2000']];
    }
}

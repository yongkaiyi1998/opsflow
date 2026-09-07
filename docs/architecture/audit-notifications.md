# Audit and Notifications

## 1. Purpose

OpsFlow must preserve important business history and notify users when action is required.

This document defines:

- approval history
- general activity logging
- domain events
- notifications
- queued side effects
- reminder and overdue behavior
- audit retention principles

The core rule is:

```text
Business state is authoritative.

Audit records explain what happened.

Notifications tell people what needs attention.
```

Notifications must never become the source of truth for workflow state.

---

# 2. Three Different Concepts

OpsFlow uses three separate concepts:

```text
Approval History
General Activity Log
Notifications
```

They solve different problems.

Do not merge them into one generic table.

---

# 3. Approval History

Approval-specific history is stored through `ApprovalAction`.

Examples:

- request submitted
- approver approved
- approver rejected
- approver requested changes
- requester resubmitted
- requester withdrew
- approval delegated
- workflow blocked

Approval history belongs to the approval runtime.

---

# 4. General Activity Log

`ActivityLog` records important broader system changes.

Examples:

- vendor updated
- department updated
- workflow version published
- workflow draft cloned
- delegation created
- important request fields changed
- user role changed
- spend category disabled

These actions may not belong to one approval step.

---

# 5. Notifications

Notifications tell users that something needs attention or that an important status changed.

Examples:

- approval assigned
- request approved
- request rejected
- changes requested
- request resubmitted
- approval overdue

Notifications are delivery mechanisms.

They are not audit records.

---

# 6. Why These Must Stay Separate

Suppose Finance approves a request.

The system may create:

```text
ApprovalAction
→ APPROVED
```

It may also create:

```text
ActivityLog
→ approval workflow completed
```

and send:

```text
Notification
→ requester informed
```

These records have different purposes.

Deleting or failing to deliver a notification must not remove the approval history.

---

# 7. ApprovalAction

Suggested fields:

```text
id

approval_instance_id
approval_step_instance_id nullable

actor_id nullable

action
comment nullable

metadata JSON nullable

created_at
```

`ApprovalAction` is append-oriented.

---

# 8. Approval Action Types

Typical actions include:

```text
SUBMITTED
ASSIGNED
APPROVED
REJECTED
CHANGES_REQUESTED
RESUBMITTED
WITHDRAWN
DELEGATED
STEP_COMPLETED
WORKFLOW_COMPLETED
WORKFLOW_BLOCKED
```

Not every internal model update requires an ApprovalAction.

Record meaningful business events.

---

# 9. Actor

When a human performs the action:

```text
actor_id = authenticated user
```

Examples:

- approve
- reject
- request changes
- resubmit
- withdraw

For system-generated actions:

```text
actor_id = null
```

may be acceptable.

Examples:

- workflow blocked
- scheduled escalation
- automatic assignment

Metadata may indicate:

```text
actor_type = SYSTEM
```

if needed later.

---

# 10. Approval Comments

Comments should be supported where business context matters.

Examples:

## Approve

Optional:

```text
Approved for Q4 software renewal.
```

## Reject

Recommended required:

```text
Budget is not available for this purchase.
```

## Request Changes

Required:

```text
Please attach the supplier quotation.
```

The requester should never have to guess why action was taken.

---

# 11. Approval Metadata

`metadata` may store additional structured information.

Examples:

```json
{
  "routing_changed": true,
  "previous_approval_instance_id": 81
}
```

or:

```json
{
  "delegated_from_user_id": 12
}
```

Do not store ordinary searchable fields only inside JSON if they deserve proper columns.

Metadata should be supplementary.

---

# 12. Approval Timeline

ApprovalActions provide the main source for the user-facing workflow timeline.

Example:

```text
09:00
Submitted by Alice

09:02
Manager approval assigned to John

10:13
Approved by John

10:14
Finance review assigned to Mary

15:40
Changes requested by Mary
"Please attach receipt."
```

Timeline data must reflect persisted history.

Do not rebuild history from current workflow configuration.

---

# 13. Timeline Visibility

Users who are authorized to view a business record may normally view its approval timeline.

The UI may show:

- action
- actor
- timestamp
- comment
- step name

Do not expose unnecessary internal metadata.

---

# 14. ActivityLog

Suggested fields:

```text
id

user_id nullable

action

subject_type
subject_id

old_values JSON nullable
new_values JSON nullable

metadata JSON nullable

ip_address nullable
user_agent nullable

created_at
```

Exact fields may be simplified during implementation if some metadata provides little value.

---

# 15. Activity Log Examples

Examples include:

```text
VENDOR_CREATED
VENDOR_UPDATED
VENDOR_DEACTIVATED

DEPARTMENT_UPDATED

WORKFLOW_VERSION_CREATED
WORKFLOW_VERSION_PUBLISHED
WORKFLOW_VERSION_ARCHIVED

REQUEST_UPDATED

DELEGATION_CREATED

USER_ROLE_CHANGED
```

Use consistent action naming.

---

# 16. Do Not Log Everything

Avoid creating activity logs for every trivial framework-level operation.

Bad examples:

```text
USER_OPENED_PAGE
REQUEST_LIST_VIEWED
MODEL_LOADED
```

unless there is a concrete compliance requirement.

Audit logs should remain useful.

---

# 17. What Should Be Audited

Audit actions that affect:

- financial information
- approval policy
- user authority
- routing
- important master data
- business status
- ownership
- delegation

These are meaningful business changes.

---

# 18. Old and New Values

For updates worth auditing, store important changes.

Example:

```json
{
  "old_values": {
    "payment_term": "30 days"
  },
  "new_values": {
    "payment_term": "60 days"
  }
}
```

Do not necessarily snapshot the entire model.

Prefer changed relevant fields.

---

# 19. Sensitive Data

Do not unnecessarily duplicate sensitive information into logs.

Avoid storing:

- passwords
- authentication secrets
- API keys
- full uploaded document contents
- large private notes without reason

Auditability does not justify copying secrets.

---

# 20. Immutability

ApprovalActions and ActivityLogs should generally not be editable through ordinary application flows.

They are historical records.

Do not provide:

```text
Edit Audit Log
Delete Approval Action
```

administrative CRUD screens.

---

# 21. Retention

V1 should retain approval and audit history indefinitely unless a future retention policy says otherwise.

Do not automatically purge important financial or approval history.

Short-lived technical application logs are a separate concern.

---

# 22. Application Logs vs Business Audit Logs

Laravel/application logs are for technical diagnostics.

Examples:

```text
exception
database failure
queue failure
unexpected state
```

ActivityLog is business history.

Example:

```text
Workflow Version 4 published by Admin.
```

Do not use Laravel log files as the only business audit mechanism.

---

# 23. Events

Domain events communicate that an important business state transition has completed.

Recommended events include:

```text
RequestSubmitted
ApprovalAssigned
ApprovalStepCompleted
RequestApproved
RequestRejected
ChangesRequested
RequestResubmitted
RequestWithdrawn
WorkflowBlocked
```

The exact event list may evolve.

---

# 24. Event Responsibility

Events describe something that already happened.

Example:

```text
RequestApproved
```

means the authoritative database state has already become approved.

The event must not mean:

```text
please try to approve the request later
```

Core state changes belong in Services.

---

# 25. Events Are Not Workflow Commands

Bad design:

```text
Controller
→ dispatch ApproveRequest event
→ Listener changes approval state
```

This makes business execution difficult to reason about.

Preferred:

```text
Controller
→ ApprovalService
→ transaction completes
→ RequestApproved event
```

Events mainly trigger secondary effects.

---

# 26. After-Commit Behavior

Events or jobs that depend on newly committed state should run after transaction commit.

Example:

```text
ApprovalService
    ↓
DB Transaction
    ↓
Commit
    ↓
ApprovalAssigned
    ↓
Notification Listener
```

Do not let a listener read uncommitted or rolled-back business state.

---

# 27. Notification Channels

V1 may support:

```text
database
email
```

Database notifications are useful for the in-app notification center.

Email provides external attention.

The workflow must still work if email is unavailable.

---

# 28. Notification Types

Recommended notification classes may include:

```text
ApprovalAssignedNotification

RequestApprovedNotification

RequestRejectedNotification

ChangesRequestedNotification

RequestResubmittedNotification

ApprovalOverdueNotification
```

Avoid one giant generic notification class with many conditional branches if separate types are clearer.

---

# 29. Approval Assigned Notification

Recipient:

```text
actual ApprovalAssignment approver
```

Example message:

```text
Purchase Request PR-2026-000123 requires your approval.
```

Useful content:

- request number
- requester
- amount
- current step
- direct application link

---

# 30. Changes Requested Notification

Recipient:

```text
request owner
```

Must include:

- request reference
- approver
- change-request comment
- direct link to edit/resubmit

Example:

```text
Finance requested changes to EXP-2026-000047.

Reason:
Please upload the hotel receipt.
```

---

# 31. Approved Notification

Recipient:

```text
request owner
```

Example:

```text
Purchase Request PR-2026-000123 has been approved.
```

Do not send notifications to every previous approver unless there is a business need.

---

# 32. Rejected Notification

Recipient:

```text
request owner
```

Should include rejection reason when available.

---

# 33. Resubmitted Notification

When a request is resubmitted:

notify the approver who now needs to review it.

Do not necessarily notify all completed previous approvers.

Example:

```text
Expense Claim EXP-2026-000047 was resubmitted and requires your review.
```

---

# 34. Workflow Blocked Notification

If runtime becomes BLOCKED:

notify an appropriate operational/admin audience.

The notification should explain:

- affected request
- affected step
- reason
- suggested corrective action

Example:

```text
PR-2026-000123 is blocked because the requester has no active manager.
```

---

# 35. Notification URLs

Notifications should link to authorized application routes.

Never assume possession of a notification URL grants access.

The destination controller must still perform Policy checks.

---

# 36. Queued Notifications

Email and other non-critical delivery should preferably be queued.

Benefits:

- faster user response
- retry support
- isolates external mail latency

Core approval transactions must not wait for SMTP.

---

# 37. Queue Failure

If email notification fails:

```text
request remains approved
```

Do not roll back approval.

Queue failure should be:

- logged
- retryable
- operationally visible

but separate from business correctness.

---

# 38. Queue Retry

Queued listeners/notifications may execute more than once.

Therefore handlers should tolerate retry.

Avoid code like:

```text
send notification
AND
advance workflow
```

inside the same queued handler.

Workflow advancement belongs in the synchronous authoritative service.

---

# 39. Duplicate Notifications

Exactly-once notification delivery is difficult to guarantee.

V1 should prioritize:

```text
workflow correctness
```

over elaborate notification deduplication.

For reminder workflows where duplicates become annoying, introduce explicit reminder tracking.

---

# 40. Notification Preferences

User-configurable notification preferences are deferred.

V1 may use fixed required business notifications.

Examples:

- approval assignment
- changes requested
- approved
- rejected

Do not add a full notification preference system unless needed.

---

# 41. In-App Notifications

Laravel database notifications can power:

```text
Notification Bell
```

Useful fields include:

- type
- request reference
- message
- target URL
- timestamp
- read status

Do not duplicate the full business record inside notification payload.

---

# 42. Read Status

Notification read/unread status is a UI concern.

Marking a notification as read must not affect workflow state.

Example:

```text
notification read
≠
approval acknowledged
```

---

# 43. Reminder Architecture

Approval reminders are driven by runtime state.

Example:

```text
ApprovalStepInstance
status = ACTIVE

Assignment
status = PENDING
```

plus an SLA/due time.

Scheduler may detect overdue approvals.

---

# 44. SLA Reminder Flow

Future flow:

```text
Scheduler
   ↓
Find overdue active assignments
   ↓
Determine reminder threshold
   ↓
Dispatch reminder
```

Reminder processing must be idempotent.

---

# 45. Reminder Tracking

When SLA functionality is implemented, consider tracking reminder delivery.

Possible table:

```text
approval_reminders
------------------
id
approval_assignment_id
reminder_type
threshold
sent_at
```

or equivalent metadata.

This prevents sending the same reminder every scheduler run.

Do not add this table before SLA/reminders are actually implemented.

---

# 46. Overdue Does Not Change Approval State

An overdue approval remains:

```text
PENDING
```

unless escalation policy explicitly changes ownership.

Overdue is an operational condition, not an approval decision.

---

# 47. Escalation

Escalation is deferred beyond initial V1.

Future escalation may:

- notify manager
- notify admin
- reassign/delegate
- create additional escalation record

Do not implement automatic escalation by silently changing the published workflow configuration.

---

# 48. Scheduler Idempotency

Scheduler jobs may run more than once.

Example:

```text
schedule:run
```

executes again after a server restart or retry.

Repeated execution must not create uncontrolled duplicate reminders.

Check persisted reminder state before sending again.

---

# 49. Event Listener Boundaries

A listener should have one clear responsibility.

Examples:

```text
SendApprovalAssignedNotification
WriteWorkflowCompletionAudit
```

Avoid a listener that:

- sends email
- updates workflow
- recalculates totals
- modifies vendor
- generates reports

all at once.

---

# 50. AuditService

An `AuditService` may centralize business activity log creation.

Example responsibilities:

```text
logCreated()
logUpdated()
logStatusChange()
logWorkflowPublished()
```

Keep its API simple.

Do not hide approval state transitions inside AuditService.

---

# 51. Automatic Model Auditing

Avoid blindly logging every Eloquent model change globally in V1.

That approach often creates noisy or confusing audit records.

Prefer explicit audit calls around important business operations.

A future package may be considered only if there is a real need.

---

# 52. Transaction Relationship

ApprovalAction should be created inside the same transaction as the approval state change.

Example:

```text
Assignment → APPROVED
+
ApprovalAction → APPROVED
```

must commit together.

General notification dispatch should happen after commit.

---

# 53. Example — Approve

Recommended flow:

```text
ApprovalService
    ↓
Begin transaction
    ↓
Lock runtime
    ↓
Assignment → APPROVED
    ↓
Step advance
    ↓
ApprovalAction(APPROVED)
    ↓
Commit
    ↓
Dispatch next events
    ↓
Notify next approver / requester
```

---

# 54. Example — Workflow Publication

Recommended:

```text
Admin publishes Workflow Version 3
        ↓
Workflow version state changes
        ↓
ActivityLog:
WORKFLOW_VERSION_PUBLISHED
```

No ApprovalAction is required because this is configuration administration, not runtime approval activity.

---

# 55. Example — Expense Edit

Requester modifies a changes-requested claim.

ActivityLog may record important changed fields.

Then on resubmit:

```text
ApprovalAction:
RESUBMITTED
```

Both records may be useful because they answer different questions.

---

# 56. Admin Audit View

Future Admin audit screen may support filters such as:

```text
actor
action
subject type
date range
```

Do not build a complex analytics interface in V1 unless needed.

---

# 57. Approval Timeline vs Admin Audit View

Approval Timeline:

```text
business-user-friendly
specific request
```

Admin Audit View:

```text
system-wide
administrative
```

Do not use the raw system-wide ActivityLog directly as the normal requester timeline.

---

# 58. Audit Authorization

General audit logs should be restricted.

V1 recommendation:

```text
ADMIN
→ broad audit access
```

Finance may later receive scoped audit access if required.

Approval timeline follows the parent record's view authorization.

---

# 59. Notification Authorization

A notification payload must not contain enough sensitive data to bypass normal authorization.

For example, a notification may show:

```text
PR-2026-000123 requires approval.
```

Opening the record still requires server-side permission.

---

# 60. Notification Data Minimization

Avoid putting full:

- invoice details
- receipt data
- bank information
- private attachment contents

inside email or database notification payload unless needed.

Link the user to the secured application instead.

---

# 61. Error Logging

Unexpected failures should use technical application logging.

Examples:

- mail provider unavailable
- notification job fails repeatedly
- workflow event listener throws
- impossible audit state

Technical logs should include enough identifiers to troubleshoot without unnecessarily exposing confidential content.

---

# 62. Failure Isolation

Failure in:

```text
email
database notification
non-critical reporting listener
AI summary
```

must not invalidate an already committed approval decision.

Core workflow services must remain independent of secondary side effects.

---

# 63. AI Auditability

Future AI features should also be traceable when they materially assist users.

Examples:

```text
AI extracted invoice fields
AI generated approval summary
AI flagged anomaly
```

However, do not treat AI suggestions as approval actions.

If AI output influences a human decision, the human remains the actual approval actor.

---

# 64. AI Output Storage

When useful, AI results may be stored separately with metadata such as:

- model/provider
- generated_at
- result type
- user-confirmed status

Do not place large AI outputs directly into ApprovalAction metadata by default.

AI architecture is deferred to later documentation.

---

# 65. Critical Audit Tests

## Approval Action Created

Successful approval creates exactly one APPROVED action.

---

## Failed Approval

If approval transaction rolls back:

no APPROVED action remains.

---

## Request Changes

CHANGES_REQUESTED records:

- actor
- comment
- step
- timestamp

---

## Resubmission

Every successful resubmission creates a RESUBMITTED action.

---

## Historical Preservation

Previous actions remain after:

- resubmit
- rejection
- workflow replacement
- completion

---

# 66. Critical Notification Tests

## Approval Assigned

New active assignment produces notification to correct approver.

---

## Request Approved

Final approval notifies requester.

---

## Changes Requested

Requester receives approver comment.

---

## Unauthorized User

Notification link does not bypass resource authorization.

---

## Queue Failure

Simulated notification failure does not revert workflow state.

---

# 67. Activity Log Tests

Important administrative actions should generate expected ActivityLog records.

Examples:

```text
workflow published
vendor updated
role changed
```

Do not test every trivial model update if it is not an audit requirement.

---

# 68. V1 Scope

Initial V1 should include:

```text
ApprovalAction history

Request approval timeline

ActivityLog for important admin/business changes

Database notifications

Core approval assignment/status notifications
```

Email notifications may be included if mail configuration is convenient, but database notification is sufficient for the first complete workflow.

---

# 69. V1.1 Scope

After core workflow is stable:

```text
queued email notifications

SLA due dates

overdue reminders

scheduler-based reminder processing

workflow blocked alerts
```

---

# 70. Deferred Features

Do not implement initially:

- SMS
- WhatsApp
- Slack/Teams integration
- customizable notification templates
- per-user notification preferences
- complex escalation chains
- audit export engine
- immutable external audit ledger
- SIEM integration
- event sourcing

These can be added only when they solve a real requirement.

---

# 71. Core Principles

Audit and notification architecture follows:

```text
ApprovalAction
= approval-specific history

ActivityLog
= broader business/admin history

Notification
= user attention mechanism

Laravel/application log
= technical diagnostics
```

These concepts must not be confused.

Additional principles:

```text
History is append-oriented.

Core transactions create authoritative state first.

Notifications happen after committed business state.

Notification failure must not undo approval.

Important actions remain traceable.

Audit logs should be useful, not noisy.
```

---

# 72. Summary

The high-level flow is:

```text
Business Action
      ↓
Service
      ↓
Transaction
      ↓
Authoritative State Change
      +
ApprovalAction / ActivityLog
      ↓
Commit
      ↓
Domain Event
      ↓
Listener / Queue
      ↓
Database Notification / Email
```

The most important separation is:

```text
Audit
≠
Notification
```

Audit explains what happened.

Notifications help the right person act on what happens next.
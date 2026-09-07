# Workflow Runtime

## 1. Purpose

Workflow Runtime represents the actual approval process created for one submitted business record.

It applies to:

- Purchase Requests
- Supplier Invoices
- Expense Claims

Workflow Configuration defines what should happen.

Workflow Runtime records what is happening and what already happened.

These two responsibilities must remain separate.

---

# 2. Runtime Structure

The runtime hierarchy is:

```text
ApprovalInstance
    ↓
ApprovalStepInstance
    ↓
ApprovalAssignment
    ↓
ApprovalAction
```

Each level has a distinct responsibility.

---

# 3. Runtime Creation

Runtime is created only after a valid Workflow Version and Rule Group have been resolved.

High-level flow:

```text
Business Record
    ↓
WorkflowResolver
    ↓
WorkflowVersion
    ↓
Matching Rule Group
    ↓
WorkflowEngine
    ↓
Approval Runtime
```

If workflow resolution fails, no runtime records should be created.

---

# 4. ApprovalInstance

An `ApprovalInstance` represents one complete approval process for one submitted business record.

Examples:

```text
Purchase Request PR-2026-000123
→ ApprovalInstance #501
```

```text
Expense Claim EXP-2026-000077
→ ApprovalInstance #502
```

Suggested fields:

```text
id
approvable_type
approvable_id

workflow_version_id
workflow_rule_group_id

status
current_step_order nullable

started_at
completed_at nullable

created_at
updated_at
```

---

# 5. Approvable Relationship

`ApprovalInstance` uses a polymorphic relation.

Possible approvable models:

```text
PurchaseRequest
SupplierInvoice
ExpenseClaim
```

Conceptually:

```text
ApprovalInstance
    morphTo approvable
```

This allows one reusable approval engine to support all three business modules.

---

# 6. ApprovalInstance Status

Suggested statuses:

```text
IN_PROGRESS
APPROVED
REJECTED
CANCELLED
BLOCKED
```

A newly started approval process normally begins as:

```text
IN_PROGRESS
```

---

# 7. BLOCKED

`BLOCKED` means the workflow cannot currently continue because of an operational or configuration issue.

Examples:

- requester has no manager
- required department manager is missing
- no active user exists for a required role
- configured specific user became inactive

BLOCKED does not mean rejected.

It means:

```text
human/admin intervention required
```

---

# 8. One Active Approval Runtime

Normally, a submitted business record should have only one active ApprovalInstance at a time.

Possible historical states:

```text
ApprovalInstance #1
CANCELLED

ApprovalInstance #2
IN_PROGRESS
```

This may happen after material changes during resubmission.

Historical instances must be preserved.

---

# 9. ApprovalStepInstance

An `ApprovalStepInstance` represents one configured workflow step at runtime.

Suggested fields:

```text
id
approval_instance_id
workflow_step_id nullable

step_order
name

approver_type
approval_mode
required_approvals

status

started_at nullable
completed_at nullable

created_at
updated_at
```

---

# 10. Why Runtime Steps Exist

Do not rely only on the original WorkflowStep configuration row.

Runtime steps preserve important historical information.

For example:

```text
Step 2
Finance Review
ROLE
ANY
```

should remain understandable even if the original workflow configuration is later archived.

---

# 11. Runtime Step Snapshot

Important workflow information should be copied into the runtime step.

Recommended snapshot fields include:

```text
step_order
name
approver_type
approval_mode
required_approvals
```

The runtime record may still reference:

```text
workflow_step_id
```

for traceability.

But historical readability must not depend entirely on that record.

---

# 12. Step Statuses

Suggested statuses:

```text
WAITING
ACTIVE
COMPLETED
REJECTED
CANCELLED
BLOCKED
```

Typical flow:

```text
WAITING
   ↓
ACTIVE
   ↓
COMPLETED
```

Only one sequential step should normally be ACTIVE at a time in V1.

---

# 13. Creating Runtime Steps

Recommended behavior:

When the workflow starts:

```text
create all ApprovalStepInstances
```

This preserves the selected route immediately.

Example:

```text
Step 1 Manager Approval
Step 2 Finance Review
Step 3 Director Approval
```

At creation:

```text
Step 1 → ACTIVE
Step 2 → WAITING
Step 3 → WAITING
```

---

# 14. Why Create All Runtime Steps Early

Creating all step instances at submission time gives several benefits:

- selected route is frozen
- timeline is predictable
- workflow structure remains inspectable
- later workflow configuration changes cannot alter the route
- debugging is easier

Actual users do not necessarily need to be assigned to all future steps immediately.

---

# 15. ApprovalAssignment

An `ApprovalAssignment` represents one actual user assigned to act on a runtime step.

Suggested fields:

```text
id
approval_step_instance_id
approver_id

status

assigned_at
acted_at nullable

delegated_from_user_id nullable

created_at
updated_at
```

---

# 16. Assignment Statuses

Suggested statuses:

```text
PENDING
APPROVED
REJECTED
SKIPPED
DELEGATED
CANCELLED
```

A newly active assignment begins as:

```text
PENDING
```

---

# 17. Assignment Timing

Recommended V1 behavior:

```text
create step instances immediately
resolve actual approvers when each step activates
```

Do not necessarily assign every future approver at submission time.

---

# 18. Why Resolve Approvers on Activation

Suppose Step 3 uses:

```text
REQUESTER_MANAGER
```

The requester may have a different manager by the time Step 3 becomes active.

Resolving at activation time better reflects the actual organization at that moment.

What remains frozen is:

```text
the workflow strategy
```

not necessarily:

```text
the exact future human user
```

---

# 19. First Step Activation

When runtime starts:

1. locate the lowest `step_order`
2. change it from WAITING to ACTIVE
3. resolve approver(s)
4. create ApprovalAssignment records
5. set `ApprovalInstance.current_step_order`
6. record relevant action/event

If no valid approver can be resolved, runtime must not silently skip the step.

---

# 20. Active Step

Only the ACTIVE step may accept approval actions.

Example:

```text
Step 1 Manager Approval → COMPLETED
Step 2 Finance Review   → ACTIVE
Step 3 Director         → WAITING
```

A Director must not be allowed to approve Step 3 yet.

---

# 21. ApprovalAction

`ApprovalAction` is the append-oriented history of meaningful workflow actions.

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

---

# 22. ApprovalAction Types

Typical action types:

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

Not every internal state change needs its own action if it creates excessive noise.

The important requirement is that meaningful business events remain traceable.

---

# 23. Append-Oriented History

ApprovalAction history should not be edited to make the current state look cleaner.

For example:

```text
Manager Approved
Finance Requested Changes
Requester Resubmitted
Finance Approved
```

all four events should remain visible.

Do not overwrite:

```text
CHANGES_REQUESTED
```

with:

```text
APPROVED
```

---

# 24. Submission Runtime Flow

A successful submission conceptually performs:

```text
Business Record in DRAFT
        ↓
Resolve Workflow
        ↓
Create ApprovalInstance
        ↓
Create ApprovalStepInstances
        ↓
Activate First Step
        ↓
Resolve Approver
        ↓
Create Assignment
        ↓
Record SUBMITTED
        ↓
Business Record → IN_APPROVAL
```

All core state changes should occur inside one database transaction.

---

# 25. Approval Flow

For an active assignment:

```text
PENDING Assignment
        ↓
Approver approves
        ↓
Assignment → APPROVED
        ↓
Record APPROVED action
        ↓
Check whether Step is complete
```

For V1 ANY approval mode:

```text
one valid approval
→ step complete
```

---

# 26. Completing a Step

When an ACTIVE step completes:

```text
Step → COMPLETED
completed_at = now()
```

Any remaining assignments in the step that are no longer required should be marked consistently.

Recommended:

```text
SKIPPED
```

Then:

```text
find next WAITING step
```

---

# 27. Activating Next Step

If another step exists:

```text
next step
WAITING → ACTIVE
```

Then:

```text
resolve approver(s)
create assignment(s)
update current_step_order
```

The ApprovalInstance remains:

```text
IN_PROGRESS
```

---

# 28. Final Approval

If the completed step is the final step:

```text
ApprovalStepInstance → COMPLETED
ApprovalInstance → APPROVED
Business Record → APPROVED
completed_at = now()
```

Record an appropriate final action/event.

No further assignments should remain actionable.

---

# 29. Rejection

For V1, a valid rejection from the active step ends the current approval process.

Flow:

```text
Assignment → REJECTED
Step → REJECTED
ApprovalInstance → REJECTED
Business Record → REJECTED
```

Future WAITING steps become:

```text
CANCELLED
```

or another consistent non-actionable state.

Recommended convention:

```text
CANCELLED
```

for future steps,

and:

```text
SKIPPED
```

for unused assignments inside an already-active step.

---

# 30. Request Changes

`CHANGES_REQUESTED` does not terminate the approval process.

Conceptually:

```text
Active Step
    ↓
Approver requests changes
    ↓
Business Record → CHANGES_REQUESTED
    ↓
Current step pauses
```

The ApprovalInstance remains associated with the business record.

Detailed resubmission rules are documented in:

```text
workflow-resubmission.md
```

---

# 31. Pausing Current Step

When changes are requested:

the current step should remain identifiable as the point where work paused.

Possible status design:

```text
ACTIVE
```

plus request status:

```text
CHANGES_REQUESTED
```

or:

introduce a dedicated step status such as:

```text
PAUSED
```

Recommended V1:

avoid adding PAUSED unless needed.

The business record's `CHANGES_REQUESTED` state is enough to prevent approval actions until resubmission.

---

# 32. Prevent Approval During Changes

While the business record is:

```text
CHANGES_REQUESTED
```

pending assignments must not be actionable.

Server-side approval logic must reject attempts to approve during this state.

Do not rely only on hiding buttons in the UI.

---

# 33. Resumption

If workflow-relevant fields did not materially change:

```text
resubmit
→ same ApprovalInstance
→ same current Step
→ new/current assignment becomes actionable
```

Previous completed steps remain completed.

---

# 34. Material Route Change

If resubmission changes workflow-relevant data enough to require a different route:

recommended behavior:

```text
old ApprovalInstance → CANCELLED
new ApprovalInstance → IN_PROGRESS
```

The old runtime remains historical.

Do not mutate the old route into the new one.

Detailed rules belong in:

```text
workflow-resubmission.md
```

---

# 35. Withdraw

A requester may withdraw an eligible request.

Recommended flow:

```text
Business Record → WITHDRAWN
ApprovalInstance → CANCELLED
ACTIVE/WAITING Steps → CANCELLED
PENDING Assignments → CANCELLED
```

Record:

```text
WITHDRAWN
```

in ApprovalAction.

---

# 36. Delegation

Delegation is a future feature but runtime should leave room for it.

Example:

```text
John originally responsible
        ↓
delegated to Mary
```

Assignment may store:

```text
approver_id = Mary
delegated_from_user_id = John
```

This makes the runtime explainable.

---

# 37. Runtime Must Be Explainable

For every active request, the system should be able to answer:

```text
Which workflow version was used?

Which rule group matched?

Which step is active?

Who is assigned?

What already happened?

Why is the request still waiting?
```

If the runtime model cannot answer these questions easily, the design is too opaque.

---

# 38. Approval Inbox

The runtime model powers:

```text
My Approval Inbox
```

Primary source:

```text
ApprovalAssignment
```

Typical filter:

```text
approver_id = current_user
AND status = PENDING
```

plus:

```text
step is ACTIVE
business record is IN_APPROVAL
```

---

# 39. Inbox Must Use Persisted Assignments

Do not rebuild approver ownership dynamically every time the inbox loads.

Runtime assignment is the source of operational ownership.

This improves:

- performance
- predictability
- auditing
- troubleshooting

---

# 40. Timeline

The request detail page should display approval history from:

```text
ApprovalAction
```

plus relevant runtime step information.

Example:

```text
09:00 Submitted by Alice

09:01 Assigned to John

10:15 Approved by John

10:15 Finance Review activated

10:16 Assigned to Mary
```

Do not reconstruct historical timelines from the latest configuration.

---

# 41. Runtime and Notifications

Runtime changes may emit domain events.

Examples:

```text
ApprovalAssigned
ApprovalStepCompleted
RequestApproved
RequestRejected
ChangesRequested
WorkflowBlocked
```

Notifications should happen after the authoritative runtime state is safely persisted.

Notification failure must not corrupt approval state.

---

# 42. BLOCKED Runtime

If a future step activates but no valid approver can be resolved:

```text
Step → BLOCKED
ApprovalInstance → BLOCKED
```

The previous completed steps remain completed.

The system should surface:

- what step is blocked
- why it is blocked
- what administrator action is required

---

# 43. Recovering From BLOCKED

A future admin recovery flow may:

1. fix organization/configuration data
2. retry approver resolution
3. create assignment
4. set step ACTIVE
5. set ApprovalInstance IN_PROGRESS

Do not restart the whole workflow unless necessary.

---

# 44. Runtime Immutability Principle

Historical runtime data should generally not be rewritten.

Examples that should remain stable:

- completed step
- approval action
- original actor
- timestamps
- selected workflow version
- selected rule group

Current operational fields may still change, such as:

- current status
- current step
- assignment status

---

# 45. Current State vs History

Use runtime tables for current operational state.

Use ApprovalAction for historical business events.

Example:

Current:

```text
ApprovalInstance.status = IN_PROGRESS
current_step_order = 2
```

History:

```text
SUBMITTED
APPROVED by Manager
Finance assigned
```

Do not force one table to serve both purposes poorly.

---

# 46. Database Constraints

Important runtime relationships should use foreign keys.

Examples:

```text
ApprovalStepInstance
→ ApprovalInstance
```

```text
ApprovalAssignment
→ ApprovalStepInstance
```

```text
ApprovalAssignment
→ User
```

```text
ApprovalAction
→ ApprovalInstance
```

---

# 47. Suggested Indexes

Recommended indexes include:

```text
approval_instances:
(approvable_type, approvable_id)
(status)
(workflow_version_id)
```

```text
approval_step_instances:
(approval_instance_id, step_order)
(status)
```

```text
approval_assignments:
(approver_id, status)
(approval_step_instance_id, status)
```

```text
approval_actions:
(approval_instance_id, created_at)
```

---

# 48. Active Instance Lookup

The system should be able to efficiently answer:

```text
What is the current ApprovalInstance for this request?
```

Consider an index on:

```text
approvable_type
approvable_id
status
```

Exact uniqueness enforcement may depend on the final schema and database strategy.

---

# 49. Runtime Service Responsibilities

Recommended separation:

## WorkflowEngine

Responsible for:

```text
creating ApprovalInstance
creating runtime steps
activating steps
creating assignments
```

## ApproverResolver

Responsible for:

```text
resolving real users
```

## ApprovalService

Responsible for:

```text
approve
reject
request changes
resubmit
withdraw
```

Do not put every runtime responsibility into one giant service.

---

# 50. Runtime Must Not Evaluate Business Routing Again During Normal Approval

Once an ApprovalInstance has been created:

normal approve/reject operations must follow that runtime route.

Do not re-run WorkflowResolver on every approval click.

Workflow routing is re-evaluated only in explicit cases such as material resubmission.

---

# 51. Business Record Status

The business record maintains a high-level status for easy application use.

Examples:

```text
DRAFT
IN_APPROVAL
CHANGES_REQUESTED
APPROVED
REJECTED
WITHDRAWN
```

ApprovalInstance maintains approval-engine-specific runtime status.

These are related but not identical concepts.

---

# 52. Why Keep Both Statuses

Business pages should not need to interpret low-level runtime details just to display:

```text
Approved
```

At the same time, the Workflow Engine needs more precise runtime state such as:

```text
BLOCKED
current step
```

Therefore both levels are useful.

Their transitions must remain synchronized by services.

---

# 53. Failure Consistency

If runtime update fails midway:

the database transaction must roll back.

Never allow states such as:

```text
Assignment APPROVED

but Step still ACTIVE

and no ApprovalAction exists
```

or:

```text
ApprovalInstance APPROVED

but Business Record still IN_APPROVAL
```

Atomicity is required.

---

# 54. Runtime Edge Cases

The runtime architecture must safely handle:

- duplicate approval attempts
- user double-click
- inactive approver
- missing manager
- approver changes before future step
- request changes
- material resubmission
- withdrawal
- blocked workflow
- final approval
- historical workflow version

Concurrency details are documented separately.

---

# 55. Critical Runtime Tests

## Start Workflow

Submission creates:

```text
1 ApprovalInstance
expected ApprovalStepInstances
first active step
expected assignment
SUBMITTED action
```

---

## Sequential Activation

Manager approves.

Expected:

```text
Manager Step → COMPLETED
Finance Step → ACTIVE
Finance Assignment → PENDING
```

---

## Future Step Protection

Director cannot approve while Director step is WAITING.

---

## Final Approval

Final step approval produces:

```text
ApprovalInstance → APPROVED
Business Record → APPROVED
```

---

## Rejection

Active approver rejects.

Expected:

```text
Step → REJECTED
ApprovalInstance → REJECTED
Business Record → REJECTED
future steps → CANCELLED
```

---

## Changes Requested

Finance requests changes.

Expected:

```text
Business Record → CHANGES_REQUESTED
previous completed steps preserved
runtime history preserved
```

---

## Withdraw

Requester withdraws.

Expected:

```text
ApprovalInstance → CANCELLED
Business Record → WITHDRAWN
pending runtime work no longer actionable
```

---

## Blocked Step

Required approver cannot be resolved.

Expected:

```text
Step → BLOCKED
ApprovalInstance → BLOCKED
no silent skip
```

---

# 56. V1 Runtime Scope

V1 runtime supports:

- one ApprovalInstance per active approval process
- sequential runtime steps
- runtime step snapshots
- actual user assignments
- ANY approval mode
- approve
- reject
- request changes
- resubmit
- withdraw
- blocked state
- approval history
- approval inbox

---

# 57. Deferred Runtime Features

Do not implement initially:

- parallel branches
- multiple simultaneously active steps
- ALL approvals
- MINIMUM approvals
- weighted votes
- dynamic step insertion
- step reordering during runtime
- arbitrary administrator force-complete
- cross-workflow dependencies

These features may be added later if required.

---

# 58. Summary

Workflow Runtime follows this structure:

```text
Resolved Workflow
      ↓
ApprovalInstance
      ↓
ApprovalStepInstances
      ↓
Activate Current Step
      ↓
ApprovalAssignments
      ↓
Human Action
      ↓
ApprovalAction
      ↓
Advance / Complete / Reject / Pause
```

Key principles:

```text
Runtime preserves what actually happened.

The selected route is frozen.

Future approvers may be resolved when their step activates.

Only the active step may be acted on.

Approval history is append-oriented.

Runtime state changes are transactional.

Historical runtime is never rewritten to match newer workflow configuration.
```

The runtime model should make every request easy to explain, audit, and continue safely.
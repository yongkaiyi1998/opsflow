# Workflow Concurrency

## 1. Purpose

Approval workflows are sensitive to concurrent actions.

Examples:

- user double-clicks Submit
- approver double-clicks Approve
- two browser tabs approve the same assignment
- two Finance users act on the same ANY-approval step
- requester submits while another update is still being saved
- duplicate queue jobs process the same workflow state
- resubmission request is sent twice

The system must ensure that repeated or concurrent actions cannot:

- advance a workflow twice
- create duplicate ApprovalInstances
- create duplicate ApprovalActions
- create duplicate assignments
- overwrite newer business state
- leave runtime records inconsistent

---

# 2. Core Principle

Do not rely on the browser to prevent duplicate actions.

UI protections such as:

```text
disable button after click
```

are useful for user experience, but they are not correctness guarantees.

Correctness must be enforced by:

```text
Database Transactions
+
Row Locking
+
State Validation
+
Database Constraints
```

---

# 3. General Transaction Pattern

Concurrency-sensitive operations should follow this pattern:

```text
Begin Transaction
      ↓
Lock authoritative record
      ↓
Re-read persisted state
      ↓
Validate transition is still allowed
      ↓
Apply changes
      ↓
Create history/runtime records
      ↓
Commit
```

Never validate important state only before obtaining the lock.

---

# 4. Why Re-Check After Locking

Consider:

```text
Request A reads assignment = PENDING

Request B reads assignment = PENDING
```

Both believe approval is allowed.

Without locking:

```text
A approves
B approves
```

Both may advance the workflow.

Correct flow:

```text
A locks assignment
A sees PENDING
A approves
A commits

B obtains lock afterwards
B now sees APPROVED
B stops
```

Only the first operation changes state.

---

# 5. Laravel Transaction Boundary

Use Laravel database transactions for atomic workflows.

Conceptually:

```php
DB::transaction(function () {
    // lock
    // validate current state
    // apply transition
    // persist related runtime changes
});
```

Do not manually scatter partial writes before starting the transaction.

---

# 6. Lock Authoritative State

The record being acted on should normally be locked.

Examples:

## Submit

Lock:

```text
Business Record
```

because the authoritative question is:

```text
Is this still DRAFT and eligible to submit?
```

---

## Approve

Lock:

```text
ApprovalInstance
ApprovalStepInstance
ApprovalAssignment
```

because these determine whether the assignment is still active and actionable.

---

## Resubmit

Lock:

```text
Business Record
Current ApprovalInstance
```

because the operation may resume or replace the runtime.

---

# 7. Recommended Lock Order

All concurrency-sensitive workflow operations should follow one consistent lock order.

Recommended order:

```text
Business Record
      ↓
ApprovalInstance
      ↓
ApprovalStepInstance
      ↓
ApprovalAssignment
```

When a particular record type is not needed, skip it.

The important rule is:

```text
Do not acquire the same record types in different orders
across different workflow operations.
```

Consistent lock ordering reduces deadlock risk.

---

# 8. Double Submit

Scenario:

A user double-clicks:

```text
Submit
```

Two HTTP requests reach the server.

Both initially see:

```text
status = DRAFT
```

Expected result:

```text
one submission succeeds
one submission fails safely
one active ApprovalInstance exists
```

---

# 9. Safe Submit Flow

Recommended:

```text
Begin Transaction
      ↓
Lock Business Record
      ↓
Verify status = DRAFT
      ↓
Verify no active ApprovalInstance exists
      ↓
Resolve Workflow
      ↓
Create ApprovalInstance
      ↓
Create Runtime Steps
      ↓
Activate First Step
      ↓
Create Assignment
      ↓
Business Record → IN_APPROVAL
      ↓
Commit
```

The second request should eventually observe:

```text
status != DRAFT
```

and stop.

---

# 10. Duplicate Active ApprovalInstance

Application checks are not enough.

Where practical, the database design should also make duplicate active approval processes difficult or impossible.

At minimum:

- query for existing active instance while holding the business record lock
- enforce consistent lifecycle rules
- add appropriate indexes

If the final schema permits a safe database-level uniqueness mechanism, prefer it.

Do not create complicated database hacks solely to simulate partial unique indexes if they reduce clarity.

---

# 11. Double Approve

Scenario:

```text
Approver clicks Approve twice
```

or:

```text
same assignment is open in two browser tabs
```

Expected:

```text
first approval succeeds
second approval does not create another state change
```

---

# 12. Safe Approve Flow

Conceptually:

```text
Begin Transaction
      ↓
Lock Business Record
      ↓
Lock ApprovalInstance
      ↓
Lock Active Step
      ↓
Lock Assignment
      ↓
Verify:
- business record is IN_APPROVAL
- instance is IN_PROGRESS
- step is ACTIVE
- assignment is PENDING
- user owns assignment
      ↓
Assignment → APPROVED
      ↓
Create APPROVED action
      ↓
Evaluate step completion
      ↓
Activate next step or complete workflow
      ↓
Commit
```

The second request must re-check persisted state after acquiring locks.

---

# 13. ANY Approval Race

A ROLE step may assign multiple Finance users.

Example:

```text
Finance Review
approval_mode = ANY

Mary → PENDING
John → PENDING
```

Mary and John approve at almost the same time.

Only one approval should complete the step.

---

# 14. Safe ANY Approval Handling

Both requests must serialize around the shared:

```text
ApprovalStepInstance
```

Therefore locking only each separate assignment is not enough.

Recommended:

```text
lock ApprovalStepInstance first
then lock acting ApprovalAssignment
```

Example:

```text
Mary locks Step
Mary approves
Step → COMPLETED
John later locks Step
John now sees COMPLETED
John cannot approve
```

This prevents two users from completing the same ANY step independently.

---

# 15. Shared Parent Lock Principle

When multiple child records can cause one shared parent state transition:

```text
lock the shared parent
```

Example:

```text
multiple ApprovalAssignments
        ↓
one ApprovalStepInstance
```

The Step is the synchronization point.

---

# 16. Final Approval Race

The last step completes the entire workflow.

The operation may update:

```text
ApprovalAssignment
ApprovalStepInstance
ApprovalInstance
Business Record
```

All of these updates must succeed or fail together.

Never allow:

```text
Assignment = APPROVED
ApprovalInstance = APPROVED
Business Record = IN_APPROVAL
```

The transaction must preserve consistency.

---

# 17. Reject vs Approve Race

Scenario:

Two valid users in an ANY step act simultaneously.

Mary:

```text
Approve
```

John:

```text
Reject
```

V1 should use:

```text
first committed valid action wins
```

After the first action completes the step or workflow, the second operation observes the new state and fails safely.

Do not attempt to merge simultaneous conflicting decisions.

---

# 18. Request Changes vs Approve Race

The same rule applies.

If:

```text
Approve
```

and:

```text
Request Changes
```

arrive concurrently:

the operation that obtains the relevant step lock first and successfully changes state wins.

The other action must re-check state and stop.

---

# 19. Resubmission Race

Scenario:

Requester double-clicks Resubmit.

Expected:

```text
one resubmission succeeds
one fails safely
```

The system must not:

- create two new ApprovalInstances
- create two resumed assignments
- record duplicate RESUBMITTED actions

---

# 20. Safe Resubmission Flow

Conceptually:

```text
Begin Transaction
      ↓
Lock Business Record
      ↓
Verify CHANGES_REQUESTED
      ↓
Lock current ApprovalInstance
      ↓
Build current WorkflowContext
      ↓
Resolve/compare route
      ↓
Resume current runtime
OR
Create replacement runtime
      ↓
Business Record → IN_APPROVAL
      ↓
Record RESUBMITTED
      ↓
Commit
```

A second request should then observe:

```text
status != CHANGES_REQUESTED
```

and stop.

---

# 21. Withdraw Race

Possible conflict:

```text
Requester withdraws
```

while:

```text
Approver approves
```

These operations must not both succeed.

Both should lock the same authoritative workflow/business records in the same order.

First valid committed transition wins.

---

# 22. Stale Browser State

The UI may show:

```text
Approve
```

even though another user already completed the step.

This is normal in distributed web applications.

Server behavior must be:

```text
reject stale action safely
```

Recommended user message:

```text
This approval has already been processed or is no longer actionable.
```

Do not treat this as a server error.

---

# 23. Optimistic Concurrency for Editing

Not every edit requires pessimistic row locking throughout the entire user interaction.

For draft/business record editing, optimistic concurrency may be appropriate.

Example:

```text
User A opens request at updated_at = 10:00

User B edits at 10:05

User A submits old form at 10:10
```

The system should detect that A is editing a stale version.

---

# 24. Suggested Optimistic Strategy

Send an original version marker such as:

```text
updated_at
```

with the form.

On update:

```text
compare submitted original timestamp
with current persisted timestamp
```

If changed:

```text
reject update
ask user to reload
```

Do not silently overwrite newer data.

---

# 25. When to Use Pessimistic Locking

Use database row locking for short critical transitions such as:

- submit
- approve
- reject
- request changes
- resubmit
- withdraw
- activate next approval step

These operations are short and state-sensitive.

---

# 26. When Not to Hold Locks

Do not hold database locks while:

- rendering pages
- waiting for user input
- uploading large files
- calling external AI providers
- sending email
- performing slow network requests

Transactions should remain short.

---

# 27. External Side Effects

Never make transaction correctness depend on:

- email delivery
- notification delivery
- AI provider
- webhook
- slow external API

Preferred flow:

```text
Commit authoritative business state
        ↓
Dispatch side effects
```

Where Laravel events/jobs require committed data, use after-commit behavior.

---

# 28. Notifications and Duplicate Delivery

Queue jobs may be retried.

Therefore notification-related logic should tolerate repeated execution.

The core approval workflow must not depend on notification exactly-once delivery.

Where duplicate notifications matter, use suitable deduplication or state checks.

Do not weaken core workflow correctness just to solve notification duplication.

---

# 29. Scheduler Idempotency

Scheduled tasks such as:

```text
overdue approval reminder
```

may run repeatedly.

The scheduler must not create uncontrolled duplicate actions.

Example strategy:

store or derive whether a reminder for a particular threshold has already been sent.

Future SLA implementation should define this explicitly.

---

# 30. ApprovalAction Duplication

A state-changing action should correspond to one successful transition.

Do not create:

```text
APPROVED
APPROVED
```

for one assignment due to retry.

Create the ApprovalAction inside the same transaction as the state transition.

If the transition fails or rolls back:

the action must also roll back.

---

# 31. State Check Before Action Creation

Bad:

```text
create APPROVED action
↓
attempt to update assignment
↓
discover assignment already processed
```

Good:

```text
lock
↓
validate current state
↓
change state
↓
create action
↓
commit
```

History should describe successful authoritative transitions.

---

# 32. Idempotency vs Validation

Not every endpoint needs a dedicated idempotency key.

For internal web approval actions, state-based idempotency is usually sufficient:

```text
PENDING → APPROVED
```

A repeated call sees:

```text
APPROVED
```

and performs no second transition.

Future external APIs/webhooks may require explicit idempotency keys.

---

# 33. Database Constraints

Database constraints should protect invariants where practical.

Examples:

```text
UNIQUE workflow version number per template
```

```text
UNIQUE step order per rule group
```

Potential runtime constraints should be considered for:

- duplicate assignments to same user in same step
- duplicate request identifiers
- duplicate supplier invoice references

Do not rely solely on application-level `exists()` checks for race-sensitive uniqueness.

---

# 34. Unique Validation Race

This pattern is unsafe by itself:

```text
check if invoice number exists
↓
not found
↓
insert
```

Two concurrent requests may both pass the check.

Use a database unique constraint as the final guarantee.

Application validation still provides friendly error messages.

---

# 35. Deadlocks

Deadlocks are possible whenever multiple transactions lock several rows.

Reduce risk by:

- consistent lock order
- short transactions
- small critical sections
- avoiding unnecessary row locks
- avoiding external calls inside transactions

A deadlock is not necessarily evidence of bad code, but frequent deadlocks indicate the locking design should be reviewed.

---

# 36. Deadlock Retry

Laravel/database infrastructure may permit retrying transactions that fail due to deadlock.

If retry is used:

the operation must be safe to execute again.

This reinforces the importance of:

```text
state validation
+
transactional action creation
```

Do not blindly retry non-idempotent external side effects.

---

# 37. Lock Only What Is Needed

Avoid:

```text
locking entire collections
locking every step in a workflow
locking unrelated requests
```

Prefer the smallest set of rows needed to protect the transition.

For example, approving one active step generally does not require locking every historical ApprovalAction.

---

# 38. Query Before Lock vs Locked Query

Data used only for display may be read normally.

Data used to authorize an authoritative state transition must be verified under the transaction/locking strategy.

Do not trust stale model instances loaded long before the transaction.

Prefer re-querying the authoritative records inside the transaction.

---

# 39. Authorization and Concurrency

Authorization must still be checked.

However, runtime ownership may change.

Example:

```text
user opened approval page
```

then assignment was delegated before user clicked Approve.

When acting:

re-check the current assignment ownership.

Do not assume access is still valid because the page was previously rendered.

---

# 40. Locking and Policies

A Policy may determine broad permission to attempt an action.

The service must still verify authoritative runtime state after locking.

Think of it as:

```text
Policy:
May this user attempt this action?

Service:
Is this action still valid right now?
```

Both are required.

---

# 41. Service Responsibility

Concurrency-sensitive business transitions belong in Services.

Examples:

```text
ApprovalService
SubmissionService
ResubmissionService
```

Do not duplicate lock/state logic across multiple Controllers.

Otherwise different endpoints may use inconsistent lock orders or transition rules.

---

# 42. Recommended ApprovalService Pattern

Conceptually:

```text
approve(user, assignmentId)
      ↓
transaction
      ↓
load + lock required records
      ↓
validate actor
      ↓
validate runtime states
      ↓
apply one transition
      ↓
record history
      ↓
advance workflow if needed
      ↓
commit
```

One service operation should own the full transition.

---

# 43. Do Not Nest Unclear Transactions

Avoid deeply nested service calls where every service independently starts a transaction.

This makes transaction boundaries hard to reason about.

Prefer:

```text
top-level business operation owns transaction
```

and internal helper methods participate in that transaction.

---

# 44. Long Running Work

If a transition needs expensive secondary work:

```text
approve
↓
generate report
↓
send email
↓
AI summary
```

only the authoritative transition should remain inside the critical transaction.

Example:

```text
transaction:
approve + advance workflow

after commit:
notification + report + AI work
```

---

# 45. Transaction Failure

If any authoritative write fails:

rollback all related authoritative writes.

Example:

```text
Assignment APPROVED
Step COMPLETED
Next Assignment creation fails
```

Expected:

```text
everything rolls back
```

The current workflow remains in its previous consistent state.

---

# 46. Do Not Swallow Transaction Exceptions

Do not catch broad exceptions inside the transaction and continue as if successful.

If an authoritative state transition cannot complete:

let the transaction fail.

Handle/log the error at the appropriate boundary.

---

# 47. User-Facing Conflict Handling

Concurrency conflicts are usually normal business conflicts, not 500 errors.

Examples:

```text
This request has already been submitted.
```

```text
This approval has already been processed.
```

```text
This request changed while you were editing it. Reload and try again.
```

```text
This request is no longer available for resubmission.
```

The UI should recover gracefully.

---

# 48. Logging

Log unexpected concurrency failures such as:

- deadlocks after retry exhaustion
- impossible state combinations
- multiple active instances detected
- assignment state inconsistent with active step
- duplicate database constraint violation that indicates a workflow bug

Do not log routine stale-action conflicts as severe system failures unless they indicate unusual frequency.

---

# 49. Invariant Checks

Important runtime invariants include:

```text
one sequential active step per active ApprovalInstance
```

```text
only PENDING assignment may be approved/rejected
```

```text
only ACTIVE step may accept actions
```

```text
APPROVED ApprovalInstance must not have actionable assignments
```

```text
only one active ApprovalInstance should exist for one business record
```

```text
Business Record status and ApprovalInstance status must remain compatible
```

Services and tests should protect these invariants.

---

# 50. Example Compatible States

Valid:

```text
Business Record = IN_APPROVAL
ApprovalInstance = IN_PROGRESS
```

Valid:

```text
Business Record = APPROVED
ApprovalInstance = APPROVED
```

Valid:

```text
Business Record = CHANGES_REQUESTED
ApprovalInstance = IN_PROGRESS
```

Invalid:

```text
Business Record = APPROVED
ApprovalInstance = IN_PROGRESS
```

unless a future explicitly documented transitional model requires it.

---

# 51. Testing — Double Submit

Send two submission attempts for the same draft.

Expected:

```text
one ApprovalInstance
one SUBMITTED transition
business record IN_APPROVAL
```

Second attempt fails safely.

---

# 52. Testing — Double Approve

Approve the same assignment twice.

Expected:

```text
one APPROVED ApprovalAction
one workflow advancement
```

Second attempt does not alter state.

---

# 53. Testing — ANY Step Race

Create two PENDING Finance assignments in one ANY step.

Simulate competing actions.

Expected:

```text
one action completes step
other assignment becomes non-actionable
```

The workflow advances once.

---

# 54. Testing — Approve vs Reject

Competing approve/reject attempts.

Expected:

```text
one committed transition wins
```

The other operation observes the updated state and fails safely.

---

# 55. Testing — Double Resubmit

Two resubmit attempts occur.

Expected:

```text
one successful resubmission
one active runtime only
no duplicate assignment
no duplicate RESUBMITTED transition
```

---

# 56. Testing — Withdraw vs Approve

Concurrent:

```text
withdraw
approve
```

Expected:

```text
only one valid final transition succeeds
```

No inconsistent mixed state.

---

# 57. Testing — Stale Edit

User A loads record.

User B updates record.

User A saves old version.

Expected:

```text
stale update rejected
```

User A is asked to reload.

---

# 58. Testing — Transaction Rollback

Force failure after assignment update but before next-step creation.

Expected:

```text
assignment remains previous state
step remains previous state
workflow does not partially advance
```

---

# 59. Testing — Database Uniqueness Race

Where a uniqueness invariant matters, verify the database constraint protects it even when application-level validation is bypassed or races.

---

# 60. Testing Real Concurrency

Normal sequential Feature Tests are not sufficient to prove all real race conditions.

For critical paths, add focused concurrency/integration tests where practical.

If the test environment cannot reliably simulate true parallel transactions:

- test locking/state logic directly
- test invariants
- document the limitation
- avoid pretending a sequential test proves parallel correctness

---

# 61. V1 Concurrency Scope

V1 must protect at least:

```text
Submit
Approve
Reject
Request Changes
Resubmit
Withdraw
Step activation
Final workflow completion
```

It must also protect database uniqueness invariants relevant to the business domains.

---

# 62. Deferred Concurrency Features

Do not implement initially:

- distributed locks
- Redis locks
- cross-service sagas
- global workflow mutexes
- event-sourced conflict resolution
- distributed consensus

The Laravel monolith and MySQL transactions are sufficient for V1.

---

# 63. Summary

Concurrency-sensitive workflow operations follow:

```text
Start Transaction
      ↓
Lock Authoritative Records
      ↓
Re-check Current State
      ↓
Validate Actor + Transition
      ↓
Apply One Atomic State Change
      ↓
Record History
      ↓
Advance Runtime if Required
      ↓
Commit
```

Core principles:

```text
The database is authoritative.

UI button disabling is not concurrency control.

State must be re-checked after locking.

Shared parent state requires shared parent locking.

Transactions must remain short.

External side effects happen after authoritative state is committed.

Repeated actions must not advance workflow twice.

Consistent lock order reduces deadlock risk.
```

The objective is not to eliminate every possible concurrent request.

The objective is to make concurrent and repeated requests safe.
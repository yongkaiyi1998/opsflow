# Workflow Resubmission

## 1. Purpose

This document defines how OpsFlow handles:

- request changes
- requester edits
- resubmission
- workflow re-evaluation
- reuse of previous approvals
- creation of a new ApprovalInstance when routing changes

The goal is to reduce unnecessary repeated approval while preserving correctness.

---

# 2. Core Principle

`CHANGES_REQUESTED` is not the same as `REJECTED`.

A request with `CHANGES_REQUESTED` may continue after the requester corrects or adds information.

Example:

```text
Manager approved
      ↓
Finance requests changes
      ↓
Requester edits
      ↓
Resubmit
      ↓
Finance continues
```

Previous valid approvals should not be repeated unnecessarily.

However, previous approvals must not be reused when the business meaning of the request has changed materially.

---

# 3. Main Resubmission Rule

On resubmission, the system must determine whether workflow-relevant data changed.

Two outcomes are possible:

```text
Non-material change
→ continue existing ApprovalInstance
```

```text
Material workflow change
→ cancel existing ApprovalInstance
→ resolve workflow again
→ create new ApprovalInstance
```

---

# 4. Non-Material Changes

Examples of changes that normally do not affect routing:

- add missing receipt
- upload another attachment
- clarify description
- correct spelling
- provide supporting comments
- add internal notes

Example:

```text
Original:
Hotel expense RM380
Missing receipt

Finance:
Request Changes

Requester:
Uploads receipt

Resubmit
```

The approval route has not changed.

The request resumes from the step that requested changes.

---

# 5. Workflow-Relevant Changes

V1 considers these routing-relevant fields:

```text
amount
department
category
```

If any of these change, workflow routing must be re-evaluated.

Examples:

```text
RM900 → RM12,000
```

```text
HR → IT
```

```text
OFFICE_SUPPLIES → SOFTWARE
```

These changes may result in a different approval route.

---

# 6. Module-Specific Routing Values

Each business module maps its own data into `WorkflowContext`.

For example:

## Purchase Request

```text
amount
department_id
category_id
```

## Supplier Invoice

```text
amount
department_id
category_id
```

## Expense Claim

```text
amount
department_id
category_id
```

The resubmission logic should compare normalized workflow context rather than arbitrary model columns.

---

# 7. Store Submission Context

When an ApprovalInstance begins, store enough information to later determine whether workflow-relevant values changed.

Recommended approach:

store a small routing snapshot on the ApprovalInstance.

Example:

```json
{
  "amount": "900.00",
  "department_id": 4,
  "category_id": 7
}
```

Possible field:

```text
workflow_context_snapshot JSON
```

This snapshot is not the complete business record.

It contains only values relevant to workflow routing.

---

# 8. Why Store a Snapshot

Without a snapshot, resubmission logic may need to reconstruct historical values from:

- audit logs
- old model versions
- approval actions
- current configuration

That is unnecessarily difficult.

A routing snapshot makes the decision explicit:

```text
original routing context
vs
current routing context
```

---

# 9. Comparing Context

On resubmit:

```text
Build current WorkflowContext
        ↓
Compare with ApprovalInstance.workflow_context_snapshot
```

If relevant values are unchanged:

```text
resume existing runtime
```

If they changed:

```text
run WorkflowResolver again
```

---

# 10. Routing Change Does Not Always Mean New Runtime

A workflow-relevant field may change but still resolve to the same route.

Example:

```text
Original amount:
RM5,000

Updated amount:
RM5,500
```

Both may resolve to:

```text
Manager
→ Finance
```

Therefore the system should not automatically restart simply because a routing field changed.

Instead:

```text
workflow-relevant data changed
        ↓
re-resolve workflow
        ↓
compare resulting route
```

---

# 11. Route Comparison

The system should compare the new resolved workflow with the current runtime route.

Important comparison points include:

- Workflow Version
- selected Rule Group
- ordered approval step definitions

If the effective approval route is materially the same, the system may continue the existing ApprovalInstance.

If the route differs materially, create a new ApprovalInstance.

---

# 12. Safe V1 Recommendation

For V1, use a conservative rule:

```text
If workflow-relevant data changed
AND
the newly resolved Workflow Version or Rule Group differs
→ restart approval
```

If the same Workflow Version and Rule Group still apply:

```text
resume current approval step
```

This keeps implementation understandable.

---

# 13. Example — Same Route

Original:

```text
Amount = RM5,000
Department = IT
Category = SOFTWARE

Matched:
Medium Value IT
```

Finance requests changes.

Requester changes:

```text
Amount = RM5,500
```

Resolver still returns:

```text
same Workflow Version
same Rule Group
```

Then:

```text
resume Finance step
```

Manager approval remains valid.

---

# 14. Example — Different Route

Original:

```text
Amount = RM900
```

Route:

```text
Manager
```

Manager approves.

Requester later changes:

```text
Amount = RM15,000
```

New route:

```text
Manager
→ Finance
→ Director
```

Previous approval occurred under a materially different request.

Therefore:

```text
old ApprovalInstance → CANCELLED
new ApprovalInstance → IN_PROGRESS
approval restarts from Step 1
```

---

# 15. Why Restart From Step 1

The system should not assume earlier approval remains valid after a material route change.

Example:

Manager originally approved:

```text
RM900
```

That does not automatically mean the Manager approved:

```text
RM15,000
```

Restarting from Step 1 is simpler, safer, and easier to audit.

---

# 16. Do Not Merge Runtime Routes

Avoid trying to transform:

```text
Manager
→ Finance
```

into:

```text
Manager
→ Finance
→ Director
```

inside the same ApprovalInstance.

This creates difficult questions:

- which approvals remain valid?
- which new steps are inserted?
- what happens to step order?
- how is history explained?
- what if a step disappeared?

Recommended V1 behavior:

```text
preserve old runtime
create new runtime
```

---

# 17. Old ApprovalInstance

When a materially changed request requires a new route:

the old ApprovalInstance should become:

```text
CANCELLED
```

It remains stored permanently.

Recommended metadata or action should explain:

```text
Cancelled because resubmission changed workflow routing.
```

Do not delete it.

---

# 18. New ApprovalInstance

After cancellation:

```text
current business record
        ↓
WorkflowResolver
        ↓
new route
        ↓
WorkflowEngine
        ↓
new ApprovalInstance
```

The new instance receives a fresh workflow context snapshot.

---

# 19. Linking Old and New Instances

For traceability, consider storing:

```text
supersedes_approval_instance_id nullable
```

on ApprovalInstance.

Example:

```text
ApprovalInstance #102
supersedes #98
```

Alternative:

store linkage in ApprovalAction metadata.

Either approach is acceptable.

The relationship must remain explainable.

---

# 20. Resubmission Action

Every resubmission should create an `ApprovalAction`.

Example:

```text
RESUBMITTED
```

Recommended metadata:

```json
{
  "routing_changed": false
}
```

or:

```json
{
  "routing_changed": true,
  "previous_approval_instance_id": 98,
  "new_approval_instance_id": 102
}
```

---

# 21. Request Changes Action

When an approver requests changes, record:

```text
CHANGES_REQUESTED
```

with:

- actor
- current step
- comment
- timestamp

The comment should normally be required.

Example:

```text
Please attach the hotel receipt.
```

This tells the requester exactly what needs correction.

---

# 22. Who May Edit

While a request is `CHANGES_REQUESTED`, only authorized users may modify it.

Normally:

```text
original requester
```

or another explicitly authorized owner.

Approvers should not directly edit requester-owned financial data unless a future requirement allows it.

---

# 23. Editable Fields

Not every field must remain editable during changes.

Module documentation should define editable fields.

Example Purchase Request:

May edit:

- description
- line items
- category
- attachment
- needed-by date

Should not edit:

- requester identity
- request number
- approval history
- submitted timestamps directly

---

# 24. Recalculate Financial Values

If editable line items change:

backend totals must be recalculated before workflow comparison.

Example:

```text
Item changed
        ↓
recalculate subtotal
        ↓
recalculate tax
        ↓
recalculate total
        ↓
build new WorkflowContext
```

Do not compare routing using stale totals.

---

# 25. Resubmission Validation

Before resubmission:

- record must be `CHANGES_REQUESTED`
- user must be authorized
- required fields must be valid
- required attachments must be present
- backend totals must be recalculated
- workflow context must be rebuilt

Only then should routing comparison occur.

---

# 26. Resume Existing ApprovalInstance

If the existing route remains valid:

```text
Business Record → IN_APPROVAL
ApprovalInstance → IN_PROGRESS
```

The step that requested changes becomes active again.

Previous completed steps remain completed.

---

# 27. Assignment Handling on Resume

Suppose Finance requested changes.

Before changes:

```text
Finance Assignment
→ PENDING
```

After request changes, the assignment should no longer be actionable.

On resubmit, recommended V1 approach:

create a new assignment for the resumed step rather than reusing the old assignment blindly.

This provides a clear operational history.

Example:

```text
Old Finance assignment
→ CANCELLED

New Finance assignment
→ PENDING
```

Both remain historical records.

---

# 28. Why Create a Fresh Assignment

A fresh assignment makes it clear that:

```text
the requester has responded
and the approver must review again
```

It also gives a new:

```text
assigned_at
```

timestamp for SLA and inbox ordering.

---

# 29. Step Status on Request Changes

Recommended V1 behavior:

when changes are requested:

```text
current step remains ACTIVE logically
```

but no assignment remains actionable.

The business record status prevents approval actions.

On resubmit:

```text
create fresh PENDING assignment
```

for the same step.

This avoids introducing a separate PAUSED step status.

---

# 30. Previous Step History

Completed steps must remain unchanged when resuming the same route.

Example:

```text
Manager → COMPLETED
Finance → changes requested
```

After resubmission:

```text
Manager → still COMPLETED
Finance → ACTIVE
```

Do not recreate Manager approval.

---

# 31. Comments and Supporting Evidence

Requester may optionally provide a resubmission comment.

Example:

```text
Attached the missing receipt and corrected the hotel amount.
```

This should be recorded in the RESUBMITTED action.

It improves audit clarity.

---

# 32. Reject After Resubmission

After resubmission, the active approver may still:

- approve
- reject
- request changes again

Repeated change cycles are allowed.

Example:

```text
Finance requests changes
→ requester resubmits
→ Finance requests changes again
```

History must preserve every cycle.

---

# 33. Multiple Resubmissions

Do not overwrite previous change requests.

Timeline may show:

```text
Finance requested changes
Requester resubmitted
Finance requested changes
Requester resubmitted
Finance approved
```

This is expected historical behavior.

---

# 34. Workflow Version Changed While Waiting

Suppose:

```text
Request uses Workflow Version 2
```

while requester is correcting data, Admin publishes Version 3.

On resubmission with no workflow-relevant change:

```text
continue Version 2
```

Do not migrate to Version 3 automatically.

---

# 35. Workflow-Relevant Change and New Published Version

If routing-relevant data changed and workflow must be re-resolved:

the resolver should use the currently active published version.

Example:

```text
Old runtime:
Version 2

Admin publishes:
Version 3

Requester changes amount materially
```

On resubmission:

```text
resolve using Version 3
```

If a new ApprovalInstance is required, it uses the current valid workflow definition.

---

# 36. Why This Is Reasonable

The old approval was created for:

```text
old business facts
+
old policy
```

A materially changed request is effectively a new approval decision.

Therefore using the current policy is appropriate.

---

# 37. Non-Routing Change and New Version

If requester only adds:

```text
receipt
comment
supporting document
```

then the existing runtime continues.

It does not move to the newest Workflow Version.

This preserves workflow stability.

---

# 38. Category Change Example

Original:

```text
Category = OFFICE_SUPPLIES
Amount = RM2,000
```

Route:

```text
Manager
```

Requester changes category to:

```text
SOFTWARE
```

New route may be:

```text
Manager
→ IT Review
→ Finance
```

Result:

```text
restart approval
```

---

# 39. Department Change Example

Original:

```text
Department = SALES
```

After correction:

```text
Department = IT
```

If department changes the route or approvers:

```text
restart approval
```

Do not keep an approval from a manager who is no longer relevant to the request.

---

# 40. Amount Change Example

Original:

```text
RM4,900
```

Threshold:

```text
RM5,000
```

Requester changes to:

```text
RM5,100
```

The new route may require Finance.

Therefore exact threshold boundary tests are important.

---

# 41. Self-Approval After Change

A routing change may produce a new approver that equals the requester.

The same self-approval protections apply during resubmission.

Do not bypass them because the request already had prior approvals.

---

# 42. Missing Approver After Change

If the new route requires:

```text
Department Manager
```

but none exists:

resubmission must fail safely.

Do not cancel the old runtime until the new route has been validated enough to start successfully.

---

# 43. Safe Restart Transaction

Recommended restart flow:

```text
Begin Transaction
↓
lock business record
↓
verify CHANGES_REQUESTED
↓
recalculate data
↓
build new WorkflowContext
↓
resolve new workflow
↓
validate first approver can be resolved
↓
cancel old ApprovalInstance
↓
create new ApprovalInstance
↓
create runtime steps
↓
activate first step
↓
update business record
↓
record RESUBMITTED
↓
Commit
```

If any step fails:

rollback.

The old runtime remains intact.

---

# 44. Do Not Cancel Too Early

Bad flow:

```text
cancel old workflow
↓
try to resolve new workflow
↓
resolution fails
```

Now the request has no usable approval process.

Therefore resolve and validate first, then cancel/start atomically.

---

# 45. Existing Route Resume Transaction

Recommended flow:

```text
Begin Transaction
↓
lock business record
↓
verify CHANGES_REQUESTED
↓
validate updated data
↓
recalculate totals
↓
compare workflow context
↓
confirm same route
↓
cancel previous pending assignment if needed
↓
create fresh assignment
↓
business record → IN_APPROVAL
↓
record RESUBMITTED
↓
Commit
```

---

# 46. Concurrency

Resubmission must be protected against duplicate requests.

Example:

user double-clicks Resubmit.

Only one operation should succeed.

Use appropriate transaction and locking strategy.

Detailed locking rules belong in:

```text
workflow-concurrency.md
```

---

# 47. Idempotency

Repeated resubmission must not:

- create multiple active ApprovalInstances
- create duplicate assignments
- record multiple state-changing resubmission actions
- restart workflow multiple times

Current persisted state must always be checked inside the transaction.

---

# 48. UI Behavior

When status is:

```text
CHANGES_REQUESTED
```

the requester should see:

- who requested changes
- the comment
- which step requested changes
- what fields may be edited
- attachments
- Resubmit button

The user should not need to search the approval timeline manually to understand what to fix.

---

# 49. Approver UI

After resubmission, the approver should clearly see:

```text
Resubmitted
```

and ideally:

- requester comment
- changed fields
- new attachments

A future enhancement may provide a change diff.

---

# 50. Change Diff

V1 does not require a complete generic diff engine.

However, audit logging should preserve important field changes.

A later UI may show:

```text
Amount
RM900 → RM1,200

Description
Updated

Attachment
receipt.pdf added
```

This can significantly speed up re-review.

---

# 51. AI and Resubmission

Future AI may summarize:

```text
The requester added the missing receipt
and changed the hotel amount from RM380 to RM350.
```

But AI does not decide whether workflow must restart.

Routing changes remain deterministic business logic.

---

# 52. Business Record Statuses

Relevant states:

```text
IN_APPROVAL
CHANGES_REQUESTED
APPROVED
REJECTED
WITHDRAWN
```

Resubmit is only valid from:

```text
CHANGES_REQUESTED
```

Do not allow:

```text
APPROVED → resubmit
```

or:

```text
REJECTED → resubmit
```

unless a separate future feature explicitly introduces reopen/revision behavior.

---

# 53. Rejected Requests

V1 treats `REJECTED` as final for that business record.

If a user wants to try again:

recommended behavior is to create a new request or use a future clone feature.

Do not reuse the changes-requested resubmission flow for rejected records.

---

# 54. Withdrawn Requests

WITHDRAWN records are also not resubmitted in V1.

The user may create or clone a new request later.

This keeps lifecycle semantics simple.

---

# 55. Critical Tests — Non-Material Change

Scenario:

```text
Manager approves
Finance requests changes
Requester adds receipt
Resubmit
```

Expected:

```text
same ApprovalInstance
Manager remains completed
Finance becomes actionable again
```

---

# 56. Critical Tests — Same Routing Group

Scenario:

```text
Amount RM5,000
Finance requests changes
Amount changes to RM5,500
```

Resolver still returns same Version + Rule Group.

Expected:

```text
same ApprovalInstance
resume current step
```

---

# 57. Critical Tests — Different Routing Group

Scenario:

```text
Amount RM900
Manager approved
changes requested
Amount changes to RM15,000
```

Expected:

```text
old ApprovalInstance → CANCELLED
new ApprovalInstance created
approval restarts from Step 1
```

---

# 58. Critical Tests — New Workflow Version

Scenario:

```text
Request uses V2
changes requested
Admin publishes V3
requester only uploads receipt
```

Expected:

```text
request remains on V2
```

---

# 59. Critical Tests — Material Change + New Version

Scenario:

```text
Request uses V2
changes requested
Admin publishes V3
requester changes amount materially
```

Expected:

```text
workflow re-resolved using current active policy
new runtime may use V3
```

---

# 60. Critical Tests — Duplicate Resubmit

Two resubmit requests occur simultaneously.

Expected:

```text
one succeeds
one fails safely
one active ApprovalInstance only
```

---

# 61. Critical Tests — New Route Invalid

Material change requires new route.

New route cannot resolve required approver.

Expected:

```text
resubmission fails
old ApprovalInstance remains preserved
business record stays CHANGES_REQUESTED
```

---

# 62. Critical Tests — Previous Approval Preserved

Non-material resubmission must not create another Manager approval requirement.

Completed previous steps remain unchanged.

---

# 63. V1 Rules Summary

Use these rules for initial implementation:

```text
CHANGES_REQUESTED may be resubmitted.

Non-routing edits:
resume current step.

Routing-relevant edits:
re-resolve workflow.

Same Version + Rule Group:
resume current runtime.

Different Version or Rule Group:
create new ApprovalInstance.

New runtime:
restart from Step 1.

Old runtime:
preserve and CANCEL.

REJECTED:
no resubmit in V1.

WITHDRAWN:
no resubmit in V1.
```

---

# 64. Deferred Features

Do not implement initially:

- selectively reuse previous approvals across a changed route
- automatic approval equivalence analysis
- reopen rejected requests
- revision numbers for every request
- configurable restart-from-step behavior
- complex field-by-field approver invalidation
- automatic AI decision on materiality

These features add significant complexity.

---

# 65. Summary

Resubmission follows this decision flow:

```text
CHANGES_REQUESTED
        ↓
Requester edits
        ↓
Validate + recalculate
        ↓
Build current WorkflowContext
        ↓
Did routing-relevant data change?
   │
   ├─ No
   │   ↓
   │ Resume current ApprovalInstance
   │ Resume current Step
   │
   └─ Yes
       ↓
    Resolve workflow again
       ↓
    Same Version + Rule Group?
       │
       ├─ Yes
       │   ↓
       │ Resume current ApprovalInstance
       │
       └─ No
           ↓
        Validate new route
           ↓
        Cancel old ApprovalInstance
           ↓
        Create new ApprovalInstance
           ↓
        Restart from Step 1
```

The main principle is:

```text
Do not repeat valid approval unnecessarily.

Do not reuse approval when the request has materially changed.
```

This balance keeps the workflow both fast and trustworthy.
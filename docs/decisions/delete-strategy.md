# Delete Strategy Decision

## 1. Decision

OpsFlow uses different deletion rules for:

- drafts
- submitted business records
- workflow runtime
- audit/history
- master data
- attachments

The main principle is:

```text
Draft operational data
may be deleted.

Submitted or historical business data
must be preserved.
```

---

## 2. Why This Matters

OpsFlow handles financial and approval records.

Once a record participates in approval, the system may need to answer:

- what was submitted?
- who approved it?
- which workflow version was used?
- what amount was reviewed?
- why was it rejected?
- what changed later?

Hard-deleting these records would damage auditability and historical integrity.

---

## 3. Business Record Categories

Core business records include:

```text
PurchaseRequest
SupplierInvoice
ExpenseClaim
```

Their deletion behavior depends on lifecycle state.

---

## 4. Draft Records

A business record may be hard-deleted when:

```text
status = DRAFT
```

and it has never entered approval workflow.

Examples:

```text
unfinished Purchase Request

mistaken Expense Claim draft

duplicate Supplier Invoice draft discovered before submission
```

Deleting these records does not destroy approval history because none exists yet.

---

## 5. Submitted Records

Once a business record has been submitted:

```text
do not hard delete
```

This applies to:

```text
IN_APPROVAL
CHANGES_REQUESTED
APPROVED
REJECTED
WITHDRAWN
```

These records are historical business records.

---

## 6. Why WITHDRAWN Is Preserved

WITHDRAWN does not mean:

```text
record never existed
```

It means:

```text
record was submitted
then intentionally withdrawn
```

That distinction is important for audit and operational history.

Therefore withdrawn records remain stored.

---

## 7. Why REJECTED Is Preserved

A rejected request documents an actual business decision.

Example:

```text
PR-2026-000154
RM25,000 equipment request

Rejected by Director
Reason: Budget unavailable
```

Deleting it would erase meaningful approval history.

Therefore rejected records are not hard-deleted.

---

## 8. Why APPROVED Is Preserved

Approved financial records must remain available for:

- reporting
- downstream operations
- audit
- historical reference
- future integrations

Approved records are never hard-deleted through normal application flows.

---

## 9. Draft Child Records

When a DRAFT parent is legitimately deleted, its draft-only child data may be deleted with it.

Examples:

```text
PurchaseRequest
→ PurchaseRequestItems
```

```text
SupplierInvoice
→ SupplierInvoiceItems
```

```text
ExpenseClaim
→ ExpenseItems
```

For never-submitted drafts, cascade deletion can be appropriate.

---

## 10. Historical Child Records

Once the parent has been submitted:

its items become part of the historical business record.

Do not provide ordinary actions that delete:

```text
approved PurchaseRequestItem

submitted SupplierInvoiceItem

historical ExpenseItem
```

independently.

Edits during `CHANGES_REQUESTED` are controlled business revisions, not arbitrary historical deletion.

---

## 11. Database Cascade Warning

Do not automatically use:

```php
cascadeOnDelete()
```

for every foreign key.

Ask first:

> Is it ever valid for the parent to be physically deleted after this child becomes historical?

If the answer is no, unrestricted cascading may be dangerous.

---

## 12. ApprovalInstance

`ApprovalInstance` is historical runtime data.

Once created:

```text
do not hard delete
```

regardless of final status.

This includes:

```text
IN_PROGRESS
APPROVED
REJECTED
CANCELLED
BLOCKED
```

A CANCELLED instance may represent an old route replaced during resubmission and remains important history.

---

## 13. ApprovalStepInstance

ApprovalStepInstances are part of historical runtime.

Do not hard-delete them.

Examples:

```text
COMPLETED
REJECTED
CANCELLED
BLOCKED
WAITING from a cancelled workflow
```

all provide useful historical context.

---

## 14. ApprovalAssignment

ApprovalAssignments should also remain preserved.

They answer:

- who was expected to act?
- who actually acted?
- who was skipped?
- who was delegated?
- which assignment became cancelled?

Do not delete them after workflow completion.

---

## 15. ApprovalAction

`ApprovalAction` is append-oriented history.

Never expose normal:

```text
delete approval action
```

functionality.

Actions such as:

```text
SUBMITTED
APPROVED
REJECTED
CHANGES_REQUESTED
RESUBMITTED
WITHDRAWN
```

must remain historical.

---

## 16. ActivityLog

ActivityLog records are also historical.

Do not provide normal CRUD deletion.

V1 retains them indefinitely unless a future formal data-retention policy defines otherwise.

---

## 17. WorkflowTemplate

WorkflowTemplate is configuration master data.

Recommended behavior:

```text
ACTIVE
INACTIVE
```

Instead of deleting an existing template:

```text
set status = INACTIVE
```

This prevents it from being used for new submissions while preserving configuration history.

---

## 18. WorkflowVersion

Published or archived WorkflowVersions:

```text
never hard delete
```

because ApprovalInstances may reference them.

A DRAFT WorkflowVersion may be deleted only when:

- it has never been published
- it has no runtime references
- deletion is authorized

---

## 19. Workflow Rules and Steps

Rules, Rule Groups, and Steps belonging to:

```text
PUBLISHED
ARCHIVED
```

versions must remain preserved.

For an unused DRAFT version, its configuration children may be deleted together with the draft.

---

## 20. Master Data

Master data includes:

```text
Department
SpendCategory
Vendor
```

Preferred lifecycle:

```text
ACTIVE
INACTIVE
```

Do not hard-delete master data that may be referenced by historical business records.

---

## 21. Vendor Example

Suppose:

```text
Vendor ABC
```

is referenced by 500 historical Supplier Invoices.

If the company stops working with Vendor ABC:

```text
Vendor ABC → INACTIVE
```

Do not delete it.

Historical invoices must still display the correct vendor.

---

## 22. Spend Category Example

Suppose category:

```text
LEGACY_SOFTWARE
```

is no longer used.

Set:

```text
INACTIVE
```

New requests cannot select it.

Old requests continue showing it.

---

## 23. Department Example

If a department is reorganized:

do not delete it if historical records reference it.

Set it inactive or use a future organizational migration process.

Historical requests should not silently appear as though they belonged to a newer department.

---

## 24. Users

Users should generally be:

```text
ACTIVE
INACTIVE
```

rather than deleted.

Historical records must preserve references to:

- requester
- employee
- submitter
- approver
- workflow publisher
- audit actor

Disabling a user must not destroy their historical actions.

---

## 25. User Personal Data

Future privacy or retention requirements may require anonymization or special handling.

That is different from ordinary application deletion.

Do not design V1 around speculative anonymization requirements.

If required later, implement an explicit retention/anonymization process while preserving business integrity.

---

## 26. Attachments

Attachment deletion depends on parent lifecycle.

### Draft

Requester may remove attachments from an editable DRAFT.

The physical file may also be deleted when no longer referenced.

### CHANGES_REQUESTED

Authorized user may add/remove attachments where module rules permit.

### Historical Final Records

Do not casually delete supporting documents from:

```text
APPROVED
REJECTED
WITHDRAWN
```

records.

They may be part of the audit evidence.

---

## 27. Replaced Attachments

During `CHANGES_REQUESTED`, a receipt or document may be replaced.

Recommended behavior:

- remove it from current business usage
- record relevant audit information where useful
- physical deletion may be allowed if retention is not required

For highly audit-sensitive documents, future implementation may retain replaced versions.

V1 does not need full attachment versioning.

---

## 28. Database Foreign Keys

Foreign keys should protect historical relationships.

Preferred outcomes when deleting referenced master/config data include:

```text
RESTRICT
```

or application-level prevention.

Avoid:

```text
SET NULL
```

when doing so would destroy meaningful historical context without justification.

---

## 29. When SET NULL Is Acceptable

Nullable relationships may legitimately use `SET NULL` only when the relationship is optional and losing it does not damage required history.

Example candidates must be reviewed individually.

Do not make `SET NULL` the automatic default.

---

## 30. When CASCADE Is Appropriate

Cascade is appropriate for data whose lifecycle is fully owned by a deletable parent.

Example:

```text
never-submitted Draft
      ↓
Draft line items
```

If the Draft parent is deleted, those temporary line items have no independent historical meaning.

---

## 31. When CASCADE Is Dangerous

Avoid cascades such as:

```text
User deleted
→ ApprovalActions deleted
```

or:

```text
WorkflowVersion deleted
→ ApprovalInstances deleted
```

or:

```text
Vendor deleted
→ SupplierInvoices deleted
```

These would destroy business history.

---

## 32. Soft Deletes

Do not automatically add Laravel `SoftDeletes` to every model.

Soft delete is useful only when it solves a concrete lifecycle requirement.

For many OpsFlow records:

```text
explicit status
```

is clearer than:

```text
deleted_at
```

Examples:

```text
Vendor → INACTIVE
PurchaseRequest → WITHDRAWN
WorkflowTemplate → INACTIVE
```

These statuses explain business meaning better than generic soft deletion.

---

## 33. Why Not Soft Delete Everything

A generic:

```text
deleted_at
```

does not explain:

- rejected
- withdrawn
- inactive
- archived
- cancelled

These are distinct business states.

Using explicit statuses improves:

- readability
- reporting
- auditing
- workflow logic

---

## 34. Soft Deletes for Drafts

Soft deleting DRAFT records is optional but not required.

Recommended V1:

```text
hard delete never-submitted drafts
```

This keeps implementation simple.

If future recovery requirements exist, SoftDeletes can be reconsidered.

---

## 35. Request Number Reuse

Deleting a DRAFT must not automatically imply its human-readable number can be reused.

Recommended:

```text
generated reference numbers are never reused
```

Example:

```text
PR-2026-000100 deleted as draft
```

Next number should still be:

```text
PR-2026-000101
```

not reuse `000100`.

This simplifies auditing and concurrency-safe numbering.

---

## 36. Supplier Invoice Duplicate Rule

Deleting a never-submitted Supplier Invoice DRAFT may release:

```text
vendor_id + invoice_no
```

if the database row is actually removed.

Once a Supplier Invoice has been submitted:

the duplicate identity remains reserved even if it later becomes:

```text
REJECTED
WITHDRAWN
```

This prevents the same supplier document from being casually entered again.

---

## 37. Resubmission Does Not Delete Old Runtime

When a material resubmission creates a new ApprovalInstance:

```text
old ApprovalInstance → CANCELLED
new ApprovalInstance → IN_PROGRESS
```

Do not delete the old instance.

Its presence explains:

- original route
- previous approvals
- reason for restart

---

## 38. Rejection Does Not Reset to Draft

Do not implement:

```text
REJECTED
→ delete runtime
→ DRAFT
```

This destroys historical meaning.

Rejected is a final V1 state.

A future clone feature may create a new draft instead.

---

## 39. Withdraw Does Not Reset to Draft

Similarly, do not transform:

```text
WITHDRAWN
→ DRAFT
```

while erasing history.

A withdrawn record remains withdrawn.

A new request may be created separately.

---

## 40. Admin Delete Power

ADMIN should not receive a generic ability to delete historical records.

Administrative role does not override audit requirements.

If exceptional data removal is ever required:

- make it explicit
- tightly authorize it
- log the reason
- consider retention/compliance impact

Do not include this in V1.

---

## 41. Controller Behavior

Avoid generic REST-style:

```text
destroy()
```

for every resource simply because Laravel resource controllers support it.

Only implement delete routes where deletion is a valid business operation.

Example:

```text
PurchaseRequest draft destroy
```

may exist.

Example:

```text
ApprovalAction destroy
```

must not exist.

---

## 42. UI Behavior

For DRAFT:

```text
Delete
```

may be shown when authorized.

For submitted records, replace Delete with meaningful actions such as:

```text
Withdraw
```

where valid.

For master data:

```text
Deactivate
```

is usually better than Delete.

---

## 43. Historical Display

Inactive related records should still display correctly.

Example:

```text
Vendor ABC (Inactive)
```

may appear on a historical invoice.

Do not hide the relationship simply because it cannot be selected for new records.

---

## 44. Reporting

Historical records should remain included in reporting according to report filters.

Do not automatically remove:

```text
Rejected
Withdrawn
Inactive master references
```

from all reports.

Different reports may choose different filters.

Preserving data makes those choices possible.

---

## 45. Data Integrity Check

Before deleting any eligible record, the application should verify its current persisted state.

Example:

```text
load + lock Draft
↓
confirm still DRAFT
↓
confirm no ApprovalInstance
↓
delete
```

This protects against a race where submission happens at the same time as deletion.

---

## 46. Draft Delete Concurrency

Scenario:

```text
Request A → Submit
Request B → Delete same Draft
```

Only one valid transition should succeed.

Deletion and submission must not result in:

```text
ApprovalInstance exists
but business record deleted
```

Use transaction/state validation where needed.

---

## 47. Attachment Cleanup

Physical files belonging only to a legitimately deleted draft should be cleaned up.

Do not leave orphaned temporary files indefinitely.

Likewise, do not delete files still referenced by retained historical records.

---

## 48. Orphan Prevention

Database and storage cleanup should avoid:

```text
orphan line items
orphan attachments
orphan approval runtime
```

Relationships and service operations should preserve ownership integrity.

---

## 49. Background Cleanup

A future scheduled cleanup job may remove:

- abandoned temporary uploads
- failed upload fragments
- unreferenced temporary draft files

It must not delete historical business attachments.

---

## 50. Tests — Draft Delete

Owner deletes never-submitted DRAFT.

Expected:

```text
parent deleted
draft items deleted
eligible attachments removed
no runtime existed
```

---

## 51. Tests — Submitted Delete Denied

Attempt to delete:

```text
IN_APPROVAL
APPROVED
REJECTED
WITHDRAWN
```

business records.

Expected:

```text
operation denied
records preserved
```

---

## 52. Tests — Master Data Deactivation

Vendor with historical invoices is deactivated.

Expected:

```text
cannot be selected for new invoice
historical invoices still display vendor
```

---

## 53. Tests — Workflow Version Preservation

Archived WorkflowVersion referenced by historical ApprovalInstance cannot be deleted.

---

## 54. Tests — Runtime Preservation

Material resubmission creates replacement ApprovalInstance.

Expected:

```text
old runtime still exists
old status = CANCELLED
```

---

## 55. Tests — Historical Action Protection

No normal application route allows deleting ApprovalAction or ActivityLog.

---

## 56. Tests — Draft Delete vs Submit

Where practical, test competing delete/submit behavior.

Expected:

```text
one valid transition wins
no orphan ApprovalInstance
```

---

## 57. V1 Delete Matrix

| Record | V1 Delete Strategy |
|---|---|
| Purchase Request DRAFT | Hard delete allowed |
| Supplier Invoice DRAFT | Hard delete allowed |
| Expense Claim DRAFT | Hard delete allowed |
| Submitted business record | Never hard delete |
| ApprovalInstance | Never hard delete |
| ApprovalStepInstance | Never hard delete |
| ApprovalAssignment | Never hard delete |
| ApprovalAction | Never hard delete |
| ActivityLog | Never hard delete |
| Published WorkflowVersion | Never hard delete |
| Unused WorkflowVersion DRAFT | Delete allowed |
| WorkflowTemplate | ACTIVE / INACTIVE |
| Vendor | ACTIVE / INACTIVE |
| Department | ACTIVE / INACTIVE |
| SpendCategory | ACTIVE / INACTIVE |
| User | ACTIVE / INACTIVE |
| Draft attachments | Removable |
| Historical attachments | Preserve by default |

---

## 58. Deferred Features

Do not implement initially:

- universal recycle bin
- restore deleted drafts
- configurable retention periods
- automated financial record purging
- attachment version retention engine
- GDPR-style anonymization workflow
- legal hold
- archival storage tiering

These require explicit business or compliance requirements.

---

## 59. Decision Summary

OpsFlow follows these deletion rules:

```text
Never-submitted DRAFT
→ may be hard deleted.

Submitted business record
→ preserve.

Rejected
→ preserve.

Withdrawn
→ preserve.

Approval runtime/history
→ preserve.

Audit records
→ preserve.

Published workflow configuration
→ preserve.

Master data
→ deactivate instead of delete.

Historical attachments
→ preserve by default.

SoftDeletes
→ not automatically applied everywhere.
```

The governing principle is:

> If deleting a record would make a past business decision harder to explain, the record should not be deleted.
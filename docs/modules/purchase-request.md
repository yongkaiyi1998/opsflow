# Purchase Request

## 1. Purpose

A Purchase Request represents a request to spend company money before a purchase is made.

Typical examples include:

- laptops
- monitors
- office furniture
- software subscriptions
- professional services
- training
- equipment
- marketing spend

The request exists to answer:

- what is being purchased?
- why is it needed?
- how much will it cost?
- which department is responsible?
- which category does the spend belong to?
- who must approve it?

A Purchase Request is not a Purchase Order and does not represent payment.

---

# 2. Main Lifecycle

Typical lifecycle:

```text
DRAFT
  ↓ submit

IN_APPROVAL
  ├── final approval → APPROVED
  ├── reject → REJECTED
  └── request changes → CHANGES_REQUESTED
                             ↓
                          resubmit
                             ↓
                        IN_APPROVAL
```

An eligible request may also be:

```text
WITHDRAWN
```

by the requester before final completion.

---

# 3. Statuses

Recommended enum:

```text
PurchaseRequestStatus
```

Values:

```text
DRAFT
IN_APPROVAL
CHANGES_REQUESTED
APPROVED
REJECTED
WITHDRAWN
```

Do not expose arbitrary status mutation through forms.

All state changes must happen through explicit business operations.

---

# 4. PurchaseRequest Fields

Suggested fields:

```text
id

request_no

requester_id
department_id
vendor_id nullable
category_id

title
description

currency

subtotal
tax_amount
total_amount

needed_by_date nullable

status

submitted_at nullable
approved_at nullable
rejected_at nullable
withdrawn_at nullable

created_at
updated_at
```

---

# 5. Request Number

Each Purchase Request has a human-readable unique reference.

Example:

```text
PR-2026-000001
```

Requirements:

- unique
- generated server-side
- not editable by user
- safe under concurrent creation

Database ID and request number are separate concepts.

---

# 6. Requester

`requester_id` identifies the employee making the request.

For normal self-service creation:

```text
requester_id = authenticated user
```

Do not trust requester ID from frontend input.

Future "submit on behalf of" functionality may explicitly allow another requester, but that is deferred.

---

# 7. Department

Each Purchase Request belongs to a department.

Recommended V1 behavior:

```text
department_id = requester.department_id
```

unless the user has a future explicit permission to submit for another department.

The department is important for:

- workflow routing
- reporting
- department manager resolution
- spend analysis

---

# 8. Spend Category

Every Purchase Request should normally have one Spend Category.

Examples:

```text
SOFTWARE
EQUIPMENT
OFFICE_SUPPLIES
TRAINING
MARKETING
PROFESSIONAL_SERVICES
```

Only active categories may be selected for new requests.

Historical requests may continue referencing inactive categories.

---

# 9. Vendor

Vendor is optional in V1.

This supports requests where:

```text
vendor is known
```

and requests where:

```text
vendor has not been chosen yet
```

Examples:

Known:

```text
Renew GitHub Enterprise
Vendor = GitHub
```

Unknown:

```text
Buy 10 office chairs
Vendor = null
```

Do not require a vendor merely to make the schema look complete.

---

# 10. Title

`title` should provide a short summary.

Examples:

```text
Purchase 10 Developer Laptops
```

```text
Renew Annual Design Software Subscription
```

Recommended validation:

- required
- meaningful maximum length
- trimmed

---

# 11. Description

`description` explains business justification.

Examples:

- why the purchase is needed
- intended use
- expected business benefit
- relevant background

Recommended:

```text
required
```

for V1.

A Purchase Request without a meaningful reason creates unnecessary approver follow-up.

---

# 12. Needed By Date

`needed_by_date` is optional.

It indicates when the requester needs the purchase.

It is not:

```text
approval deadline
```

and does not directly affect workflow routing in V1.

Future dashboard logic may use it to highlight urgent requests.

---

# 13. Currency

V1 defaults to:

```text
MYR
```

but stores:

```text
currency CHAR(3)
```

to avoid a future schema migration just to support another currency.

V1 does not implement:

- exchange rates
- currency conversion
- multi-currency reporting

---

# 14. Purchase Request Items

A Purchase Request contains one or more items.

Suggested fields:

```text
purchase_request_items
----------------------
id
purchase_request_id

description

quantity
unit_price

subtotal

created_at
updated_at
```

---

# 15. Item Example

Example request:

```text
MacBook Pro
5 × RM7,500

USB-C Dock
5 × RM650

Extended Warranty
5 × RM900
```

Each item represents one logical spend line.

---

# 16. Quantity

Recommended storage:

```text
DECIMAL
```

or integer depending on product scope.

For V1, integer quantity is usually enough:

```text
quantity INT UNSIGNED
```

Requirements:

```text
quantity >= 1
```

If fractional units become a real requirement later, schema can be revisited.

---

# 17. Unit Price

Use fixed decimal storage:

```text
DECIMAL(15,2)
```

Never use FLOAT or DOUBLE.

`unit_price` must be:

```text
>= 0
```

Normally:

```text
> 0
```

unless free items are a valid future use case.

---

# 18. Item Subtotal

Backend calculates:

```text
subtotal = quantity × unit_price
```

Do not trust item subtotal from frontend input.

The application may accept quantity and unit price, then calculate subtotal server-side.

---

# 19. Request Subtotal

Purchase Request subtotal is:

```text
SUM(purchase_request_items.subtotal)
```

Always calculated server-side.

Do not trust a frontend-supplied request subtotal.

---

# 20. Tax Amount

V1 may support a manually entered request-level tax amount if needed.

Recommended safer initial design:

```text
tax_amount
```

is server-validated but entered by the requester where tax is known.

Alternative:

calculate item-level tax later.

Do not build a full tax engine in V1.

---

# 21. Total Amount

Authoritative total:

```text
total_amount = subtotal + tax_amount
```

This value is used for:

- approval workflow routing
- reporting
- dashboards

Because workflow may depend on amount, total calculation must be completed before submission.

---

# 22. Money Calculation

All authoritative calculations happen on the server.

Never trust frontend values for:

```text
item subtotal
request subtotal
total amount
```

The frontend may display calculated values for UX only.

---

# 23. Attachments

Purchase Requests may contain private attachments.

Examples:

- quotations
- proposal PDFs
- screenshots
- specifications
- supporting documents

Attachments use the shared polymorphic attachment architecture.

---

# 24. Attachment Requirements

V1 does not require an attachment for every Purchase Request.

Future policy may require:

```text
quotation required above RM5,000
```

but this should be a deterministic business validation rule if implemented.

Do not hard-code speculative attachment policies in V1.

---

# 25. Draft Creation

A requester may create a Purchase Request as:

```text
DRAFT
```

Drafts do not enter approval workflow.

Draft creation should not create:

- ApprovalInstance
- ApprovalAssignment
- approval notifications

---

# 26. Draft Editing

The requester may edit their own DRAFT request.

Editable fields include:

- title
- description
- category
- vendor
- needed-by date
- items
- tax amount
- attachments

The requester cannot edit:

- request number
- status directly
- requester identity
- approval history
- workflow version
- approver assignments

---

# 27. Draft Delete

V1 may allow hard deletion of a Purchase Request only while:

```text
status = DRAFT
```

and only if it has never entered approval workflow.

Once submitted:

```text
do not hard delete
```

Use withdrawal or historical status instead.

---

# 28. Submit Requirements

A request may be submitted only when:

- user is authorized
- status = DRAFT
- requester is active
- requester has valid department
- title is valid
- description is valid
- category is active
- at least one line item exists
- item values are valid
- backend totals are recalculated
- workflow can be resolved
- required first approver can be resolved

Submission must fail safely if any requirement is missing.

---

# 29. Submit Flow

Conceptually:

```text
DRAFT
  ↓
validate business data
  ↓
recalculate totals
  ↓
build WorkflowContext
  ↓
resolve workflow
  ↓
create approval runtime
  ↓
status → IN_APPROVAL
  ↓
record SUBMITTED
  ↓
commit
```

This is one atomic business operation.

---

# 30. WorkflowContext

Purchase Request provides a normalized workflow context.

V1 fields:

```text
module_type = PURCHASE_REQUEST
requester_id
department_id
category_id
amount = total_amount
currency
```

Workflow Engine should not inspect arbitrary PurchaseRequest fields.

---

# 31. During IN_APPROVAL

While status is:

```text
IN_APPROVAL
```

the requester cannot freely edit business data.

This prevents approval from being based on moving information.

If changes are required:

```text
Approver → Request Changes
```

then requester edits under:

```text
CHANGES_REQUESTED
```

---

# 32. Approve

Purchase Request approval is handled through shared `ApprovalService`.

PurchaseRequestController must not directly do:

```text
status = APPROVED
```

When final workflow step completes:

```text
PurchaseRequest.status → APPROVED
approved_at → now()
```

---

# 33. Reject

When the approval workflow rejects the request:

```text
status → REJECTED
rejected_at → now()
```

V1 treats REJECTED as final.

The requester cannot resubmit a rejected record.

If they want to try again later, create or clone a new request.

---

# 34. Request Changes

An approver may request changes.

Result:

```text
status → CHANGES_REQUESTED
```

The requester receives:

- approver comment
- request reference
- edit/resubmit access

---

# 35. Editing After Changes Requested

While status is:

```text
CHANGES_REQUESTED
```

the requester may edit allowed business fields.

This may include routing-relevant values such as:

- amount
- category
- department, if permitted

If these change, workflow resubmission rules apply.

---

# 36. Resubmit

Resubmission behavior follows:

```text
docs/architecture/workflow-resubmission.md
```

In summary:

```text
same route
→ resume current ApprovalInstance
```

```text
materially different route
→ cancel old ApprovalInstance
→ create new ApprovalInstance
→ restart from Step 1
```

---

# 37. Withdraw

Requester may withdraw an eligible request.

Recommended V1 allowed states:

```text
IN_APPROVAL
CHANGES_REQUESTED
```

Optionally:

```text
DRAFT
```

but Draft can simply be deleted instead.

After withdrawal:

```text
status → WITHDRAWN
withdrawn_at → now()
```

active approval runtime becomes non-actionable.

---

# 38. Withdraw Reason

V1 may optionally request a withdrawal reason.

If included, record it in:

```text
ApprovalAction
```

or relevant audit metadata.

Do not require a reason unless it improves real workflow value.

---

# 39. Authorization

Typical requester permissions:

```text
view own request
create
edit own draft
edit own changes-requested request
submit own draft
withdraw own eligible request
```

Approvers may:

```text
view assigned request
approve
reject
request changes
```

FINANCE and ADMIN visibility follows `authorization.md`.

---

# 40. View Access

The following may normally view a Purchase Request:

- requester
- currently assigned approver
- users with explicitly defined finance/admin access
- other users only if documented authorization permits it

Do not expose all purchase requests company-wide by default.

---

# 41. Approval History

Purchase Request detail page should show approval timeline.

Example:

```text
Submitted by Alice

Manager Approval
Approved by John

Finance Review
Waiting for Mary
```

The timeline comes from shared approval runtime/history.

---

# 42. Activity Logging

Important Purchase Request changes may create ActivityLog entries.

Examples:

```text
PURCHASE_REQUEST_UPDATED
PURCHASE_REQUEST_WITHDRAWN
```

Approval-specific actions remain in ApprovalAction.

Do not duplicate every approval event unnecessarily into ActivityLog.

---

# 43. Validation — Items

Each item should validate:

- description required
- quantity >= 1
- unit price valid decimal
- reasonable maximum values
- subtotal calculated server-side

A request requires at least one item.

---

# 44. Validation — Tax

If V1 permits tax input:

```text
tax_amount >= 0
```

Ensure total remains valid.

Do not allow:

```text
negative total
```

---

# 45. Validation — Needed Date

If provided:

```text
needed_by_date
```

should generally not be in an obviously invalid format.

Whether past dates are prohibited is a business decision.

Recommended V1:

```text
needed_by_date >= today
```

at initial creation/submission.

---

# 46. Vendor Validation

If vendor is provided:

- vendor must exist
- vendor should be ACTIVE for new request creation

If vendor later becomes INACTIVE:

existing historical Purchase Request remains valid.

---

# 47. Category Validation

For new/edited requests:

category must be ACTIVE.

Historical records may continue displaying inactive categories.

---

# 48. Department Validation

Requester must have a valid active department before submission.

If department is derived from requester:

do not permit arbitrary department selection in the form.

---

# 49. Requester Manager Requirement

The Purchase Request module itself should not hard-code:

```text
requester must always have manager
```

because some workflows may not use REQUESTER_MANAGER.

The workflow engine decides whether a missing manager matters based on selected route.

---

# 50. Duplicate Requests

V1 does not attempt generic duplicate Purchase Request detection.

Two requests may legitimately have similar:

- title
- amount
- vendor

Do not block them automatically.

Future AI may highlight possible duplicates as an advisory hint.

---

# 51. Copy / Clone Request

Deferred but useful future feature:

```text
Clone Purchase Request
```

It could create a new:

```text
DRAFT
```

using selected business fields from an older request.

It must not copy:

- approval history
- workflow runtime
- status
- request number
- approval timestamps

---

# 52. Purchase Request vs Purchase Order

OpsFlow V1 stops at:

```text
APPROVED PURCHASE REQUEST
```

It does not automatically create:

```text
Purchase Order
```

A future procurement module may add:

```text
Approved Purchase Request
→ Purchase Order
```

Do not build PO logic into PurchaseRequestService now.

---

# 53. Purchase Request vs Supplier Invoice

These are separate business records.

Purchase Request:

```text
before purchase
```

Supplier Invoice:

```text
supplier requests payment
```

V1 does not require Supplier Invoice to reference a Purchase Request.

Future versions may link them.

---

# 54. Future Linking

A future Supplier Invoice may optionally reference:

```text
purchase_request_id
```

or a future Purchase Order.

This can support spend traceability.

Do not make that relationship mandatory in V1.

---

# 55. Dashboard

Requester dashboard may show:

```text
My Draft Purchase Requests

Waiting Approval

Changes Requested

Recently Approved
```

Approver inbox comes from shared ApprovalAssignments.

---

# 56. Purchase Request List

Useful filters:

```text
request_no
title
status
department
category
vendor
date range
```

Use pagination.

Avoid N+1 queries when displaying:

- requester
- department
- category
- vendor

---

# 57. Search

V1 search may support:

```text
request number
title
requester name
vendor name
```

Keep search simple.

Do not introduce Elasticsearch.

---

# 58. Sorting

Useful defaults:

```text
created_at DESC
```

Potential sort fields:

- request number
- total
- status
- submitted date
- needed date

Do not allow arbitrary unsanitized SQL sort fields.

---

# 59. Request Detail Page

Recommended sections:

```text
Header
- request number
- status
- requester
- department

Business Details
- title
- description
- category
- vendor
- needed-by date

Items
- description
- quantity
- unit price
- subtotal

Totals
- subtotal
- tax
- total

Attachments

Approval Timeline

Available Actions
```

Keep workflow status easy to understand.

---

# 60. Approval View

Approver should see enough information to decide quickly.

Important:

- request reason
- total amount
- item breakdown
- category
- department
- vendor
- supporting attachments
- previous approval actions

Avoid forcing approver to open multiple pages to understand one request.

---

# 61. Changes Requested View

Requester should immediately see:

```text
Changes requested by
Step
Comment
Date
```

plus:

```text
Edit
Resubmit
```

This directly supports faster turnaround.

---

# 62. Database Relationships

Conceptually:

```text
PurchaseRequest
belongsTo requester(User)

PurchaseRequest
belongsTo Department

PurchaseRequest
belongsTo Vendor nullable

PurchaseRequest
belongsTo SpendCategory

PurchaseRequest
hasMany PurchaseRequestItem

PurchaseRequest
morphMany Attachment

PurchaseRequest
morphMany ApprovalInstance
```

Usually one active ApprovalInstance exists at a time, while historical instances may remain.

---

# 63. Suggested Indexes

Recommended:

```text
purchase_requests:
UNIQUE(request_no)

(requester_id, status)
(department_id, status)
(category_id)
(vendor_id)
(status)
(submitted_at)
```

Exact indexes should follow actual query usage.

---

# 64. Item Indexes

Recommended:

```text
purchase_request_items:
(purchase_request_id)
```

Foreign key should normally cascade when deleting a never-submitted DRAFT request.

Do not cascade-delete historical items from submitted business records.

---

# 65. Delete Strategy

DRAFT:

may be hard-deleted if never submitted.

Submitted or historical records:

```text
do not hard delete
```

This includes:

- IN_APPROVAL
- CHANGES_REQUESTED
- APPROVED
- REJECTED
- WITHDRAWN

---

# 66. Transaction — Draft Save

Ordinary single-model draft editing may not require a large explicit transaction.

However, when updating:

```text
PurchaseRequest
+
multiple line items
```

using replace/add/delete item operations, a transaction is recommended so totals and line items remain consistent.

---

# 67. Transaction — Submit

Submission must be transactional because it changes:

```text
PurchaseRequest
ApprovalInstance
ApprovalStepInstances
ApprovalAssignments
ApprovalActions
```

and possibly related timestamps.

All succeed or all roll back.

---

# 68. Optimistic Concurrency

Draft and changes-requested editing should protect against stale updates.

Recommended:

```text
updated_at
```

version check.

Example:

```text
User A opens request
User B edits request
User A saves stale form
```

User A's save should fail safely rather than overwrite B.

---

# 69. Double Submit

Double submission must not create two approval processes.

Concurrency behavior follows:

```text
docs/architecture/workflow-concurrency.md
```

Expected:

```text
one submit succeeds
one fails safely
one active ApprovalInstance
```

---

# 70. Tests — Create Draft

Authorized user can create a DRAFT Purchase Request.

Verify:

- requester derived correctly
- status DRAFT
- request number generated
- no approval runtime created

---

# 71. Tests — Item Calculation

Given:

```text
quantity = 3
unit_price = 100.00
```

expected:

```text
subtotal = 300.00
```

Request totals must equal backend-calculated values.

---

# 72. Tests — Ignore Frontend Total

Frontend submits:

```text
total_amount = 1.00
```

while actual items total:

```text
5000.00
```

Expected:

```text
stored / routed amount = 5000.00
```

Never trust frontend total.

---

# 73. Tests — Submit

Valid DRAFT submission creates:

- ApprovalInstance
- expected runtime steps
- active first assignment
- SUBMITTED ApprovalAction

and changes request to:

```text
IN_APPROVAL
```

---

# 74. Tests — Invalid Workflow

If no valid workflow can be resolved:

expected:

```text
submission fails
request remains DRAFT
no partial ApprovalInstance
```

---

# 75. Tests — Authorization

Employee cannot:

- edit another employee's request
- submit another employee's request
- withdraw another employee's request
- approve an unassigned request

---

# 76. Tests — Edit Restriction

Requester can edit:

```text
DRAFT
CHANGES_REQUESTED
```

Requester cannot freely edit:

```text
IN_APPROVAL
APPROVED
REJECTED
WITHDRAWN
```

---

# 77. Tests — Request Changes

Scenario:

```text
Manager approves
Finance requests changes
```

Expected:

```text
PurchaseRequest → CHANGES_REQUESTED
```

Requester can edit permitted fields.

---

# 78. Tests — Non-Material Resubmit

Requester adds attachment only.

Expected:

```text
existing ApprovalInstance resumes
previous completed approvals remain
```

---

# 79. Tests — Material Resubmit

Requester changes total from:

```text
RM900
```

to:

```text
RM15,000
```

and route changes.

Expected:

```text
old ApprovalInstance CANCELLED
new ApprovalInstance created
workflow restarts
```

---

# 80. Tests — Final Approval

After final step:

```text
PurchaseRequest → APPROVED
approved_at set
ApprovalInstance → APPROVED
```

---

# 81. Tests — Reject

Valid rejection:

```text
PurchaseRequest → REJECTED
rejected_at set
```

Request cannot be resubmitted in V1.

---

# 82. Tests — Withdraw

Requester withdraws eligible request.

Expected:

```text
PurchaseRequest → WITHDRAWN
ApprovalInstance → CANCELLED
pending approval work non-actionable
```

---

# 83. Tests — Double Submit

Two submit attempts:

expected one runtime only.

---

# 84. Tests — Stale Edit

Stale `updated_at` version:

expected update rejected.

---

# 85. Tests — Vendor Inactive

Inactive vendor cannot be selected for a new request.

Existing historical request using that vendor remains readable.

---

# 86. Tests — Category Inactive

Inactive category cannot be used for new submission.

Existing historical request remains valid.

---

# 87. V1 Scope

V1 Purchase Request includes:

```text
Draft creation

Multiple line items

Backend total calculation

Category

Optional Vendor

Department

Attachments

Submit

Approval Workflow

Request Changes

Resubmit

Reject

Withdraw

Approval Timeline

Authorization

Feature Tests
```

---

# 88. Deferred Features

Do not implement initially:

- Purchase Orders
- RFQ / quotation comparison engine
- budgets
- budget reservation
- vendor bidding
- recurring purchase requests
- automatic duplicate detection
- procurement catalog
- receiving
- payment
- accounting integration
- multi-currency conversion
- AI-based approval routing

These may be added later when they solve a real requirement.

---

# 89. Core Principles

Purchase Request follows these principles:

```text
The requester describes intended spend.

The backend calculates authoritative money.

The workflow engine decides approval routing.

The requester cannot change submitted data freely.

Approvers act through runtime assignments.

Changes Requested allows correction without unnecessary restart.

Material routing changes trigger workflow re-evaluation.

Approval history is preserved.

Approved Purchase Request is not yet a Purchase Order or payment.
```

---

# 90. Summary

Purchase Request flow:

```text
Create Draft
     ↓
Add Items
     ↓
Calculate Totals
     ↓
Submit
     ↓
WorkflowResolver
     ↓
Approval Runtime
     ↓
Approve / Reject / Request Changes
     ↓
Approved
or
Rejected
or
Resubmit
```

The module owns purchase-request business data.

The shared Workflow Engine owns approval routing.

The shared Approval Runtime owns approval history and assignments.

That separation should remain clear throughout implementation.
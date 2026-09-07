# Supplier Invoice

## 1. Purpose

A Supplier Invoice represents an invoice received from an external vendor that requires internal verification and approval before the company is ready to pay it.

Typical examples include:

- software subscription invoices
- office rental invoices
- consulting invoices
- equipment invoices
- cloud service invoices
- recurring supplier service charges

The module exists to answer:

- who is the vendor?
- what is the invoice number?
- what is the invoice for?
- how much is being billed?
- when is payment due?
- which department owns the spend?
- has this invoice already been submitted?
- who must verify and approve it?

A Supplier Invoice in OpsFlow is not a payment record.

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

An eligible record may also become:

```text
WITHDRAWN
```

before final completion.

---

# 3. Statuses

Recommended enum:

```text
SupplierInvoiceStatus
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

Do not allow arbitrary status updates from frontend forms.

State transitions must happen through explicit business operations.

---

# 4. SupplierInvoice Fields

Suggested fields:

```text
id

internal_no
invoice_no

vendor_id
department_id
category_id
submitted_by

invoice_date
due_date nullable

currency

subtotal
tax_amount
total_amount

description

status

submitted_at nullable
approved_at nullable
rejected_at nullable
withdrawn_at nullable

created_at
updated_at
```

---

# 5. Internal Number

Each Supplier Invoice should have an internal system reference.

Example:

```text
INV-2026-000001
```

Requirements:

- generated server-side
- unique
- not editable by users
- safe under concurrent creation

This internal number is separate from the supplier's own invoice number.

---

# 6. Supplier Invoice Number

`invoice_no` is the invoice number printed or supplied by the vendor.

Examples:

```text
INV-88291
AWS-2026-09-001
MY/2026/00981
```

This value should be treated as business data and preserved exactly enough for identification.

The system should normally normalize whitespace but should not invent or replace the supplier invoice number.

---

# 7. Duplicate Protection

One of the most important Supplier Invoice rules is duplicate protection.

Recommended business uniqueness:

```text
vendor_id + invoice_no
```

This should be enforced at the database level where practical.

Example:

```text
Vendor = ABC Services
Invoice No = INV-10021
```

Submitting the same combination twice should fail.

---

# 8. Why Vendor Must Be Part of Duplicate Check

Different vendors may legitimately use the same invoice number.

Example:

```text
Vendor A
INV-001
```

and:

```text
Vendor B
INV-001
```

These are not duplicates.

Therefore:

```text
invoice_no alone
```

should not normally be globally unique.

---

# 9. Invoice Number Normalization

Duplicate checks should avoid trivial formatting differences where reasonable.

At minimum:

- trim leading/trailing whitespace

Potential future normalization may include:

- case normalization
- internal whitespace normalization

Do not over-normalize values in a way that changes legitimate supplier identifiers.

The database uniqueness strategy should match the chosen normalization approach.

---

# 10. Vendor

Every Supplier Invoice must belong to a Vendor.

Unlike Purchase Request:

```text
vendor_id is required
```

because a supplier invoice has already been issued by a known supplier.

Only active Vendors may normally be selected for new invoices.

Historical invoices may continue referencing inactive Vendors.

---

# 11. Department

Each Supplier Invoice belongs to the department responsible for the spend.

This may be:

- submitter's department
- selected business-owning department

V1 must choose one clear rule.

Recommended V1:

```text
department_id is selected by authorized submitter
```

because Finance or admin staff may enter invoices on behalf of another department.

The selected department must be active.

---

# 12. Category

Every Supplier Invoice should have one Spend Category for:

- workflow routing
- reporting
- spend analysis

Examples:

```text
SOFTWARE
RENT
PROFESSIONAL_SERVICES
EQUIPMENT
UTILITIES
MARKETING
```

Only active categories may be selected for new invoices.

---

# 13. Submitted By

`submitted_by` records the user who entered the Supplier Invoice into OpsFlow.

For normal creation:

```text
submitted_by = authenticated user
```

Do not trust this value from frontend input.

This is not necessarily the same as:

```text
department owner
```

or:

```text
approver
```

---

# 14. Invoice Date

`invoice_date` represents the date issued by the supplier.

Recommended:

```text
required
```

This date is used for:

- reporting
- aging
- audit
- future duplicate/anomaly analysis

---

# 15. Due Date

`due_date` represents when the supplier expects payment.

It may be nullable if unknown.

Useful for:

- Finance Workspace
- due-soon indicators
- future reminders
- prioritization

Due date does not itself determine approval correctness in V1.

---

# 16. Due Date Validation

Recommended baseline:

```text
due_date >= invoice_date
```

when due date is provided.

If a company occasionally receives overdue invoices, due date may still be in the past relative to today.

That should not make the record invalid.

---

# 17. Currency

V1 defaults to:

```text
MYR
```

Store:

```text
currency CHAR(3)
```

V1 does not implement:

- exchange rates
- currency conversion
- FX gain/loss
- converted reporting totals

---

# 18. Invoice Items

A Supplier Invoice may contain multiple line items.

Suggested fields:

```text
supplier_invoice_items
----------------------
id
supplier_invoice_id

description
quantity
unit_price
subtotal

created_at
updated_at
```

---

# 19. Item Example

Example:

```text
Cloud Hosting
1 × RM4,000

Managed Support
1 × RM1,500

Additional Storage
5 × RM100
```

The system should allow invoice items to describe the supplier charge clearly.

---

# 20. Quantity

For V1:

```text
quantity DECIMAL(15,4)
```

is more flexible than integer-only quantity.

Why?

Supplier invoices may contain:

```text
1.5 hours
2.25 units
0.5 service period
```

If you want an even simpler V1, integer quantity is acceptable, but decimal quantity is more realistic for supplier billing.

Recommended:

```text
quantity > 0
```

---

# 21. Unit Price

Use:

```text
DECIMAL(15,2)
```

or a higher scale if the chosen quantity model requires precise multiplication.

Never use FLOAT or DOUBLE for financial values.

---

# 22. Item Subtotal

The backend calculates:

```text
subtotal = quantity × unit_price
```

Do not trust frontend item subtotal.

---

# 23. Invoice Subtotal

Authoritative invoice subtotal:

```text
SUM(supplier_invoice_items.subtotal)
```

calculated server-side.

---

# 24. Tax Amount

V1 may support:

```text
tax_amount
```

at invoice level.

Recommended:

```text
tax_amount >= 0
```

Do not build a complete tax engine in V1.

---

# 25. Total Amount

Authoritative total:

```text
total_amount = subtotal + tax_amount
```

This value is used for:

- approval routing
- finance reporting
- dashboards
- future anomaly detection

Totals must be recalculated before submission.

---

# 26. Description

`description` explains the business purpose or billing context.

Examples:

```text
Annual cloud hosting renewal for production systems.
```

```text
September office rental invoice.
```

Recommended:

```text
required
```

or at minimum strongly encouraged.

---

# 27. Attachments

Supplier Invoice should normally have at least one supporting attachment.

Most commonly:

```text
supplier invoice PDF/image
```

Potential additional attachments:

- contract
- quotation
- delivery document
- email confirmation
- statement

---

# 28. Invoice Document Requirement

Unlike Purchase Request, V1 should strongly consider requiring one invoice attachment before submission.

Recommended rule:

```text
at least one attachment required
```

before entering workflow.

This makes the module realistic and prepares cleanly for future AI extraction.

---

# 29. Private Storage

Attachments are private business documents.

Access must follow:

```text
SupplierInvoicePolicy
```

Do not expose unrestricted public file URLs.

---

# 30. Draft Creation

A Supplier Invoice begins as:

```text
DRAFT
```

Draft creation does not create approval runtime.

The submitter may save partial data before final submission.

---

# 31. Draft Editing

Authorized users may edit DRAFT invoices.

Editable fields include:

- vendor
- supplier invoice number
- department
- category
- invoice date
- due date
- description
- line items
- tax
- attachments

Do not allow direct editing of:

- internal number
- status
- approval runtime
- submitted_by
- approved_at
- workflow version

---

# 32. Draft Delete

A never-submitted DRAFT invoice may be hard-deleted.

Once submitted:

```text
do not hard delete
```

Historical financial records should remain preserved.

---

# 33. Submit Requirements

A Supplier Invoice may be submitted only when:

- user is authorized
- status = DRAFT
- vendor is valid and active
- invoice number is present
- duplicate invoice check passes
- department is valid
- category is valid
- invoice date is valid
- at least one invoice item exists
- values are valid
- invoice attachment requirement is satisfied
- totals are recalculated
- workflow can be resolved
- first required approver can be resolved

---

# 34. Duplicate Check Before Submit

Application-level validation should provide a friendly error.

Example:

```text
Invoice INV-10021 already exists for ABC Services.
```

But database uniqueness must remain the final protection against race conditions.

Do not rely only on:

```text
exists()
```

before insert/update.

---

# 35. Duplicate Check on Edit

When editing an existing draft:

ignore the current record when validating:

```text
vendor_id + invoice_no
```

But if vendor or invoice number changes to another existing combination:

reject the update.

---

# 36. Duplicate Constraint and Historical Records

A rejected or withdrawn invoice may still represent the same supplier document.

Recommended V1:

keep the uniqueness constraint across all records.

Do not allow the same supplier invoice number to be re-entered simply because the earlier record was rejected.

If a correction is needed, use the existing record or a future explicit replacement/revision mechanism.

---

# 37. Submit Flow

Conceptually:

```text
DRAFT
  ↓
validate invoice
  ↓
check duplicate
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

This must be atomic.

---

# 38. WorkflowContext

Supplier Invoice provides:

```text
module_type = SUPPLIER_INVOICE
requester_id = submitted_by
department_id
category_id
amount = total_amount
currency
```

Future fields may include:

```text
vendor_id
```

if vendor-specific routing becomes a real requirement.

Do not add vendor routing in V1 without need.

---

# 39. Finance Review

Supplier Invoice naturally fits Finance review.

A common workflow may be:

```text
Department Manager
      ↓
Finance
      ↓
Director for high value
```

But this route belongs in Workflow Configuration.

Do not hard-code Finance approval in SupplierInvoiceService.

---

# 40. During IN_APPROVAL

While invoice is:

```text
IN_APPROVAL
```

business data should not be freely editable.

Otherwise approvers may approve different data than what was originally submitted.

Changes must go through:

```text
Request Changes
```

---

# 41. Approve

Final approval is handled by shared `ApprovalService`.

When final step completes:

```text
SupplierInvoice.status → APPROVED
approved_at → now()
```

APPROVED means:

```text
internally approved and ready for downstream finance handling
```

It does not mean payment has happened.

---

# 42. Payment Meaning

OpsFlow V1 does not implement payment execution.

Therefore avoid statuses such as:

```text
PAID
PAYMENT_FAILED
```

inside Supplier Invoice V1 unless a payment module is actually introduced later.

The scope ends at approval readiness.

---

# 43. Reject

When rejected:

```text
status → REJECTED
rejected_at → now()
```

V1 treats rejection as final for this business record.

The same supplier invoice should not be casually recreated because duplicate protection should still apply.

---

# 44. Request Changes

An approver may request changes for issues such as:

- wrong amount
- missing invoice attachment
- wrong department
- wrong category
- unclear description
- incorrect invoice date

Result:

```text
status → CHANGES_REQUESTED
```

The submitter can correct allowed fields.

---

# 45. Resubmission

Follow:

```text
docs/architecture/workflow-resubmission.md
```

If only supporting information changes:

```text
resume existing route
```

If amount/department/category changes materially:

```text
re-evaluate routing
```

A different route may require a new ApprovalInstance.

---

# 46. Vendor Change During Resubmission

Vendor is not a V1 workflow-routing field, but changing vendor is still financially significant.

Recommended V1 rule:

```text
changing vendor during CHANGES_REQUESTED
requires duplicate revalidation
```

It does not automatically restart workflow unless future routing rules depend on vendor.

However, ActivityLog should preserve the change.

---

# 47. Invoice Number Change During Resubmission

Changing supplier invoice number requires:

- duplicate revalidation
- audit logging

It does not necessarily restart workflow because invoice number is not a routing field.

Still, approvers should be able to see that the value changed.

---

# 48. Amount Change During Resubmission

Changing item values or tax recalculates:

```text
subtotal
tax_amount
total_amount
```

Because amount affects routing:

```text
workflow re-evaluation required
```

---

# 49. Department / Category Change

Both are routing-relevant in V1.

Changing either requires workflow re-evaluation.

---

# 50. Withdraw

Authorized submitter may withdraw an eligible Supplier Invoice.

Recommended allowed states:

```text
IN_APPROVAL
CHANGES_REQUESTED
```

After withdrawal:

```text
status → WITHDRAWN
withdrawn_at → now()
```

Approval runtime becomes non-actionable.

---

# 51. Who May Create Supplier Invoices

V1 should be more restrictive than Expense Claim creation.

Possible options:

### Option A

Any Employee may submit Supplier Invoice.

### Option B

Only FINANCE / ADMIN or designated users may submit.

For a cleaner enterprise system, recommended V1:

```text
EMPLOYEE may create Purchase Request / Expense Claim

FINANCE and ADMIN may create Supplier Invoice
```

This reflects the fact that supplier invoices are typically centralized through AP/Finance.

If you want departments to submit invoices too, authorization can be widened later.

---

# 52. Recommended Authorization

V1 recommendation:

## FINANCE

May:

- create Supplier Invoice
- edit own/finance draft
- submit
- view finance invoice list
- view approval state

## ADMIN

May manage according to administrative policies.

## EMPLOYEE

Does not create Supplier Invoice by default.

May still view an invoice when they are an assigned approver.

---

# 53. Why Centralize Invoice Entry

Centralized Finance entry gives several benefits:

- more consistent vendor selection
- stronger duplicate control
- cleaner invoice numbering
- less duplicate submission
- easier AP operations

This makes the system more realistic without adding much complexity.

---

# 54. Approval Permission

Being FINANCE does not automatically allow approving every Supplier Invoice.

Approval still requires:

```text
active ApprovalAssignment
```

Visibility and approval responsibility remain separate.

---

# 55. View Access

Supplier Invoice may contain sensitive commercial information.

Typical viewers:

- FINANCE
- ADMIN
- currently assigned approvers
- other explicitly authorized users

Do not expose supplier invoices company-wide.

---

# 56. Finance Workspace

Supplier Invoice should be one of the main Finance Workspace modules.

Useful sections:

```text
Draft Invoices

Awaiting Approval

Changes Requested

Approved Invoices

Due Soon
```

---

# 57. Due Soon

Useful UI condition:

```text
due_date approaching
AND
status not final/approved as appropriate
```

V1 may display this as a filter or badge.

Do not build complex automatic escalation solely from due date.

---

# 58. Overdue Invoice

An invoice can be overdue according to supplier due date while still waiting for approval.

This is operationally important but should not change approval state automatically.

Example:

```text
Supplier Due Date Passed
≠
Automatically Approved
```

The dashboard may highlight it.

---

# 59. Aging

Future Finance reports may classify:

```text
0–30 days
31–60 days
61–90 days
90+ days
```

based on invoice/due dates.

Do not build full AP aging reports in initial V1 unless desired after core workflow is complete.

---

# 60. Approval Timeline

Invoice detail page should show:

```text
Submitted by Finance

Department Manager
Approved

Finance Review
Approved

Director
Waiting
```

Timeline comes from shared approval runtime.

---

# 61. Activity Logging

Important Supplier Invoice changes may include:

```text
SUPPLIER_INVOICE_CREATED
SUPPLIER_INVOICE_UPDATED
SUPPLIER_INVOICE_WITHDRAWN
```

Important changed values may include:

- vendor
- invoice number
- amount
- invoice date
- due date
- category
- department

Approval-specific actions remain in ApprovalAction.

---

# 62. Duplicate Attempt Logging

Routine user validation errors do not necessarily require ActivityLog.

However, repeated unexpected duplicate insert failures may be worth technical logging.

Do not spam the business audit trail with every form mistake.

---

# 63. Database Relationships

Conceptually:

```text
SupplierInvoice
belongsTo Vendor

SupplierInvoice
belongsTo Department

SupplierInvoice
belongsTo SpendCategory

SupplierInvoice
belongsTo User as submittedBy

SupplierInvoice
hasMany SupplierInvoiceItem

SupplierInvoice
morphMany Attachment

SupplierInvoice
morphMany ApprovalInstance
```

---

# 64. Suggested Unique Constraints

Recommended:

```text
UNIQUE(internal_no)
```

and:

```text
UNIQUE(vendor_id, invoice_no)
```

Exact database collation/case behavior should be considered when implementing invoice-number normalization.

---

# 65. Suggested Indexes

Recommended:

```text
supplier_invoices:

(vendor_id)
(department_id, status)
(category_id)
(submitted_by)
(status)
(invoice_date)
(due_date)
```

Do not add every possible combination without evidence from queries.

---

# 66. Item Index

Recommended:

```text
supplier_invoice_items:
(supplier_invoice_id)
```

---

# 67. Draft Item Updates

When replacing/updating multiple line items:

use a transaction so:

```text
items
+
subtotal
+
tax
+
total
```

remain consistent.

---

# 68. Submit Transaction

Submission must atomically update/create:

```text
SupplierInvoice
ApprovalInstance
ApprovalStepInstances
ApprovalAssignments
ApprovalActions
```

If any workflow operation fails:

```text
Supplier Invoice remains DRAFT
```

---

# 69. Concurrency — Duplicate Supplier Invoice

Two Finance users may attempt to create the same supplier invoice simultaneously.

Both may pass an application-level duplicate check.

The database unique constraint must ensure only one succeeds.

Handle the resulting conflict gracefully.

---

# 70. Concurrency — Double Submit

Two submit attempts for the same draft must produce:

```text
one active ApprovalInstance
```

only.

Follow:

```text
docs/architecture/workflow-concurrency.md
```

---

# 71. Optimistic Concurrency

Draft and changes-requested edits should protect against stale updates.

Use:

```text
updated_at
```

or equivalent version marker.

Do not silently overwrite another Finance user's newer edit.

---

# 72. AI Extension — Invoice Extraction

Supplier Invoice is the strongest natural entry point for future AI.

Possible V2 flow:

```text
Upload Invoice
      ↓
AI Document Extraction
      ↓
Suggest:
vendor
invoice_no
invoice_date
due_date
subtotal
tax
total
      ↓
Human Review
      ↓
Save Draft
```

AI output is not authoritative until validated/confirmed.

---

# 73. AI Must Not Auto-Submit

Future AI must not:

```text
extract document
→ automatically submit for approval
```

without validation.

Recommended:

```text
AI suggests
Human verifies
Backend validates
Human submits
```

---

# 74. AI Duplicate Hint

Future AI or similarity logic may flag:

```text
Possible duplicate invoice
```

But authoritative duplicate protection remains deterministic:

```text
vendor_id + invoice_no
```

AI should supplement, not replace, database rules.

---

# 75. AI Anomaly Hint

Potential future hints:

- amount much higher than previous invoices
- unusual due date
- vendor/category mismatch
- duplicate-looking document
- invoice total inconsistent with extracted line items

These are advisory.

They must not automatically reject the invoice.

---

# 76. Invoice Detail Page

Recommended sections:

```text
Header
- internal number
- supplier invoice number
- status

Vendor
- vendor name

Business Context
- department
- category
- description

Dates
- invoice date
- due date

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

---

# 77. Finance List Page

Useful columns:

```text
Internal No
Supplier Invoice No
Vendor
Department
Invoice Date
Due Date
Total
Status
```

Useful filters:

```text
vendor
department
status
category
invoice date
due date
```

Use pagination.

---

# 78. Search

V1 search may support:

```text
internal number
supplier invoice number
vendor name
description
```

Do not introduce Elasticsearch.

---

# 79. Tests — Create Draft

Authorized Finance user can create DRAFT invoice.

Verify:

- submitted_by derived from authenticated user
- internal number generated
- no approval runtime created

---

# 80. Tests — Unauthorized Creation

Ordinary EMPLOYEE cannot create Supplier Invoice under recommended V1 authorization.

---

# 81. Tests — Duplicate Vendor Invoice

Create:

```text
Vendor A
INV-001
```

Then attempt another:

```text
Vendor A
INV-001
```

Expected:

```text
rejected
```

---

# 82. Tests — Same Number Different Vendor

Create:

```text
Vendor A
INV-001
```

and:

```text
Vendor B
INV-001
```

Expected:

```text
both allowed
```

---

# 83. Tests — Backend Total

Frontend sends incorrect total.

Backend calculates from line items and tax.

Stored/routed amount must use backend total.

---

# 84. Tests — Attachment Requirement

Submit without required invoice attachment.

Expected:

```text
submission rejected
invoice remains DRAFT
```

---

# 85. Tests — Submit

Valid invoice submission creates approval runtime and changes:

```text
status → IN_APPROVAL
```

---

# 86. Tests — Workflow Failure

No valid workflow or approver.

Expected:

```text
submission fails
invoice remains DRAFT
no partial runtime
```

---

# 87. Tests — Final Approval

Final approval results:

```text
SupplierInvoice → APPROVED
approved_at set
ApprovalInstance → APPROVED
```

---

# 88. Tests — Reject

Valid rejection results:

```text
SupplierInvoice → REJECTED
```

Record remains historical and duplicate uniqueness remains protected.

---

# 89. Tests — Request Changes

Approver requests correction.

Expected:

```text
status → CHANGES_REQUESTED
```

Authorized Finance user may edit.

---

# 90. Tests — Amount Change Resubmit

Amount changes enough to select a different workflow route.

Expected:

```text
old ApprovalInstance preserved/cancelled
new ApprovalInstance created
```

according to resubmission rules.

---

# 91. Tests — Invoice Number Change

Changing invoice number to an existing:

```text
vendor + invoice_no
```

combination must fail.

---

# 92. Tests — Vendor Change

Changing Vendor during CHANGES_REQUESTED triggers duplicate revalidation.

---

# 93. Tests — Withdraw

Authorized user withdraws eligible invoice.

Expected:

```text
SupplierInvoice → WITHDRAWN
ApprovalInstance → CANCELLED
pending assignments non-actionable
```

---

# 94. Tests — Double Submit

Two submission attempts result in one active runtime only.

---

# 95. Tests — Concurrent Duplicate Create

Where practical, test or at least validate database uniqueness protects simultaneous duplicate invoice insertion.

---

# 96. V1 Scope

V1 Supplier Invoice includes:

```text
Finance-controlled creation

Vendor

Supplier invoice number

Duplicate protection

Department

Category

Invoice date

Due date

Multiple line items

Backend totals

Private invoice attachment

Approval Workflow

Request Changes

Resubmit

Reject

Withdraw

Approval Timeline

Finance list/workspace

Feature Tests
```

---

# 97. Deferred Features

Do not implement initially:

- payment execution
- payment status
- payment gateway
- bank integration
- accounting integration
- purchase order matching
- goods receipt matching
- 3-way matching
- vendor statement reconciliation
- recurring invoice automation
- multi-currency conversion
- AP aging engine
- tax engine
- automatic AI approval
- autonomous fraud blocking

These may be introduced later as separate capabilities.

---

# 98. Core Principles

Supplier Invoice follows:

```text
Vendor + Invoice Number protects against duplicates.

Finance owns structured invoice intake.

Backend calculations are authoritative.

Invoice documents remain private.

Approval workflow determines routing.

Approval does not mean payment.

Financial records remain historical.

AI may extract and assist later,
but humans verify and approve.
```

---

# 99. Summary

Supplier Invoice flow:

```text
Finance Creates Draft
      ↓
Vendor + Invoice Details
      ↓
Add Line Items
      ↓
Attach Supplier Invoice
      ↓
Backend Calculates Total
      ↓
Duplicate Validation
      ↓
Submit
      ↓
WorkflowResolver
      ↓
Approval Runtime
      ↓
Approve / Reject / Request Changes
      ↓
APPROVED
```

The Supplier Invoice module owns supplier billing data.

The Workflow Engine owns routing.

Approval Runtime owns approval execution and history.

Payment remains outside V1 scope.
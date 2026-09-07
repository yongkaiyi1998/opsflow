# Expense Claim

## 1. Purpose

An Expense Claim represents business expenses that an employee has already paid personally and wants the company to reimburse.

Typical examples include:

- transport
- parking
- hotel
- meals
- business travel
- small office purchases
- training fees
- client entertainment
- mileage-related expenses

The module exists to answer:

- who paid the expense?
- what was the expense for?
- when did it happen?
- how much was spent?
- which spend category applies?
- is supporting evidence attached?
- who must approve the reimbursement?

An Expense Claim is not a payroll record and does not perform actual reimbursement payment in V1.

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

An eligible claim may also become:

```text
WITHDRAWN
```

before final approval.

---

# 3. Statuses

Recommended enum:

```text
ExpenseClaimStatus
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

Do not expose direct status editing through forms.

All important state transitions happen through explicit business operations.

---

# 4. ExpenseClaim Fields

Suggested fields:

```text
id

claim_no

employee_id
department_id

title
description

currency
total_amount

status

submitted_at nullable
approved_at nullable
rejected_at nullable
withdrawn_at nullable

created_at
updated_at
```

---

# 5. Claim Number

Each Expense Claim has a unique human-readable reference.

Example:

```text
EXP-2026-000001
```

Requirements:

- unique
- generated server-side
- not editable by user
- safe under concurrent creation

Database ID and claim number remain separate.

---

# 6. Employee

`employee_id` identifies the employee requesting reimbursement.

For normal self-service creation:

```text
employee_id = authenticated user
```

Do not trust employee ID from frontend input.

Submitting claims on behalf of another employee is deferred.

---

# 7. Department

Recommended V1 behavior:

```text
department_id = employee.department_id
```

The department is captured for:

- workflow routing
- reporting
- manager resolution
- spend analysis

Do not allow ordinary employees to freely submit claims under another department.

---

# 8. Claim Header vs Expense Items

An Expense Claim is a container.

Example:

```text
Singapore Client Visit
```

The individual expenses are stored as:

```text
ExpenseItem
```

Example:

```text
Flight       RM450
Hotel        RM700
Grab         RM80
Meals        RM120
```

The claim total is calculated from all Expense Items.

---

# 9. Title

`title` provides a short description of the overall claim.

Examples:

```text
Singapore Customer Meeting
```

```text
September Sales Travel Expenses
```

Recommended validation:

- required
- trimmed
- reasonable maximum length

---

# 10. Description

`description` explains the overall business purpose.

Examples:

```text
Travel expenses for customer implementation meeting in Singapore.
```

Recommended:

```text
required
```

A clear business purpose reduces approver follow-up.

---

# 11. Currency

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
- foreign-currency conversion
- FX reimbursement rules

For initial scope, all items in one claim use the same currency.

---

# 12. Expense Items

Suggested fields:

```text
expense_items
-------------
id
expense_claim_id

category_id

expense_date
merchant nullable
description

amount
tax_amount

receipt_required
receipt_attached

created_at
updated_at
```

Each item represents one expense event.

---

# 13. Expense Date

`expense_date` identifies when the expense occurred.

Recommended:

```text
required
```

Normally:

```text
expense_date <= today
```

because an employee should not claim a future expense.

Future travel advances are a different business process and are outside V1.

---

# 14. Merchant

`merchant` records where the employee spent the money.

Examples:

```text
Grab
Hilton Kuala Lumpur
Malaysia Airlines
ABC Parking
```

Merchant may be nullable for cases where it is not meaningful.

Merchant is free-text in V1.

Do not require every expense merchant to exist in the Vendor master.

---

# 15. Why Merchant Is Not Vendor

Vendor master is primarily used for Supplier Invoice processes.

Expense Claims often involve:

- taxis
- restaurants
- parking machines
- small shops

Creating Vendor master records for every merchant would make the system cumbersome.

Therefore:

```text
ExpenseItem.merchant
```

is normally free text.

---

# 16. Spend Category

Each Expense Item has its own Spend Category.

Examples:

```text
TRAVEL
HOTEL
MEALS
PARKING
TRAINING
OFFICE_SUPPLIES
CLIENT_ENTERTAINMENT
```

This is different from Purchase Request and Supplier Invoice, which may use one main category at header level.

---

# 17. Why Category Belongs to Item

One claim may contain:

```text
Hotel
Travel

Meal
Meals

Taxi
Transport
```

Using one category for the entire claim would lose useful reporting information.

Therefore category belongs to each Expense Item.

---

# 18. Workflow Category

The shared WorkflowContext currently expects one category.

Expense Claim therefore needs one deterministic routing category.

Recommended V1 rule:

```text
primary workflow category
=
category of the highest-value Expense Item
```

Example:

```text
Hotel RM800 → TRAVEL
Meal RM100  → MEALS
Parking RM20 → PARKING
```

Routing category:

```text
TRAVEL
```

---

# 19. Why Highest-Value Item

This approach is:

- deterministic
- easy to explain
- easy to test
- usually representative of the main spend

It avoids asking the employee to choose an additional duplicate claim-level category.

If future business requirements need a claim-level purpose category, the model can evolve.

---

# 20. Tied Highest-Value Categories

If two highest-value items have equal amounts but different categories:

recommended deterministic V1 behavior:

```text
use the earliest ExpenseItem by id/order
```

However, this situation should not materially affect most workflows.

If category routing becomes highly important, a dedicated claim-level routing category may be introduced later.

---

# 21. Amount

Each Expense Item stores:

```text
amount DECIMAL(15,2)
```

Requirements:

```text
amount > 0
```

Never use FLOAT or DOUBLE.

---

# 22. Tax Amount

V1 may store:

```text
tax_amount DECIMAL(15,2)
```

default:

```text
0.00
```

Tax is informational in V1.

Do not build a tax recovery engine yet.

---

# 23. Meaning of Item Amount

Recommended V1 convention:

```text
amount = gross amount paid by employee
```

If tax is included in the receipt:

```text
tax_amount
```

is a breakdown of the gross amount, not an additional amount.

Therefore claim total is:

```text
SUM(expense_items.amount)
```

not:

```text
SUM(amount + tax_amount)
```

This avoids double-counting tax.

---

# 24. Claim Total

Authoritative:

```text
total_amount = SUM(expense_items.amount)
```

Calculated server-side.

Never trust frontend claim total.

---

# 25. Receipt Requirement

Not every expense necessarily needs a receipt.

ExpenseItem stores:

```text
receipt_required
```

and:

```text
receipt_attached
```

However, `receipt_attached` should ideally be derived from actual attachments rather than trusted as user input.

---

# 26. Receipt Policy

V1 should keep receipt policy simple.

Recommended initial rule:

```text
receipt required by default
```

with explicit exceptions if needed.

Examples that might be allowed without receipt:

- mileage
- toll under specific company policy
- very small parking expense

Do not build a generic policy engine for receipt requirements in V1.

---

# 27. Receipt Attachment

Receipts should attach to the individual Expense Item rather than only the claim header.

Conceptually:

```text
ExpenseItem
morphMany Attachment
```

This makes it clear which receipt supports which expense.

The claim itself may also support general supporting attachments.

---

# 28. Receipt Validation

Before submission:

for every item where:

```text
receipt_required = true
```

at least one eligible receipt attachment should exist.

Do not trust:

```text
receipt_attached = true
```

from frontend input.

Derive it from persisted attachment records.

---

# 29. Missing Receipt

If a required receipt is missing:

submission fails with a clear message.

Example:

```text
A receipt is required for the hotel expense dated 5 Sep 2026.
```

The claim remains:

```text
DRAFT
```

---

# 30. Draft Creation

A claim begins as:

```text
DRAFT
```

Creating a draft does not create approval runtime.

Employees may save partially completed claims and return later.

---

# 31. Draft Editing

Employee may edit their own DRAFT claim.

Editable:

- title
- description
- expense items
- attachments

Cannot edit directly:

- claim number
- employee ownership
- department
- status
- approval history
- workflow version
- approval assignments
- approval timestamps

---

# 32. Draft Delete

A never-submitted DRAFT may be hard-deleted by its owner.

Once submitted:

```text
do not hard delete
```

Historical claims must remain auditable.

---

# 33. Submit Requirements

A claim may be submitted only when:

- user is authorized
- status = DRAFT
- employee is active
- employee has valid department
- title and description are valid
- at least one Expense Item exists
- all item dates are valid
- all item categories are active
- all amounts are valid
- required receipts exist
- total is recalculated
- workflow context can be built
- workflow can be resolved
- first required approver can be resolved

---

# 34. Submit Flow

Conceptually:

```text
DRAFT
  ↓
validate claim
  ↓
validate expense items
  ↓
validate receipts
  ↓
calculate total
  ↓
determine routing category
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

# 35. WorkflowContext

Expense Claim provides:

```text
module_type = EXPENSE_CLAIM
requester_id = employee_id
department_id
category_id = routing category
amount = total_amount
currency
```

The Workflow Engine does not need to understand every Expense Item.

---

# 36. During IN_APPROVAL

While status is:

```text
IN_APPROVAL
```

the employee cannot freely edit:

- amount
- expense items
- receipts
- categories
- business description

This prevents approvers from reviewing changing information.

Corrections require:

```text
Request Changes
```

---

# 37. Approve

Approval is handled by shared `ApprovalService`.

When the final workflow step completes:

```text
ExpenseClaim.status → APPROVED
approved_at → now()
```

APPROVED means:

```text
approved for reimbursement processing
```

It does not mean the employee has already been paid.

---

# 38. Reimbursement

Actual reimbursement execution is outside V1.

Do not add statuses such as:

```text
PAID
REIMBURSED
PAYMENT_FAILED
```

unless a reimbursement/payment module is intentionally introduced later.

---

# 39. Reject

When rejected:

```text
status → REJECTED
rejected_at → now()
```

V1 treats REJECTED as final.

The employee cannot resubmit the rejected claim.

A future Clone action may create a new Draft if needed.

---

# 40. Request Changes

Approver may request corrections such as:

- missing receipt
- unclear description
- incorrect amount
- incorrect category
- duplicate item
- invalid expense date

Result:

```text
status → CHANGES_REQUESTED
```

The approver comment should explain what needs correction.

---

# 41. Editing After Changes Requested

Employee may edit allowed business information while:

```text
CHANGES_REQUESTED
```

Including:

- item amount
- category
- merchant
- description
- receipt
- expense date

Any change that affects routing must trigger resubmission evaluation.

---

# 42. Amount Change

Any change to item amounts causes:

```text
recalculate claim total
```

Because amount is routing-relevant:

```text
workflow re-evaluation may be required
```

---

# 43. Category Change

Because routing category is derived from items:

changing:

- item category
- item amount
- adding/removing an item

may change the primary workflow category.

Therefore routing category must be recalculated on resubmit.

---

# 44. Department Change

Department normally comes from Employee and is not directly editable.

If employee's department changes while claim is in CHANGES_REQUESTED:

the current WorkflowContext may differ on resubmission.

This is treated as a workflow-relevant change.

---

# 45. Non-Material Resubmission

Example:

Finance requests missing receipt.

Employee uploads the receipt without changing:

- amounts
- categories
- department

Then:

```text
same ApprovalInstance
current step resumes
previous approvals remain
```

---

# 46. Material Resubmission

Example:

Original total:

```text
RM800
```

Employee corrects hotel amount.

New total:

```text
RM8,000
```

If workflow route changes:

```text
old ApprovalInstance → CANCELLED
new ApprovalInstance → created
approval restarts from Step 1
```

Follow:

```text
docs/architecture/workflow-resubmission.md
```

---

# 47. Withdraw

Employee may withdraw an eligible claim.

Recommended V1 states:

```text
IN_APPROVAL
CHANGES_REQUESTED
```

After withdrawal:

```text
ExpenseClaim → WITHDRAWN
ApprovalInstance → CANCELLED
pending runtime → non-actionable
withdrawn_at → now()
```

---

# 48. Claim Ownership

Employee owns the Expense Claim.

Typical permissions:

- create
- view own
- edit own draft
- edit own changes-requested claim
- submit
- resubmit
- withdraw

Ownership is enforced server-side.

---

# 49. Manager Access

Manager may view the claim when:

```text
workflow assigns them
```

Do not automatically grant every manager unrestricted access to every historical claim from their reports unless explicitly required.

---

# 50. Finance Access

FINANCE should normally have operational visibility into Expense Claims.

Typical:

- view submitted claims
- view claims requiring Finance review
- view approved claims
- access Finance Workspace

Approval itself still requires active runtime assignment.

---

# 51. Admin Access

ADMIN may have broader administrative visibility according to Policy.

Admin does not automatically replace the assigned approver.

---

# 52. Private Attachments

Receipts contain potentially sensitive information.

All attachments must be private.

Access requires authorization to the parent Expense Claim / Expense Item.

Do not expose direct public storage URLs.

---

# 53. Receipt File Types

Recommended V1 accepted types may include:

```text
PDF
JPEG
PNG
WEBP
```

Set reasonable file size limits.

Do not accept arbitrary executable file types.

Exact limits can be defined during implementation.

---

# 54. Multiple Receipts

An Expense Item may support multiple attachments.

Example:

```text
restaurant receipt
credit card receipt
supporting email
```

Receipt validation requires at least one valid supporting attachment when receipt is required.

---

# 55. Duplicate Expense Items

V1 should not automatically block items merely because they share:

- amount
- date
- merchant

Legitimate duplicate-looking expenses can occur.

Future logic may flag possible duplicates.

Do not make weak heuristics authoritative.

---

# 56. Future Duplicate Hint

Potential future advisory rule:

```text
same employee
same merchant
same date
same amount
```

→ possible duplicate

But human review decides.

This is a good future AI/rule-assisted feature.

---

# 57. Expense Policy

V1 should not attempt to implement a complete corporate expense policy engine.

Possible later rules include:

- meal limit
- hotel nightly limit
- receipt threshold
- mileage rate
- prohibited categories
- weekend expense warning

These can be added after core approval workflow is stable.

---

# 58. Finance Review

A common expense workflow may look like:

```text
Requester Manager
      ↓
Finance
```

High-value:

```text
Requester Manager
      ↓
Department Manager / Director
      ↓
Finance
```

These routes belong in Workflow Configuration.

ExpenseClaimService must not hard-code them.

---

# 59. Approval Timeline

Claim detail should show:

```text
Submitted by Alice

Manager Approval
Approved by John

Finance Review
Waiting for Mary
```

Timeline is driven by shared ApprovalAction/runtime records.

---

# 60. Activity Logging

Important Expense Claim changes may generate:

```text
EXPENSE_CLAIM_UPDATED
EXPENSE_CLAIM_WITHDRAWN
```

Changed fields worth auditing may include:

- item amount
- item category
- expense date
- merchant
- claim total

Approval-specific history remains in ApprovalAction.

---

# 61. Claim Detail Page

Recommended sections:

```text
Header
- claim number
- status
- employee
- department

Business Purpose
- title
- description

Expense Items
- date
- merchant
- category
- description
- amount
- receipt status

Total

Attachments

Approval Timeline

Available Actions
```

---

# 62. Expense Item Display

Approvers should be able to review all relevant data in one view.

Example:

| Date | Merchant | Category | Description | Amount | Receipt |
|---|---|---|---|---:|---|
| Sep 4 | Grab | Travel | Airport transfer | RM45 | Yes |
| Sep 4 | Hotel | Travel | Customer visit | RM380 | Yes |
| Sep 5 | Restaurant | Meals | Client dinner | RM120 | Yes |

Avoid making approvers open each item on a separate page just to understand the claim.

---

# 63. Approval Efficiency

To speed review, the approval page should clearly highlight:

- total
- highest-value expenses
- missing receipts
- unusual items if future analysis exists
- approver comment history

The core system should already be efficient before AI is introduced.

---

# 64. List Page

Useful columns:

```text
Claim No
Employee
Department
Title
Total
Status
Submitted At
```

Useful filters:

```text
employee
department
status
date range
```

Use pagination.

---

# 65. Search

V1 search may support:

```text
claim number
employee name
title
merchant
```

Merchant search may require querying Expense Items.

Keep implementation simple and avoid unnecessary full-text infrastructure.

---

# 66. Reporting

Future reports may include:

```text
Expense by Department
Expense by Category
Expense by Employee
Monthly Expense Trend
Average Approval Time
```

V1 only needs enough structured data to support these later.

Do not build extensive BI features before workflow completion.

---

# 67. Database Relationships

Conceptually:

```text
ExpenseClaim
belongsTo User as employee

ExpenseClaim
belongsTo Department

ExpenseClaim
hasMany ExpenseItem

ExpenseClaim
morphMany Attachment

ExpenseClaim
morphMany ApprovalInstance
```

```text
ExpenseItem
belongsTo ExpenseClaim

ExpenseItem
belongsTo SpendCategory

ExpenseItem
morphMany Attachment
```

---

# 68. Suggested Indexes

Recommended:

```text
expense_claims:

UNIQUE(claim_no)

(employee_id, status)
(department_id, status)
(status)
(submitted_at)
```

For items:

```text
expense_items:

(expense_claim_id)
(category_id)
(expense_date)
```

Add indexes based on actual query patterns.

---

# 69. Delete Strategy

DRAFT claim:

may be hard-deleted if never submitted.

Submitted or historical claim:

```text
do not hard delete
```

Expense Items belonging to submitted claims must remain historical.

Do not cascade-delete historical financial data through ordinary application actions.

---

# 70. Draft Update Transaction

Updating:

```text
ExpenseClaim
+
multiple ExpenseItems
+
totals
```

should use a transaction when changes span multiple records.

This prevents:

```text
items updated
but total stale
```

---

# 71. Submit Transaction

Submission must atomically create/update:

```text
ExpenseClaim
ApprovalInstance
ApprovalStepInstances
ApprovalAssignments
ApprovalActions
```

If workflow start fails:

```text
claim remains DRAFT
```

---

# 72. Optimistic Concurrency

Draft and changes-requested edits should protect against stale updates.

Use:

```text
updated_at
```

or an equivalent version marker.

Example:

```text
Employee opens claim

Finance/request owner changes relevant data elsewhere

Employee submits stale version
```

Stale update should fail safely.

---

# 73. Double Submit

Two submit requests must result in:

```text
one active ApprovalInstance
```

Follow:

```text
docs/architecture/workflow-concurrency.md
```

---

# 74. AI Extension — Receipt Extraction

Expense Claim is a natural V2 AI module.

Possible flow:

```text
Upload Receipt
      ↓
AI/OCR Extraction
      ↓
Suggest:
merchant
expense date
amount
tax
category
      ↓
Employee confirms
      ↓
Create / update Expense Item
```

AI output is not authoritative until validated.

---

# 75. AI Category Suggestion

Future AI may suggest:

```text
Grab receipt
→ Travel
```

or:

```text
Restaurant receipt
→ Meals
```

The employee confirms the category.

Workflow routing uses the validated persisted category, not raw AI output.

---

# 76. AI Approval Summary

Future approver view may show:

```text
Employee requests RM1,245 for a 2-day customer visit.

Largest expenses:
Hotel RM700
Flight RM400
Transport RM95

All required receipts are attached.
```

This can speed review without changing approval authority.

---

# 77. AI Anomaly Hints

Possible future hints:

- same-looking receipt submitted twice
- amount significantly higher than usual
- merchant/category mismatch
- expense on unusual date
- missing receipt information

These are advisory.

Do not automatically reject the claim.

---

# 78. AI Failure

AI failure must not block normal manual claim creation.

Employee must still be able to:

```text
enter expense manually
attach receipt
submit normally
```

Core workflow remains independent of AI.

---

# 79. Tests — Create Draft

Authenticated employee can create their own DRAFT claim.

Verify:

- employee_id derived from authenticated user
- department derived correctly
- status DRAFT
- claim number generated
- no ApprovalInstance created

---

# 80. Tests — Ownership

Employee A cannot:

- edit Employee B claim
- submit Employee B claim
- withdraw Employee B claim

---

# 81. Tests — Expense Date

Future expense date should fail validation under V1 rules.

Past/today valid date succeeds.

---

# 82. Tests — Item Amount

Zero or negative amount rejected.

Valid decimal amount accepted.

---

# 83. Tests — Claim Total

Given items:

```text
RM45
RM380
RM120
```

expected:

```text
total_amount = RM545
```

calculated server-side.

---

# 84. Tests — Ignore Frontend Total

Frontend submits:

```text
total_amount = 1.00
```

while actual item total is:

```text
545.00
```

Expected:

```text
stored / routed total = 545.00
```

---

# 85. Tests — Tax Does Not Double Count

Item:

```text
amount = RM100
tax_amount = RM6
```

Expected contribution to claim total:

```text
RM100
```

not:

```text
RM106
```

under the V1 gross-amount convention.

---

# 86. Tests — Receipt Required

Expense Item requires receipt but has none.

Submission fails.

Claim remains DRAFT.

---

# 87. Tests — Receipt Attached

Required item has authorized valid receipt attachment.

Submission may proceed.

---

# 88. Tests — Routing Category

Claim items:

```text
Travel RM800
Meals RM100
Parking RM20
```

Expected routing category:

```text
Travel
```

---

# 89. Tests — Submit

Valid claim submission creates:

- ApprovalInstance
- expected step runtime
- first assignment
- SUBMITTED action

and changes claim to:

```text
IN_APPROVAL
```

---

# 90. Tests — No Workflow

If no matching valid workflow exists:

```text
submission fails
claim remains DRAFT
no partial runtime exists
```

---

# 91. Tests — Request Changes

Finance requests missing receipt.

Expected:

```text
ExpenseClaim → CHANGES_REQUESTED
```

Employee may edit and attach receipt.

---

# 92. Tests — Non-Material Resubmit

Employee adds missing receipt only.

Expected:

```text
same ApprovalInstance
previous Manager approval remains
current Finance step resumes
```

---

# 93. Tests — Amount Route Change

Original:

```text
RM800
```

Updated:

```text
RM8,000
```

New workflow route differs.

Expected:

```text
old ApprovalInstance CANCELLED
new ApprovalInstance created
restart from Step 1
```

---

# 94. Tests — Category Route Change

Changing expense item values/categories causes derived routing category to change.

If matching Rule Group changes:

new ApprovalInstance is created according to resubmission rules.

---

# 95. Tests — Final Approval

Final step completes:

```text
ExpenseClaim → APPROVED
approved_at set
ApprovalInstance → APPROVED
```

---

# 96. Tests — Reject

Valid rejection:

```text
ExpenseClaim → REJECTED
rejected_at set
```

No V1 resubmission.

---

# 97. Tests — Withdraw

Employee withdraws eligible claim.

Expected:

```text
ExpenseClaim → WITHDRAWN
ApprovalInstance → CANCELLED
pending runtime non-actionable
```

---

# 98. Tests — Double Submit

Two submit attempts create one active runtime only.

---

# 99. Tests — Stale Edit

Stale version update is rejected rather than overwriting newer changes.

---

# 100. Tests — Inactive Category

Inactive category cannot be selected for new or edited expense item.

Historical item remains readable.

---

# 101. V1 Scope

V1 Expense Claim includes:

```text
Employee-owned draft

Multiple Expense Items

Item-level categories

Merchant

Expense date

Receipt attachment

Receipt validation

Backend claim total

Workflow routing

Submit

Approve

Reject

Request Changes

Resubmit

Withdraw

Approval Timeline

Finance visibility

Authorization

Feature Tests
```

---

# 102. Deferred Features

Do not implement initially:

- actual reimbursement payment
- payroll integration
- mileage calculator
- per diem engine
- travel advance
- foreign exchange conversion
- corporate cards
- expense policy engine
- receipt OCR requirement
- automatic duplicate blocking
- tax recovery
- mobile receipt capture
- autonomous AI approval

These may be added after the core workflow is complete.

---

# 103. Core Principles

Expense Claim follows:

```text
Employee claims money already paid personally.

The claim contains multiple expense items.

Each item owns its spend category.

Receipts attach to individual items.

Backend calculates the authoritative claim total.

Workflow routing uses normalized derived data.

Submitted financial data cannot change freely.

Request Changes allows correction.

Material route changes trigger workflow re-evaluation.

Approval means ready for reimbursement,
not already reimbursed.

AI may assist later,
but manual entry always remains possible.
```

---

# 104. Summary

Expense Claim flow:

```text
Employee Creates Draft
       ↓
Add Expense Items
       ↓
Attach Receipts
       ↓
Backend Calculates Total
       ↓
Determine Routing Category
       ↓
Submit
       ↓
WorkflowResolver
       ↓
Approval Runtime
       ↓
Manager / Finance Approval
       ↓
APPROVED
```

The Expense Claim module owns reimbursement-request data.

The shared Workflow Engine owns routing.

Approval Runtime owns execution and history.

Actual reimbursement remains outside V1 scope.
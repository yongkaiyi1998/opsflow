# OpsFlow User Guide

## 1. Introduction

OpsFlow manages Purchase Requests, Supplier Invoices, and Expense Claims through configurable approval workflows. A record starts as a **Draft**, is submitted to a matching Workflow, and moves through its assigned approval steps until it is approved, rejected, returned for changes, or withdrawn.

OpsFlow calculates authoritative totals, selects the workflow route, and records approval history on the server. AI can extract document data, suggest categories, summarize records, flag observations, explain a route, and help draft text. **AI is advisory:** a person must verify extracted data and an assigned approver must make every approval decision.

## 2. Roles and access

Access also depends on whether your account is active, your ownership of a record, and any current approval assignment.

| Role or responsibility | Implemented access |
| --- | --- |
| **Employee** | Create and manage their own Purchase Requests and, when assigned to a department, Expense Claims. Use Quotation Intake and Receipt Intake for their own uploads. View records they own and approval items currently assigned to them. |
| **Finance** | Create and manage Supplier Invoices and Invoice Intake batches. View Expense Claims for finance operations. Finance users can also use employee features where applicable. Finance visibility does not grant approval authority. |
| **Administrator (Admin)** | View Purchase Requests, Supplier Invoices, and Expense Claims; manage Users, Departments, Spend Categories, Vendors, and Workflows. Admin access does not automatically grant approval authority. |
| **Approver** | Approver is an assignment, not a separate global role. Any active user may act only on their own current, pending Approval Assignment. A requester cannot approve their own record. |

Inactive users cannot sign in or perform record, intake, or approval actions.

## 3. Dashboard and navigation

The **Dashboard** shows My drafts, Waiting approval, Changes requested, My approval inbox, recently approved records, and quick actions. Finance users also see finance-operation totals; Admin users see configuration and workflow-publication summaries.

The sidebar groups links under:

- **Main:** Dashboard and the business modules available to your account, including the corresponding intake pages.
- **Work:** Approval Inbox and Notifications.
- **Administration:** Users, Departments, Spend Categories, Vendors, and Workflows; visible to Admin users.

On narrow screens, use the menu button to open navigation. If a link is absent, your role or record context does not permit that area.

## 4. Purchase Requests

### Create and edit a Draft

Open **Purchase Requests** and select **New purchase request**. Enter the title, business justification, category, optional vendor and needed-by date, and one or more items. Quantities are whole numbers. OpsFlow derives the requester and department from your account and recalculates subtotal, tax, and total when the record is saved.

While the request is **Draft**, its owner can use **Edit request**, add or remove private attachments, or **Delete draft**. A deleted request number is not reused.

### Submit and follow the workflow

Review the saved record and select **Submit for approval**. OpsFlow validates the persisted request, resolves the applicable published Workflow, and creates the approval route. The status becomes **In Approval**. Submitted records cannot be edited while approval is active.

If an approver selects **Request changes**, the request becomes **Changes Requested**. Read the approver's comment, select **Edit request**, save the correction, add an optional Resubmission note, and select **Resubmit for approval**.

The owner may select **Withdraw** while the request is **In Approval** or **Changes Requested**. A Withdrawn or Rejected request cannot be resubmitted in the current version.

The detail page shows the current status, authoritative totals, attachments, and **Approval timeline**. The timeline retains submissions, approver actions, resubmissions, withdrawals, and prior workflow history.

## 5. Supplier Invoices

Supplier Invoices are available to Finance and Admin users.

### Manual creation

Open **Supplier Invoices** and select **New supplier invoice**. Choose an active vendor, department, and spend category; enter the supplier invoice number, dates, description, tax, and line items. OpsFlow recalculates line subtotals and invoice totals. The same normalized invoice number cannot be used twice for one vendor. Add a private invoice document before submission.

### Invoice Intake

1. Open **Invoice Intake** and select **Upload batch**.
2. Upload one or more supported PDF or image invoice documents. Each file is stored privately and processed separately.
3. Open the batch and use **View extraction** for a document. Depending on its state, select **Process document** or **Retry extraction** when available.
4. When extraction reaches **Needs Verification**, compare candidate values with **Open original**.
5. Review extraction checks, advisory vendor matches, and **Possible duplicates**. Vendor matches must be explicitly selected. Duplicate warnings identify records worth comparing; they do not prove that the invoice is a duplicate.
6. Correct all fields and line items, choose the authoritative vendor, department, and category, then select **Verify and create draft**.

Verification creates a normal Supplier Invoice **Draft** with the original stored as its private invoice document. It does not submit the invoice. Review the Draft and then use **Submit for approval**. Changes Requested, resubmission, withdrawal, status, and approval history follow the lifecycle described for Purchase Requests.

## 6. Expense Claims

### Manual creation

Open **Expense Claims** and select **New expense claim**. Add one or more items with category, expense date, merchant, description, gross amount, and informational tax included. The claim total is the sum of gross item amounts; tax is not added again. Your employee identity and department come from your account. After saving, attach a private receipt to each item marked as requiring one.

### Receipt Intake

1. Open **Receipt Intake** and select **Upload receipts**.
2. Upload a batch of supported PDF or image receipts. Extraction is queued for each receipt.
3. Open the batch to monitor each document. Wait for extraction, or retry a failed document when the action is available.
4. When every receipt is ready, select **Verify receipts**.
5. Compare every extracted candidate with its original receipt, correct the values, and select a Spend Category for every item. Category selection is human-confirmed even when AI provides a suggestion.
6. Select **Create Expense Claim draft**.

One verified batch creates one Expense Claim **Draft**; each receipt becomes one Expense Claim item and its original remains a private item attachment. The Draft is not submitted automatically. Review it, satisfy receipt requirements, and select **Submit for approval**. The standard Changes Requested, resubmission, withdrawal, status, and approval-history behavior then applies.

## 7. Purchase Quotation Intake

1. Open **Quotation Intake** and select **Upload quotations**.
2. Upload supported PDF or image quotation files. Uploading starts extraction but creates no Purchase Request.
3. Open a quotation and review its extraction state. Process or retry it when the corresponding action is available.
4. Select **Verify quotation** when candidate data is ready.
5. Compare the candidate with the private original. Correct the Purchase Request fields and items, and explicitly confirm the vendor and Spend Category. OpsFlow recalculates the authoritative total.
6. Select **Create Purchase Request Draft**.

Each verified quotation creates an ordinary Purchase Request **Draft** with the original attached privately. The Draft is **not automatically submitted**; review and submit it through the normal Purchase Request flow. OpsFlow does not provide an RFQ workflow or quotation comparison/ranking.

## 8. Approval Inbox

**Approval Inbox** lists only current, pending assignments for you. Select **Review** to open the business record, attachments you are authorized to access, routing context, and **Approval timeline**.

Before acting, check the authoritative record and **Review assistance**:

- **System Checks** are deterministic checks produced by OpsFlow.
- **AI Summary** and **AI Observations** are optional advisory context and may be marked **Out of date** after a record changes.
- **Why am I approving this?** always shows authoritative route facts. When AI is enabled, **Explain with AI** adds an optional plain-language explanation; it does not choose the route.

Available decisions are:

- **Approve:** accepts the current step. A comment is optional.
- **Request changes:** returns the record for correction. **Required changes** is mandatory; **Draft comment with AI** can produce editable wording when AI is enabled.
- **Reject:** ends the approval process. **Rejection reason** is mandatory; AI can draft editable wording when enabled.

Only the first valid committed action on an active assignment succeeds. If another approver has already completed an ANY step, or the record state has changed, the old assignment is no longer actionable.

## 9. AI Assistance

AI controls appear only when AI is enabled and the current page supports them.

- **Category Suggestion / Suggest category:** recommends an existing active Spend Category. You decide whether to use it.
- **AI Summary:** condenses persisted record facts for review.
- **AI Observations / Attention Flags:** point out items that may deserve attention. They are observations, not proven facts or decisions.
- **Workflow Explanation:** explains persisted route facts in plain language; deterministic Workflow rules remain authoritative.
- **Writing Assistance:** **Improve with AI** can draft a Purchase Request justification or Expense Claim description. **Draft comment with AI** can draft request-changes or rejection text. Always review and edit before saving or acting.
- **System Checks vs AI Observations:** System Checks are deterministic application results. AI Observations are optional model output and can be unavailable, incomplete, or stale.

AI never sets authoritative totals, selects approvers, submits a Draft, or approves/rejects a record. Normal manual workflows remain usable when AI is unavailable.

## 10. Notifications

Open **Notifications** for workflow assignments and important status changes. Unread items show an indicator and contribute to the navigation count. Select the notification message to open its authorized target, or use **Mark as read**. Notifications are informational; the authoritative status remains on the business record and Approval timeline.

## 11. Administration

Admin users can manage:

- **Users:** create or edit user identity, role, department, manager, and active status.
- **Departments:** maintain the departments used for ownership and routing.
- **Spend Categories:** maintain the categories used by records and Workflow rules.
- **Vendors:** maintain supplier data used by requests, invoices, and intake matching.
- **Workflows:** maintain module-specific approval templates, rule groups, conditions, ordered steps, and approver sources.

Workflow versions have three states:

- **Draft:** editable and not used for new submissions. Admins can configure, validate and publish, or delete an unused Draft.
- **Published:** the active version used for new submissions. It is immutable; use **Clone to draft** to make a change.
- **Archived:** a replaced historical version. It remains immutable and available for approval history.

Publishing a new version archives the previous Published version. Existing approval instances retain the version with which they started.

## 12. Common statuses

### Business records

| Status | Meaning |
| --- | --- |
| **Draft** | Editable record that has not entered approval. |
| **In Approval** | Submitted and moving through its approval steps. |
| **Changes Requested** | An approver returned it for correction; the owner may edit and resubmit or withdraw. |
| **Approved** | All required approval steps completed. This does not mean a PO, payment, or reimbursement was executed. |
| **Rejected** | Approval ended with rejection; the record is retained and cannot be resubmitted in the current version. |
| **Withdrawn** | The owner cancelled an active or changes-requested approval; history is retained and the record cannot be resubmitted in the current version. |

### Document Intake

| Status | Meaning |
| --- | --- |
| **Pending** | Uploaded and waiting for extraction. If AI is disabled, it remains Pending. |
| **Processing** | Extraction is running through the queue. |
| **Needs Verification** | Candidate data is ready for human review. |
| **Verified** | A person confirmed the data and the corresponding Draft was created. |
| **Failed** | Extraction did not complete; open the document for the reason and retry when available. |

Batch counters may also show **Skipped**, a completed, non-actionable document state.

## 13. Common questions and troubleshooting

### Why can't I edit this record?

Business records are editable only by the permitted owner or managing role while **Draft** or **Changes Requested**. Records in active approval or final states are preserved. You also cannot edit another employee's record merely by knowing its URL.

### Why can't I approve it?

You need your own current, pending Approval Assignment, an active account, and an actionable record. A broad Finance or Admin role is not enough, self-approval is blocked, and another approver may already have completed the step.

### Why is AI unavailable?

AI may be disabled by configuration, unsupported on that page, or temporarily unavailable because the configured provider timed out or failed. Continue with the manual workflow where possible and contact an administrator if AI should be enabled.

### Why is a document still Pending?

Extraction may be waiting for the queue worker. When AI is disabled, uploaded documents remain Pending until it is enabled. If processing fails, open the document to review the message and use the retry action when available.

### Why must AI extraction be verified?

Extracted values are untrusted candidates. Human verification confirms the original document, vendor/category choices, dates, and line items before OpsFlow creates a business Draft and recalculates authoritative totals.

### Why was a possible duplicate flagged?

OpsFlow found matching evidence such as vendor, normalized invoice number, amount, or date. The warning asks you to compare records; it does not automatically block or declare a duplicate. Exact vendor and invoice-number uniqueness still applies when the Draft is created.

### Why didn't creating a draft submit it for approval?

Manual entry and every intake verification deliberately create an editable **Draft**. Review attachments, categories, line items, and totals, then explicitly select **Submit for approval**. AI never submits on your behalf.

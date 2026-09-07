# Workflow Overview

## 1. Purpose

The Workflow Engine is the shared approval subsystem used by:

- Purchase Requests
- Supplier Invoices
- Expense Claims

Its purpose is to:

- determine which approval workflow applies
- determine which approval steps are required
- determine who should approve each step
- create the runtime approval process
- preserve approval history

The Workflow Engine does not make approval decisions itself.

Humans approve or reject requests.

---

# 2. Core Principle

The workflow subsystem is divided into two major parts:

```text
Workflow Configuration
        ↓
Approval Runtime
```

These two parts must remain separate.

Configuration describes:

> What should happen?

Runtime records:

> What actually happened?

---

# 3. Workflow Configuration

Workflow configuration defines how future requests should be routed.

Main concepts:

```text
WorkflowTemplate
    ↓
WorkflowVersion
    ↓
WorkflowRuleGroup
    ↓
WorkflowRule
    ↓
WorkflowStep
```

Configuration is managed by administrators.

---

# 4. Approval Runtime

Approval Runtime represents the actual approval process created for one submitted business record.

Main concepts:

```text
ApprovalInstance
    ↓
ApprovalStepInstance
    ↓
ApprovalAssignment
    ↓
ApprovalAction
```

Runtime data is historical business data and must not be treated as editable workflow configuration.

---

# 5. High-Level Submission Flow

When a business record is submitted:

```text
Purchase Request
Supplier Invoice
Expense Claim
        ↓
Create WorkflowContext
        ↓
WorkflowResolver
        ↓
Select WorkflowVersion
        ↓
Select Matching Rule Group
        ↓
WorkflowEngine
        ↓
Create Approval Runtime
        ↓
Activate First Step
        ↓
Assign Approver
        ↓
Request enters IN_APPROVAL
```

If a valid workflow cannot be resolved, submission must fail safely.

The system must never silently bypass approval.

---

# 6. WorkflowContext

The three business modules have different database structures.

The Workflow Engine should not depend directly on every model field.

Instead, each module provides a normalized `WorkflowContext`.

Conceptually:

```text
WorkflowContext
---------------
module_type
requester_id
department_id
category_id
amount
currency
```

Only workflow-relevant information belongs in the context.

This keeps the Workflow Engine independent from module-specific details.

---

# 7. WorkflowResolver

`WorkflowResolver` determines which workflow configuration applies.

Its responsibility is:

```text
WorkflowContext
        ↓
Find Current Published Workflow
        ↓
Evaluate Rule Groups
        ↓
Return Matching Workflow Version + Route
```

It does not:

- create approval records
- approve requests
- send notifications
- update business statuses

---

# 8. WorkflowEngine

`WorkflowEngine` converts the selected workflow configuration into runtime approval records.

Its responsibilities include:

- create `ApprovalInstance`
- create runtime step records
- activate the current step
- resolve the required approver
- create approval assignments

It does not perform approve/reject actions.

Those belong to `ApprovalService`.

---

# 9. ApprovalService

`ApprovalService` owns runtime approval state transitions.

Typical operations:

```text
approve
reject
requestChanges
resubmit
withdraw
```

It is responsible for moving the workflow forward safely.

Example:

```text
Manager approves
      ↓
Manager step completes
      ↓
Finance step activates
      ↓
Finance receives assignment
```

---

# 10. Approver Resolution

Workflow Steps should not normally contain hard-coded business logic.

Approvers are resolved using explicit strategies.

Examples:

```text
REQUESTER_MANAGER
DEPARTMENT_MANAGER
ROLE
SPECIFIC_USER
```

Example:

```text
Step 1
REQUESTER_MANAGER
```

may resolve to:

```text
John
```

for one request and:

```text
Mary
```

for another.

The workflow definition remains reusable.

---

# 11. Sequential Approval Model

V1 uses sequential approval.

Example:

```text
Manager
   ↓
Finance
   ↓
Director
```

Only the active step may be acted on.

Future steps remain inactive until the current step completes.

Parallel approval is intentionally deferred.

---

# 12. Typical Approval Lifecycle

```text
DRAFT
  ↓ submit

IN_APPROVAL
  ├── final approval ──→ APPROVED
  │
  ├── reject ─────────→ REJECTED
  │
  └── request changes → CHANGES_REQUESTED
                              ↓
                           resubmit
                              ↓
                         IN_APPROVAL
```

State transitions must go through the responsible service.

Controllers must not directly set approval states.

---

# 13. Request Changes

`CHANGES_REQUESTED` is different from rejection.

It means:

> The request may continue after the requester corrects or supplies information.

Example:

```text
Manager ✓
Finance → Request Changes
```

After correction:

```text
Manager remains completed
Finance resumes
```

unless workflow-relevant data changed enough to require a new route.

Detailed resubmission behavior is documented separately.

---

# 14. Workflow Versioning

Workflow configuration changes over time.

Example:

```text
Version 1

Manager
→ Director
```

Later:

```text
Version 2

Manager
→ Finance
→ Director
```

A request already submitted under Version 1 must remain on Version 1.

New requests may use Version 2.

The workflow used by an existing ApprovalInstance must not silently change.

Detailed versioning rules are documented separately.

---

# 15. Approval History

Every meaningful approval action must be preserved.

Examples:

```text
SUBMITTED
APPROVED
REJECTED
CHANGES_REQUESTED
RESUBMITTED
WITHDRAWN
DELEGATED
```

Approval history supports:

- auditability
- timeline display
- troubleshooting
- compliance
- reporting

Historical actions must not be erased when the workflow advances.

---

# 16. Approval Inbox

The runtime model supports a simple operational inbox.

Conceptually:

```text
ApprovalAssignment
where
approver = current user
and status = PENDING
```

This produces:

```text
My Approval Inbox
```

The inbox should show exactly what the current user needs to act on.

The system should not recalculate complete workflow routing every time the inbox loads.

---

# 17. Notifications

Approval runtime events may trigger notifications.

Examples:

```text
ApprovalAssigned
RequestApproved
RequestRejected
ChangesRequested
RequestResubmitted
```

Notifications are side effects.

They do not determine workflow state.

Core approval state must remain correct even if notification delivery fails.

---

# 18. Error Philosophy

Workflow failures must fail safely.

Examples:

- no workflow configured
- requester has no manager
- required role has no eligible approver
- invalid published configuration

The system must not:

- skip required approval
- silently approve
- guess an approver

Instead, it should surface a clear configuration or operational error.

---

# 19. V1 Scope

The initial Workflow Engine supports:

- one main workflow family per business module
- workflow versioning
- simple rule-based routing
- prioritized rule groups
- sequential approval steps
- requester manager
- department manager
- role-based approver
- specific user approver
- approval runtime
- approval inbox
- approve
- reject
- request changes
- resubmit
- withdraw
- approval history

---

# 20. Deferred Features

The following are intentionally deferred:

- parallel branches
- graphical workflow builder
- BPMN
- arbitrary scripting
- nested Boolean rule trees
- AI-based routing
- weighted voting
- percentage approval
- multi-company workflow inheritance
- complex escalation chains

These may be added later if a real requirement justifies them.

---

# 21. Related Documents

Detailed behavior is documented separately:

```text
docs/architecture/workflow-configuration.md
docs/architecture/workflow-rules.md
docs/architecture/workflow-runtime.md
docs/architecture/workflow-resubmission.md
docs/architecture/workflow-concurrency.md
```

Module-specific behavior is documented in:

```text
docs/modules/purchase-request.md
docs/modules/supplier-invoice.md
docs/modules/expense-claim.md
```

---

# 22. Summary

The Workflow Engine follows this responsibility chain:

```text
Business Record
      ↓
WorkflowContext
      ↓
WorkflowResolver
      ↓
Workflow Configuration
      ↓
WorkflowEngine
      ↓
Approval Runtime
      ↓
ApprovalService
      ↓
Human Action
```

The most important architectural rule is:

```text
Workflow Configuration
≠
Approval Runtime
```

Configuration defines future approval behavior.

Runtime preserves the actual approval process for a specific request.
# Workflow Configuration

## 1. Purpose

Workflow Configuration defines how future business requests should be routed for approval.

It is managed by administrators and applies to:

- Purchase Requests
- Supplier Invoices
- Expense Claims

Configuration defines:

- workflow family
- workflow versions
- rule groups
- approval steps
- approver resolution strategy

It does not contain actual approval history.

---

# 2. Configuration Structure

The configuration hierarchy is:

```text id="nd0am2"
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

Each layer has a distinct responsibility.

---

# 3. WorkflowTemplate

A `WorkflowTemplate` represents one logical approval workflow family.

Examples:

```text id="9m5mvy"
Purchase Request Approval
Supplier Invoice Approval
Expense Claim Approval
```

Suggested fields:

```text id="zxbpw9"
id
name
code
module_type
description
status
created_at
updated_at
```

Suggested `module_type` values:

```text id="azx9mx"
PURCHASE_REQUEST
SUPPLIER_INVOICE
EXPENSE_CLAIM
```

Suggested `status` values:

```text id="sd5x7n"
ACTIVE
INACTIVE
```

---

# 4. One Main Template Per Module

V1 should keep configuration simple.

Recommended:

```text id="w4nhc7"
PURCHASE_REQUEST
→ one main WorkflowTemplate

SUPPLIER_INVOICE
→ one main WorkflowTemplate

EXPENSE_CLAIM
→ one main WorkflowTemplate
```

Different approval paths are handled by Rule Groups inside the active Workflow Version.

This avoids creating too many overlapping workflow templates.

---

# 5. WorkflowVersion

A `WorkflowVersion` represents one specific published definition of a workflow.

Suggested fields:

```text id="itp4mu"
id
workflow_template_id
version
status
effective_from nullable
effective_until nullable
created_by
published_by nullable
published_at nullable
created_at
updated_at
```

Suggested statuses:

```text id="a5fjs6"
DRAFT
PUBLISHED
ARCHIVED
```

Suggested unique constraint:

```text id="js6fr4"
UNIQUE(workflow_template_id, version)
```

---

# 6. Version Lifecycle

Typical lifecycle:

```text id="3j8pnx"
Create Draft
   ↓
Configure
   ↓
Validate
   ↓
Publish
   ↓
Published
   ↓
Archived when replaced
```

Only DRAFT versions may be edited.

PUBLISHED versions are immutable.

ARCHIVED versions remain historical records.

---

# 7. Why Published Versions Are Immutable

Suppose Version 1 contains:

```text id="35a3s4"
> RM10,000
Manager → Director
```

Later the business changes policy:

```text id="3txkrt"
> RM10,000
Manager → Finance → Director
```

Do not edit Version 1.

Instead:

```text id="d8n56m"
Version 1
→ remains unchanged

Version 2
→ contains the new configuration
```

Existing approval instances continue using Version 1.

New submissions may use Version 2.

---

# 8. Editing Rules

For a DRAFT version, administrators may edit:

- rule groups
- rules
- steps
- approver types
- step order
- display names

For a PUBLISHED version, these must not be edited.

If change is needed:

```text id="ce9l6a"
Clone Published Version
        ↓
Create New Draft
        ↓
Edit
        ↓
Publish
```

---

# 9. Version Numbering

Use simple sequential integer versions:

```text id="2xmkqq"
1
2
3
4
```

Do not use semantic versioning such as:

```text id="p7tfxr"
1.2.4
```

Workflow version numbers represent policy revisions, not software releases.

---

# 10. Active Published Version

V1 should allow only one active published version per template.

Recommended behavior:

When Version 3 is published:

```text id="z8d37a"
Version 2
→ ARCHIVED

Version 3
→ PUBLISHED
```

Existing ApprovalInstances referencing Version 2 remain valid.

Only new submissions use Version 3.

---

# 11. Effective Dates

The schema may reserve:

```text id="x2dsjj"
effective_from
effective_until
```

V1 may keep usage simple.

Recommended initial behavior:

- published version becomes active immediately
- only one active published version per template

Future versions may support scheduled activation.

Do not introduce scheduled policy activation unless required.

---

# 12. WorkflowRuleGroup

A `WorkflowRuleGroup` represents one routing scenario.

Suggested fields:

```text id="w5yxca"
id
workflow_version_id
name
priority
is_default
created_at
updated_at
```

Examples:

```text id="nmf2df"
Low Value
Medium Value
High Value
IT High Value
Default
```

A Rule Group contains:

- zero or more rules
- one or more approval steps

---

# 13. Rule Group Priority

Rule groups are evaluated in explicit priority order.

Recommended:

```text id="6in5mp"
lower number = higher priority
```

Example:

```text id="s55rpm"
10  IT Software > RM10,000
20  Any Request > RM10,000
30  RM1,001–10,000
40  <= RM1,000
999 Default
```

If multiple groups match:

```text id="6kz7mu"
first matching group wins
```

Evaluation order must be deterministic.

---

# 14. Default Rule Group

A WorkflowVersion should have at most one default Rule Group.

The default group is used when no specific group matches.

Recommended validation:

```text id="b5g8mh"
0 or 1 default group allowed
```

For production use, having exactly one default group is strongly preferred.

If no rule matches and no default exists:

submission must fail safely.

---

# 15. WorkflowRule

A `WorkflowRule` defines one condition inside a Rule Group.

Suggested fields:

```text id="ip6e7x"
id
workflow_rule_group_id
field
operator
value
created_at
updated_at
```

Detailed rule evaluation behavior belongs in:

```text id="r6zjbw"
workflow-rules.md
```

---

# 16. Rule Groups Use AND in V1

Inside one Rule Group:

```text id="b2pppl"
Rule A
AND
Rule B
AND
Rule C
```

Example:

```text id="68ku5f"
amount > 10000
AND
department = IT
AND
category = SOFTWARE
```

Do not support arbitrary nested Boolean expressions in V1.

---

# 17. WorkflowStep

A `WorkflowStep` defines one ordered approval stage.

Suggested fields:

```text id="71o6ol"
id
workflow_rule_group_id
step_order
name
approver_type
approver_value nullable
approval_mode
minimum_approvals nullable
sla_hours nullable
created_at
updated_at
```

Example:

```text id="3pgg0r"
1  Manager Approval
2  Finance Review
3  Director Approval
```

---

# 18. Step Ordering

Each Rule Group must have a deterministic step order.

Suggested constraint:

```text id="96l99v"
UNIQUE(workflow_rule_group_id, step_order)
```

Step order should normally begin at:

```text id="ki9cwp"
1
```

and be sequential.

Admin validation should prevent:

```text id="uqchq4"
1
3
8
```

unless the system explicitly supports gaps later.

---

# 19. Approver Types

V1 supported approver types:

```text id="c4ut1s"
REQUESTER_MANAGER
DEPARTMENT_MANAGER
ROLE
SPECIFIC_USER
```

Each type has different configuration requirements.

---

# 20. REQUESTER_MANAGER

Configuration:

```text id="3l7gp0"
approver_type = REQUESTER_MANAGER
approver_value = null
```

At runtime, the approver is resolved from the requester’s direct manager relationship.

No user ID should be stored in the workflow step.

---

# 21. DEPARTMENT_MANAGER

Configuration:

```text id="evuw58"
approver_type = DEPARTMENT_MANAGER
approver_value = null
```

At runtime, the approver is resolved from the request’s department.

---

# 22. ROLE

Configuration example:

```text id="k4r31y"
approver_type = ROLE
approver_value = FINANCE
```

The configured role must exist and be valid.

V1 may use ANY approval mode for role-based steps.

---

# 23. SPECIFIC_USER

Configuration example:

```text id="ku9kzk"
approver_type = SPECIFIC_USER
approver_value = 27
```

The configured user must exist.

The user should also be active at configuration validation time.

Runtime eligibility is checked again when the step activates.

---

# 24. Approval Mode

The schema may reserve:

```text id="v4gwkw"
ANY
ALL
MINIMUM
```

V1 should implement only:

```text id="2ib7hv"
ANY
```

This keeps the first version easy to understand.

ALL and MINIMUM may be added later without redesigning the table.

---

# 25. SLA Field

`WorkflowStep` may reserve:

```text id="rhl32t"
sla_hours
```

V1 may leave SLA behavior unimplemented.

The field exists to support later:

- reminders
- overdue detection
- escalation

Do not let SLA affect approval correctness.

---

# 26. Publish Validation

Before a WorkflowVersion can be published, validate the configuration.

At minimum:

- WorkflowTemplate exists
- version status is DRAFT
- at least one Rule Group exists
- at most one default Rule Group exists
- each Rule Group has at least one Step
- every Step has valid order
- every Step has a supported approver type
- ROLE values are valid
- SPECIFIC_USER values reference valid users
- rules use supported fields
- rules use supported operators
- required rule values are present
- no duplicate step order exists

Invalid workflow configuration must not be published.

---

# 27. Configuration Warnings

Some cases may not block publication but should produce warnings.

Examples:

- same user appears in multiple sequential steps
- overlapping Rule Groups
- no default Rule Group
- inactive user selected as SPECIFIC_USER
- unusually large number of steps

Warnings help administrators catch mistakes without requiring a complex validation engine.

---

# 28. Self-Approval Risk

Configuration may result in self-approval at runtime.

Example:

```text id="njr82e"
Requester
=
Department Manager
```

V1 should not try to solve every self-approval case during configuration.

Instead:

- configuration may be published
- runtime approver resolution detects self-approval
- submission fails safely

A future version may support explicit skip or escalation behavior.

---

# 29. Inactive Template

If a WorkflowTemplate is INACTIVE:

new requests must not resolve against it.

Existing ApprovalInstances remain unaffected.

Disabling a template does not delete historical configuration.

---

# 30. Archiving

ARCHIVED WorkflowVersions remain readable.

They may still be referenced by historical ApprovalInstances.

Do not delete archived workflow versions.

Archiving means:

```text id="7g8vq9"
not available for new submissions
```

not:

```text id="3ibd6o"
safe to delete
```

---

# 31. Delete Strategy

Workflow configuration has historical importance.

Do not hard-delete:

- published WorkflowVersions
- archived WorkflowVersions
- Rule Groups referenced by published versions
- Steps referenced by published versions

Draft versions may be deletable if they have never been published or referenced.

Prefer conservative deletion behavior.

---

# 32. Clone Version

Creating a new version should ideally support cloning the current published version.

Conceptually:

```text id="x5eslw"
Published Version 3
      ↓
Clone
      ↓
Draft Version 4
```

Clone:

- Rule Groups
- Rules
- Steps

but not:

- ApprovalInstances
- Approval history

This makes policy changes faster and safer.

---

# 33. Admin Workflow Configuration Flow

Recommended UI flow:

```text id="gqip3a"
Workflow Template
      ↓
Versions
      ↓
Open Draft
      ↓
Configure Rule Groups
      ↓
Configure Rules
      ↓
Configure Steps
      ↓
Validate
      ↓
Publish
```

The admin UI should make the current state obvious.

Example:

```text id="a6zlcm"
Version 3
PUBLISHED

Version 4
DRAFT
```

---

# 34. Readability Over Power

Workflow configuration should be understandable by a normal administrator.

Prefer:

```text id="sgm3o3"
Amount > RM10,000
Department = IT
```

over complicated expressions.

The goal is not to build a programming language.

The goal is to make business approval policy configurable and predictable.

---

# 35. Configuration Example

Purchase Request workflow:

```text id="iwnl6r"
Template:
Purchase Request Approval

Version:
3
```

Rule Group 1:

```text id="urcrrf"
Name:
IT High Value

Priority:
10

Rules:
amount > 10000
department = IT

Steps:
1 Requester Manager
2 Finance
3 Specific Director
```

Rule Group 2:

```text id="xj9h3o"
Name:
Standard High Value

Priority:
20

Rules:
amount > 10000

Steps:
1 Requester Manager
2 Finance
```

Default Group:

```text id="uqm1z2"
Priority:
999

Steps:
1 Requester Manager
```

---

# 36. Configuration and Runtime Boundary

Configuration stores strategies such as:

```text id="s4eeoa"
REQUESTER_MANAGER
ROLE = FINANCE
```

Runtime stores actual resolved people such as:

```text id="yuw8f2"
John
Mary
```

Do not store actual runtime assignments back into WorkflowStep.

This separation is required for reuse and historical accuracy.

---

# 37. Model Relationships

Conceptually:

```text id="utxdtn"
WorkflowTemplate
hasMany WorkflowVersion
```

```text id="ynae4j"
WorkflowVersion
belongsTo WorkflowTemplate
hasMany WorkflowRuleGroup
```

```text id="2vh0t5"
WorkflowRuleGroup
belongsTo WorkflowVersion
hasMany WorkflowRule
hasMany WorkflowStep
```

```text id="mlo5qs"
WorkflowRule
belongsTo WorkflowRuleGroup
```

```text id="bxyxgd"
WorkflowStep
belongsTo WorkflowRuleGroup
```

---

# 38. Recommended Indexes

Suggested indexes:

```text id="mq8hpe"
workflow_templates:
(module_type, status)
```

```text id="70t9hd"
workflow_versions:
(workflow_template_id, status)
```

```text id="pfr20r"
workflow_rule_groups:
(workflow_version_id, priority)
```

```text id="pzyo0t"
workflow_rules:
(workflow_rule_group_id)
```

```text id="ah54e6"
workflow_steps:
(workflow_rule_group_id, step_order)
```

---

# 39. Critical Configuration Tests

## Publish Valid Workflow

A valid DRAFT version publishes successfully.

---

## Reject Invalid Rule

Unsupported field or operator prevents publication.

---

## Duplicate Step Order

Two steps with the same order must fail validation.

---

## Immutable Published Version

Editing a PUBLISHED version must be rejected.

---

## Clone Version

Cloning Version 2 creates a new DRAFT Version 3 with equivalent rules and steps.

---

## Publish New Version

Publishing a new version archives the previously active version.

Historical ApprovalInstances remain linked to their original version.

---

## Default Group Constraint

More than one default Rule Group must fail validation.

---

# 40. Summary

Workflow Configuration defines reusable approval policy.

The main structure is:

```text id="3f84dg"
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

Key principles:

```text id="fohtjy"
Drafts are editable.

Published versions are immutable.

New policy changes create new versions.

Rule Groups define routing scenarios.

Steps define approval order.

Configuration stores approver strategies,
not actual runtime assignees.

Historical workflow configuration is preserved.
```

The goal is to keep approval policy flexible without making configuration difficult to understand.
# Workflow Versioning Decision

## 1. Decision

Published workflow versions are immutable.

When approval policy changes, OpsFlow creates a new `WorkflowVersion` instead of modifying the existing published version.

Existing ApprovalInstances continue using the WorkflowVersion that was selected when they were created.

---

## 2. Why This Is Required

Approval history must remain explainable.

Example:

Version 1:

```text
Amount > RM10,000
Manager → Director
```

Later policy changes to:

```text
Amount > RM10,000
Manager → Finance → Director
```

If Version 1 were edited in place, an old request could appear to have followed a workflow that did not exist when it was submitted.

That would damage:

- auditability
- historical accuracy
- troubleshooting
- approval traceability

Therefore published workflow configuration must not be rewritten.

---

## 3. Version Lifecycle

Use:

```text
DRAFT
→ PUBLISHED
→ ARCHIVED
```

Only `DRAFT` versions may be edited.

Once published:

```text
rules
rule groups
steps
approver strategy
```

are immutable.

To change policy:

```text
Published Version
      ↓
Clone
      ↓
New Draft Version
      ↓
Edit
      ↓
Publish
```

---

## 4. Active Version

V1 keeps version selection simple.

Each WorkflowTemplate has one active published version for new submissions.

When a new version is published:

```text
previous active version → ARCHIVED
new version → PUBLISHED
```

Archived versions remain available for historical ApprovalInstances.

They must not be deleted.

---

## 5. Submission Behavior

When a request is submitted:

```text
Business Record
      ↓
WorkflowResolver
      ↓
Current Published WorkflowVersion
      ↓
ApprovalInstance
```

The selected version is stored on the ApprovalInstance.

Example:

```text
ApprovalInstance.workflow_version_id = 3
```

That relationship remains stable for the lifetime of the approval runtime.

---

## 6. New Version Does Not Affect Existing Runtime

Example:

```text
Request A
submitted using Version 2
```

Admin later publishes:

```text
Version 3
```

Request A continues using:

```text
Version 2
```

A new Request B uses:

```text
Version 3
```

Never automatically migrate an in-progress ApprovalInstance to a newer version.

---

## 7. Request Changes Without Material Routing Change

Suppose Request A uses Version 2.

While it is in `CHANGES_REQUESTED`, Admin publishes Version 3.

Requester only:

- adds a receipt
- clarifies description
- uploads another attachment

The request should continue using:

```text
Version 2
```

because its approval routing has not materially changed.

---

## 8. Material Resubmission

If workflow-relevant data changes during resubmission, such as:

```text
amount
department
category
```

the system re-runs workflow resolution.

If the effective route changes enough to require a new ApprovalInstance:

```text
old ApprovalInstance → CANCELLED
new ApprovalInstance → created
```

The new ApprovalInstance uses the currently applicable published WorkflowVersion.

Example:

```text
Old runtime → Version 2

Admin publishes Version 3

Requester changes amount materially

New runtime → may use Version 3
```

The old runtime remains preserved.

---

## 9. Why Material Resubmission May Use the New Version

A materially changed request represents a new approval decision.

The old approval was based on:

```text
old business facts
+
old policy
```

After a material change, the new approval should normally use:

```text
current business facts
+
current active policy
```

This is more accurate than forcing the new request facts through an outdated policy.

---

## 10. No In-Place Runtime Upgrade

Do not transform an existing ApprovalInstance from:

```text
Version 2
```

into:

```text
Version 3
```

in place.

If policy/routing must change materially:

```text
preserve old runtime
create new runtime
```

This keeps history clear.

---

## 11. Version Numbers

Use simple sequential integers:

```text
1
2
3
4
```

Recommended uniqueness:

```text
UNIQUE(workflow_template_id, version)
```

Do not use semantic software-style version numbers such as:

```text
1.2.3
```

Workflow versions represent business policy revisions.

---

## 12. Deletion

Never hard-delete a WorkflowVersion that has been:

- published
- archived
- referenced by an ApprovalInstance

Draft versions that have never been published or referenced may be deletable.

---

## 13. Database Relationships

Conceptually:

```text
WorkflowTemplate
    hasMany WorkflowVersion
```

```text
ApprovalInstance
    belongsTo WorkflowVersion
```

Historical ApprovalInstances must retain a valid reference to their original version.

Do not cascade-delete workflow versions from ApprovalInstances.

---

## 14. Critical Tests

### Existing Runtime Keeps Old Version

```text
Request A submits under V1
Publish V2
Request A remains on V1
```

### New Request Uses New Version

```text
Publish V2
Request B submits
Request B uses V2
```

### Published Version Cannot Be Edited

Attempting to modify rules or steps belonging to a published version must fail.

### Clone Creates Draft

```text
V2 PUBLISHED
→ clone
→ V3 DRAFT
```

V2 remains unchanged.

### Non-Material Resubmission

```text
Request uses V2
V3 published
receipt added
resubmit
```

Expected:

```text
same ApprovalInstance
still V2
```

### Material Resubmission

```text
Request uses V2
V3 published
amount changes materially
route changes
```

Expected:

```text
old runtime preserved
new runtime uses current applicable version
```

---

## 15. Decision Summary

OpsFlow follows these rules:

```text
Draft workflow versions are editable.

Published workflow versions are immutable.

Policy changes create new versions.

Existing ApprovalInstances keep their original version.

New submissions use the current published version.

Non-material resubmission keeps the existing runtime/version.

Material routing changes may create a new runtime using current policy.

Historical versions are preserved.
```

The main reason is simple:

> Approval history must always reflect the policy that actually governed the request at that time.
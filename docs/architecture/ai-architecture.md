# AI Architecture

## Purpose

OpsFlow uses AI as an assistive layer around deterministic business workflows.

> AI extracts, summarizes, suggests, explains, and flags.  
> Deterministic rules validate and route.  
> Humans verify and approve.

The core application must continue to work when AI is disabled or unavailable.

## Core Boundaries

AI may:
- Extract candidate data from invoices and receipts.
- Suggest vendors and spend categories.
- Detect possible duplicates or unusual similarities.
- Summarize business records.
- Produce attention observations.
- Explain existing workflow decisions.
- Draft user-editable text.

AI must not:
- Approve or reject requests.
- Decide workflow routing.
- Bypass Policies, validation, or authorization.
- Directly create authoritative business records from unverified output.
- Override server-calculated money values.
- Directly submit generated comments or records.

Model output is always treated as untrusted input.

## Provider Layer

Business features must not depend directly on OpenAI, Ollama, or another vendor.

Suggested boundary:

AI Feature Service  
→ AiManager / AiProvider  
→ Cloud API or Local AI Endpoint

Prefer an OpenAI-compatible HTTP provider where practical so cloud and local services can share the same integration.

Configuration should support:
- `AI_ENABLED`
- `AI_PROVIDER`
- `AI_BASE_URL`
- `AI_MODEL`
- `AI_API_KEY`
- `AI_TIMEOUT`

Secrets must never be committed.

Feature-specific services own prompts, schemas, and domain validation.

Examples:
- DocumentExtractionService
- CategorySuggestionService
- SummaryService
- AttentionFlagService
- WorkflowExplanationService
- WritingAssistantService

## Structured Output

Features that affect application UI or candidate data must request structured output.

AI responses must be validated before use:
- expected schema
- allowed enums
- decimal strings
- required fields
- valid existing record IDs
- malformed or incomplete responses

AI-generated database IDs must never be trusted without checking authoritative records and authorization.

Money continues to use existing OpsFlow Money rules. AI totals are informational until recalculated by the backend.

## AI Interaction Trace

AI calls should be traceable through an `ai_interactions` record containing concepts such as:
- feature
- provider
- model
- subject type/id
- prompt version
- status
- input fingerprint
- structured response
- latency
- error information
- actor
- timestamps

AI interaction history is not the authoritative business audit log.

Avoid storing unnecessary secrets or full sensitive documents.

## Batch Document Intake

Invoice intake uses a separate lifecycle from `SupplierInvoice`.

Upload N documents  
→ IntakeBatch  
→ DocumentIntake per file  
→ Queue extraction  
→ Needs Verification  
→ Human verifies  
→ SupplierInvoice DRAFT  
→ Normal OpsFlow workflow

One failed document must not fail the whole batch.

Suggested `DocumentIntake` states:
- PENDING
- PROCESSING
- NEEDS_VERIFICATION
- VERIFIED
- FAILED
- SKIPPED

Original documents remain private and authorization-protected.

AI processing must not add AI-specific states to the Supplier Invoice business lifecycle.

## Document Extraction

Invoices are the first supported document type.

Candidate fields may include:
- vendor
- invoice number
- invoice date
- due date
- currency
- line items
- quantity
- unit price
- subtotal
- tax
- total
- suggested category

Image/PDF handling may use OCR, multimodal models, or provider-specific capabilities.

OpsFlow does not require one fixed OCR strategy.

AI extraction produces candidate data only.

## Human Verification

Verification is the boundary between AI output and business data.

AI candidate data  
→ schema validation  
→ human review/edit  
→ server validation  
→ authoritative money recalculation  
→ create SupplierInvoice DRAFT

AI must never submit the draft for approval.

Verification must be transactional and idempotent so retries or double-clicks cannot create duplicate invoices.

Users must be able to reopen the original uploaded document while reviewing extracted data.

## Vendor Matching

Vendor matching is advisory.

Prefer deterministic matching first using available signals such as normalized vendor name and existing vendor data.

AI may assist ambiguous matches.

The model must not silently create vendors or replace vendor references.

The selected vendor remains human-confirmed and backend-validated.

## Smart Duplicate Detection

Duplicate detection is hybrid, not an LLM-only decision.

Compare new documents against:
- existing Supplier Invoices
- previous unverified Document Intakes
- documents in the same upload batch

Useful signals include:
- vendor
- normalized invoice number
- amount
- invoice date proximity
- line-item or text similarity

Possible outcomes:
- exact duplicate
- high similarity
- medium similarity
- no meaningful match

A duplicate similarity score is an application score, not AI confidence.

AI may explain why records look similar but must not fabricate matching evidence.

Possible duplicates require human review rather than automatic deletion.

## Category Suggestion

AI may recommend an existing active Spend Category.

The model must not invent authoritative category IDs.

Suggested flow:

authoritative category candidates  
→ AI suggestion  
→ validate returned category  
→ show suggestion + reason  
→ user accepts/rejects  
→ normal backend validation

## Summary and Attention Flags

AI Summary is advisory display content only.

Authoritative totals, statuses, routing, and approval decisions always come from OpsFlow.

### System Checks

Deterministic facts such as:
- exact duplicate
- total mismatch
- invalid reference
- missing required data

### AI Observations

Soft observations such as:
- vague business justification
- unclear purpose
- semantic similarity
- unusual description/context

AI observations must be presented as observations, not proven facts.

## Workflow Explanation

AI never calculates the workflow route.

The existing WorkflowVersion, RuleGroup, WorkflowStep, ApprovalInstance, and Assignment data remain authoritative.

AI may convert those facts into human-readable explanations such as “Why am I approving this?”

## Writing Assistance

AI may draft:
- improved business justification
- request-changes comments
- rejection comments

Generated text stays editable and requires explicit user submission.

Nothing is auto-submitted.

## Queue and Failure Handling

Slow AI work, especially document extraction, runs through Laravel queues.

External AI calls must not occur inside authoritative database transactions.

Jobs must support:
- timeout
- retry
- partial batch failure
- safe reprocessing
- idempotency
- provider outages
- malformed responses

AI failure must not break normal OpsFlow approval workflows.

## Security

Treat uploaded documents and model responses as untrusted data.

Protect against:
- IDOR and unauthorized document access
- prompt injection inside invoices/receipts
- model-generated HTML/XSS
- malformed structured responses
- hallucinated record IDs
- sensitive data leakage to providers
- leaked API keys
- oversized or unsupported files
- excessive logging of private content

Document text is data, not instructions.

Never render raw model output as trusted HTML.

## Prompt Management

Prompts should be:
- feature-specific
- versioned
- kept out of controllers/jobs
- paired with a structured output schema where applicable

Store prompt/schema versions with AI interactions for debugging and reproducibility.

## Testing

Normal automated tests must use a fake AI provider.

Cover:
- structured response validation
- malformed responses
- provider failure
- authorization
- queue retry/idempotency
- verification concurrency
- duplicate detection
- vendor/category validation
- safe HTML rendering

Real cloud/local model tests are optional integration or manual checks and should not be required by CI.

## Implementation Sequence

1. AI01 — AI Foundation & Provider Abstraction
2. AI02 — AI Persistence, Queue & Structured Output
3. AI03 — Batch Document Intake
4. AI04 — Document Extraction
5. AI05 — Human Verification & Draft Creation
6. AI06 — Vendor Matching & Smart Duplicate Detection
7. AI07 — Category Suggestion
8. AI08 — AI Summary & Attention Flags
9. AI09 — Workflow Explanation & Writing Assistance
10. AI10 — AI Security, Hardening, Tests & Portfolio Polish

Avoid microservices, autonomous agents, vector databases, and complex RAG infrastructure unless a concrete future requirement justifies them.

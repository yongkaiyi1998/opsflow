# OpsFlow 用户指南

## 1. 简介

OpsFlow 用于管理采购申请（Purchase Request）、供应商发票（Supplier Invoice）和费用报销单（Expense Claim），并通过可配置的审批工作流（Workflow）完成审批。记录从 **Draft（草稿）** 开始；提交后，系统会匹配相应的 Workflow，并按审批步骤依次流转，直至获批、被拒绝、退回修改或被撤回。

OpsFlow 在服务器端计算权威金额、选择工作流路线，并记录审批历史。AI 可以提取文档数据、建议类别、汇总记录、标记观察项、解释审批路线和协助起草文字。**AI 仅提供辅助建议：**提取的数据必须由人工核验，所有审批决定也必须由获分配的审批人作出。

## 2. 角色与访问权限

访问权限还取决于账户是否有效、记录所有权，以及是否存在当前审批任务。

| 角色或职责 | 已实现的权限 |
| --- | --- |
| **Employee（员工）** | 创建并管理自己的 Purchase Request；如已分配部门，还可创建和管理自己的 Expense Claim。可使用 Quotation Intake 和 Receipt Intake 处理自己上传的文件。可查看本人记录及当前分配给自己的审批事项。 |
| **Finance（财务）** | 创建和管理 Supplier Invoice 及 Invoice Intake 批次。可查看 Expense Claim 以进行财务运营管理。适用时，Finance 用户也可使用员工功能。财务可见性不等同于审批权限。 |
| **Administrator / Admin（管理员）** | 查看 Purchase Request、Supplier Invoice 和 Expense Claim；管理 Users、Departments、Spend Categories、Vendors 和 Workflows。Admin 权限不会自动赋予审批权限。 |
| **Approver（审批人）** | Approver 是具体任务职责，并非独立的全局角色。任何有效用户只能处理当前分配给本人且仍为 Pending 的 Approval Assignment。申请人不能审批自己的记录。 |

停用的用户无法登录，也不能执行记录、Intake 或审批操作。

## 3. Dashboard 与导航

**Dashboard** 显示 My drafts、Waiting approval、Changes requested、My approval inbox、最近获批记录和快捷操作。Finance 用户还会看到财务运营统计；Admin 用户会看到配置和 Workflow 发布摘要。

侧边栏分为：

- **Main：**Dashboard，以及当前账户可使用的业务模块和对应 Intake 页面。
- **Work：**Approval Inbox 和 Notifications。
- **Administration：**Users、Departments、Spend Categories、Vendors 和 Workflows；仅 Admin 用户可见。

在窄屏设备上，使用菜单按钮打开导航。如果某个链接没有显示，说明当前角色或记录上下文无权进入该区域。

## 4. Purchase Requests（采购申请）

### 创建和编辑 Draft

打开 **Purchase Requests**，选择 **New purchase request**。填写标题、业务理由、类别、可选供应商和需求日期，并添加一个或多个项目。数量必须是整数。OpsFlow 会根据当前账户确定申请人和部门，并在保存时重新计算小计、税额和总额。

申请处于 **Draft** 时，所有者可以使用 **Edit request**、添加或删除私有附件，或选择 **Delete draft**。已删除的申请编号不会被重复使用。

### 提交并跟进 Workflow

检查已保存的记录后，选择 **Submit for approval**。OpsFlow 会验证数据库中的申请、解析适用的已发布 Workflow，并创建审批路线。状态随后变为 **In Approval**。审批进行期间不能编辑已提交记录。

如果审批人选择 **Request changes**，申请会变为 **Changes Requested**。阅读审批人的意见，选择 **Edit request** 并保存修改；可填写 Resubmission note，然后选择 **Resubmit for approval**。

申请所有者可在状态为 **In Approval** 或 **Changes Requested** 时选择 **Withdraw**。在当前版本中，Withdrawn 或 Rejected 的申请不能重新提交。

详情页显示当前状态、权威金额、附件和 **Approval timeline**。时间线会保留提交、审批操作、重新提交、撤回和以往 Workflow 历史。

## 5. Supplier Invoices（供应商发票）

Supplier Invoice 仅供 Finance 和 Admin 用户使用。

### 手动创建

打开 **Supplier Invoices**，选择 **New supplier invoice**。选择有效的 Vendor、Department 和 Spend Category；填写供应商发票编号、日期、说明、税额和明细项目。OpsFlow 会重新计算明细小计和发票总额。同一 Vendor 不能重复使用相同的规范化发票编号。提交前必须添加私有发票文档。

### Invoice Intake

1. 打开 **Invoice Intake**，选择 **Upload batch**。
2. 上传一个或多个支持的 PDF 或图片发票文档。每个文件都会私有存储并单独处理。
3. 打开批次，对某个文档选择 **View extraction**。根据文档状态，在可用时选择 **Process document** 或 **Retry extraction**。
4. 当提取状态变为 **Needs Verification** 时，使用 **Open original** 对照候选数据。
5. 检查提取校验项、建议性的 Vendor 匹配结果和 **Possible duplicates**。Vendor 匹配结果必须由用户明确选择。重复警告只表示这些记录值得比较，并不证明发票一定重复。
6. 更正所有字段和明细项目，选择权威的 Vendor、Department 和 Category，然后选择 **Verify and create draft**。

核验完成后，系统会创建普通的 Supplier Invoice **Draft**，原始文件会作为其私有发票文档保存。系统不会自动提交发票。检查 Draft 后，再使用 **Submit for approval**。Changes Requested、重新提交、撤回、状态和审批历史的行为与 Purchase Request 相同。

## 6. Expense Claims（费用报销单）

### 手动创建

打开 **Expense Claims**，选择 **New expense claim**。添加一个或多个项目，每个项目包含 Category、Expense Date、Merchant、Description、Gross Amount，以及仅供参考的 Tax Included。报销单总额是所有项目 Gross Amount 的总和；税额不会再次加到总额中。员工身份和部门来自当前账户。保存后，为每个标记为需要收据的项目添加私有收据。

### Receipt Intake

1. 打开 **Receipt Intake**，选择 **Upload receipts**。
2. 上传一批支持的 PDF 或图片收据。系统会为每张收据排队执行提取。
3. 打开批次查看每个文档的进度。等待提取完成；如果操作可用，可重试失败的文档。
4. 所有收据都准备好后，选择 **Verify receipts**。
5. 将每项提取候选数据与原始收据逐一比较，更正数据，并为每个项目选择 Spend Category。即使 AI 给出建议，Category 仍必须由人工确认。
6. 选择 **Create Expense Claim draft**。

一个已核验批次会创建一个 Expense Claim **Draft**；每张收据会成为一个 Expense Claim Item，原始文件则作为该项目的私有附件保留。Draft 不会自动提交。检查记录并满足收据要求后，选择 **Submit for approval**。此后使用标准的 Changes Requested、重新提交、撤回、状态和审批历史流程。

## 7. Purchase Quotation Intake（采购报价单导入）

1. 打开 **Quotation Intake**，选择 **Upload quotations**。
2. 上传支持的 PDF 或图片报价文件。上传会启动提取，但不会创建 Purchase Request。
3. 打开某份报价并检查提取状态。在相应操作可用时处理或重试。
4. 候选数据准备好后，选择 **Verify quotation**。
5. 将候选数据与私有原始文件对照。更正 Purchase Request 字段和项目，并明确确认 Vendor 和 Spend Category。OpsFlow 会重新计算权威总额。
6. 选择 **Create Purchase Request Draft**。

每份已核验报价会创建一个普通的 Purchase Request **Draft**，原始报价文件会作为私有附件保存。Draft **不会自动提交**；请通过正常 Purchase Request 流程检查并提交。OpsFlow 不提供 RFQ Workflow，也不提供报价比较或排名功能。

## 8. Approval Inbox（审批收件箱）

**Approval Inbox** 只列出当前分配给你且仍为 Pending 的任务。选择 **Review**，可查看业务记录、你有权访问的附件、审批路线信息和 **Approval timeline**。

操作前，请检查权威业务记录和 **Review assistance**：

- **System Checks** 是 OpsFlow 生成的确定性检查结果。
- **AI Summary** 和 **AI Observations** 是可选的辅助信息；记录改变后，可能标记为 **Out of date**。
- **Why am I approving this?** 始终显示权威的审批路线事实。启用 AI 后，**Explain with AI** 可补充通俗解释，但不会选择审批路线。

可执行的决定包括：

- **Approve：**批准当前步骤。审批意见可选。
- **Request changes：**将记录退回修改。必须填写 **Required changes**；启用 AI 时，可使用 **Draft comment with AI** 生成可编辑的措辞。
- **Reject：**终止审批流程。必须填写 **Rejection reason**；启用 AI 时，也可生成可编辑的草稿。

一个有效任务只接受第一个成功提交的有效操作。如果其他审批人已完成 ANY 步骤，或记录状态已经改变，旧任务将不能再操作。

## 9. AI Assistance（AI 辅助）

只有在 AI 已启用且当前页面支持时，AI 操作才会显示。

- **Category Suggestion / Suggest category：**推荐现有且有效的 Spend Category；是否采用由用户决定。
- **AI Summary：**汇总数据库中已保存的记录事实，便于审核。
- **AI Observations / Attention Flags：**指出可能需要关注的内容。它们是观察项，不是已证实事实或决定。
- **Workflow Explanation：**用通俗语言解释已保存的审批路线事实；确定性的 Workflow 规则始终具有权威性。
- **Writing Assistance：****Improve with AI** 可协助起草 Purchase Request 的业务理由或 Expense Claim 说明；**Draft comment with AI** 可起草 Request Changes 或 Reject 的意见。保存或执行操作前必须人工检查和编辑。
- **System Checks 与 AI Observations：**System Checks 是确定性的应用检查结果；AI Observations 是可选的模型输出，可能不可用、不完整或已过时。

AI 不会设定权威金额、选择审批人、提交 Draft，也不会批准或拒绝记录。AI 不可用时，正常的手动流程仍可继续使用。

## 10. Notifications（通知）

打开 **Notifications** 查看审批任务和重要状态变化。未读通知会显示标记，并计入导航栏数量。选择通知内容可打开有权访问的目标，或使用 **Mark as read**。通知仅供提示；权威状态仍以业务记录和 Approval timeline 为准。

## 11. Administration（管理）

Admin 用户可以管理：

- **Users：**创建或编辑用户身份、角色、部门、直属经理和有效状态。
- **Departments：**维护用于所有权和审批路线的部门。
- **Spend Categories：**维护业务记录和 Workflow 规则使用的类别。
- **Vendors：**维护采购申请、发票和 Intake 匹配所用的供应商数据。
- **Workflows：**维护各业务模块的审批模板、Rule Groups、条件、顺序审批步骤和审批人来源。

Workflow Version 有三种状态：

- **Draft：**可编辑，不用于新的提交。Admin 可以配置、验证并发布，也可以删除未使用的 Draft。
- **Published：**用于新提交的当前有效版本。该版本不可修改；需要变更时使用 **Clone to draft**。
- **Archived：**被新版本取代的历史版本。该版本不可修改，并会保留用于审批历史。

发布新版本时，先前的 Published 版本会变为 Archived。现有审批实例仍保留其启动时使用的版本。

## 12. 常见状态

### 业务记录

| 状态 | 含义 |
| --- | --- |
| **Draft** | 尚未进入审批、仍可编辑的记录。 |
| **In Approval** | 已提交，正在按审批步骤流转。 |
| **Changes Requested** | 审批人已退回修改；所有者可以编辑后重新提交，或撤回。 |
| **Approved** | 所有必要审批步骤已完成。这不表示已创建 PO、执行付款或完成报销。 |
| **Rejected** | 审批以拒绝结束；记录会保留，在当前版本中不能重新提交。 |
| **Withdrawn** | 所有者撤回了审批中或被退回修改的记录；历史会保留，在当前版本中不能重新提交。 |

### Document Intake

| 状态 | 含义 |
| --- | --- |
| **Pending** | 已上传，正在等待提取。AI 停用时会保持 Pending。 |
| **Processing** | 提取任务正在队列中运行。 |
| **Needs Verification** | 候选数据已准备好，等待人工核验。 |
| **Verified** | 人工已确认数据，并创建了相应 Draft。 |
| **Failed** | 提取未完成；打开文档查看原因，并在操作可用时重试。 |

批次统计还可能显示 **Skipped**，即已完成且无需再操作的文档状态。

## 13. 常见问题与故障排查

### 为什么我不能编辑这条记录？

业务记录只有在状态为 **Draft** 或 **Changes Requested** 时，才允许相应所有者或管理角色编辑。审批中和最终状态的记录会被保留。仅知道其他员工记录的 URL 也不能获得编辑权限。

### 为什么我不能审批？

你必须拥有属于自己的、当前仍为 Pending 的 Approval Assignment，账户必须有效，而且记录仍可操作。仅有 Finance 或 Admin 角色并不足够；系统也禁止自我审批。该步骤还可能已被另一位审批人完成。

### 为什么 AI 不可用？

AI 可能在配置中被停用、当前页面不支持该功能，或配置的 AI Provider 暂时超时或失败。在可行时继续使用手动流程；如果 AI 本应启用，请联系管理员。

### 为什么文档一直处于 Pending？

提取任务可能正在等待 Queue Worker。AI 停用时，上传的文档会保持 Pending，直至 AI 启用。如果处理失败，请打开文档查看错误信息，并在可用时使用重试操作。

### 为什么必须人工核验 AI 提取结果？

提取值只是未受信任的候选数据。人工核验会对照原始文档，确认 Vendor / Category、日期和明细项目；之后 OpsFlow 才会创建业务 Draft，并重新计算权威金额。

### 为什么系统标记了可能重复？

OpsFlow 找到了 Vendor、规范化发票编号、金额或日期等相似证据。该警告用于提醒用户比较记录，不会自动阻止处理，也不会直接认定为重复。创建 Draft 时，系统仍会强制执行 Vendor 与发票编号的精确唯一性规则。

### 为什么创建 Draft 后没有自动提交审批？

手动录入和所有 Intake 核验流程都会有意创建一个可编辑的 **Draft**。请检查附件、Category、明细项目和总额，然后明确选择 **Submit for approval**。AI 永远不会代替用户提交。

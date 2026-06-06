# Dashboard perfection audit

Last reviewed: 2026-06-06

This is a high-level engineering checklist. It intentionally contains no customer, employee, credential, token, filesystem-path, or operational-record data. The application continues to use its existing storage and business workflows.

## Shared controls verified or improved

- `includes/auth.php` is the shared admin/mixed-role authentication boundary. Authenticated workspaces now send private/no-store and `X-Robots-Tag: noindex, nofollow, noarchive` headers.
- `includes/customer_portal.php` and `includes/employee_portal.php` enforce their respective sessions and now apply the same private workspace headers.
- Shared CSRF helpers now provide a consistently escaped form field and a friendly HTTP 419 failure. Missing CSRF protection was added to the mutation pages identified below.
- `assets/css/admin-unified.css` contains reusable page-header, action, empty-state, responsive-table, status-badge, keyboard-focus, and mobile-form primitives.
- File-backed storage, role behavior, existing public-token flows, and existing business workflows were preserved.

Legend: **Yes** means present/verified in this pass; **N/A** means the page has no mutation form; **Shared** means supplied by a shared helper/layout; **Follow-up** means a non-blocking browser review or legacy-style cleanup remains.

## Core and operational workspaces

| Page / path | Intended role | Key source/helper | Important actions | Auth | POST CSRF | Mobile / empty / messages | Findings and fixes |
|---|---|---|---|---|---|---|---|
| Admin dashboard — `admin-dashboard.php`, `admin-dashboard.js` | Admin | overview, complaints, leads, tasks, employees | module navigation, quick actions | Yes | N/A | Yes / Yes / Yes | Verified business-health, lead, complaint, task, reminder, quick-action and recent-work sections. |
| Employee dashboard — `employee-dashboard.php` | Employee | employee portal, customers, complaints, leads, tasks | assigned-work navigation and filters | Yes | N/A | Shared / Yes / Yes | Added shared responsive/focus styling; verified employee session boundary. |
| Customer dashboard — `customer-dashboard.php` | Customer | customer portal, complaints, customer records | raise complaint, open documents/support | Yes | **Yes, fixed** | Shared / Yes / Yes | Added CSRF and private-cache/noindex protection; retained privacy-safe session lookup. |
| Leads dashboard — `leads-dashboard.php` | Admin / employee | leads, audit log, customer and employee stores | create/import/update/bulk actions/settings | Yes | **Yes, fixed** | Yes / Yes / Yes | Added CSRF to all server mutation forms; preserved employee restrictions on admin settings/actions. |
| Lead detail — `lead-detail.php` | Admin / employee | leads and audit log | edit, convert, mark not interested | Yes | **Yes, fixed** | Shared / N/A / Yes | Added CSRF and shared responsive/focus styling. |
| Admin requests — `admin-requests.php` | Admin | customer admin/request storage | approve/reject requests | Yes | Yes | Yes / Yes / Yes | Verified. |
| Admin records — `admin-records.php` | Admin | customer records | browse/download records | Yes | N/A | Yes / Yes / Yes | Verified. |
| Admin complaints — `admin-complaints.php` | Admin | complaint and employee stores | create, assign, update, note | Yes | Yes | Yes / Yes / Yes | Verified. |
| Complaints overview — `complaints-overview.php` | Admin / employee | complaint and customer stores | quick actions, export, filters | Yes | **Yes, fixed** | Yes / Yes / Yes | Added CSRF to quick actions and export. |
| Complaint detail — `complaint-detail.php` | Admin / employee | complaint/customer stores | update and notify | Yes | **Yes, fixed** | Yes / N/A / Yes | Added CSRF while retaining role-sensitive access. |
| Public complaint form — `complaint.php` | Public customer intake | complaint helper | submit registered-customer complaint | Public intake | **Yes, fixed** | Shared / N/A / Yes | Added session CSRF without adding OTP or changing the intake workflow. |
| Admin tasks — `admin-tasks.php` | Admin | task and employee stores | assign, complete, archive | Yes | **Yes, fixed** | Yes / Yes / Yes | Added CSRF to all mutations. |
| Employee tasks — `employee-tasks.php` | Employee | employee portal and tasks | create/update/complete assigned tasks | Yes | **Yes, fixed** | Shared / Yes / Yes | Added CSRF, private-cache/noindex, and shared responsive styling. |
| Admin reminders — `admin-reminders.php` | Admin | reminders/request helpers | create/approve/reject/cancel | Yes | Yes | Yes / Yes / Yes | Verified. |
| Audit log — `audit-log.php` | Admin | audit log | inspect recent activity | Yes | N/A | Shared / Yes / N/A | Added shared responsive table/focus styling; private-cache/noindex comes from admin auth. |

## Documents, finance, handover, and customer/partner management

| Pages / paths inspected | Intended role | Key source/helper | Actions/forms | Auth | POST CSRF | UI / robustness notes |
|---|---|---|---|---|---|---|
| `admin-documents.php`, `admin-quotations.php`, `admin-agreements.php`, `admin-challans.php`, `admin-invoices.php`, `admin-proformas.php`, `admin-quote-settings.php`, `admin-templates.php` | Admin or approved mixed-role flow | document helpers / file-backed document stores | create, update, issue, archive, print/share | Yes | Yes | Existing lifecycle, status messages, filters, responsive wrappers, and safe error handling retained. |
| `quotation-view.php`, `receipt-view.php`, `agreement-view.php`, `challan-view.php`, `challan-print.php`, `agreement-print.php`, `agreement-pdf.php`, `download.php` | Authenticated or public-token, depending on document | public document security / renderers | view, accept where supported, print/download | Token/auth as designed | Yes where mutating | Existing noindex/private-cache public-document protections retained; no storage/routing rewrite. |
| `employee-documents.php`, `employee-quotations.php`, `employee-challans.php` | Employee | employee portal / document helpers | employee-scoped create/view | Yes | Yes | Verified employee session boundary and existing CSRF. |
| `admin-subsidy-tracker.php`, `admin-solar-finance-settings.php` | Admin | subsidy / solar finance helpers | update settings and tracking | Yes | Yes | Verified. |
| `admin-handover-templates.php`, `generate-handover.php` | Admin | handover helper | edit template, generate handover | Yes | **Yes, fixed** | Added CSRF without changing generated-file workflow. |
| `admin-users.php` | Admin / restricted employee portal mode | customer, employee, complaints, handover helpers | customer/employee CRUD, import, bulk actions | Yes | **Yes, fixed** | Added CSRF to both mutation handlers and every POST form; retained employee restrictions. |
| `admin-customer-import.php` | Admin | customer importer | CSV upload | Yes | **Yes, fixed** | Added CSRF; file import behavior unchanged. |
| `admin-referrers.php` | Admin | referrer/customer helpers | manage referrers | Yes | Yes | Verified. |
| Customer/employee pages linked from dashboards | Customer / employee | respective portal helper | scoped records, documents, tasks, complaints | Yes | Yes where mutating | Shared private/no-store and noindex headers now apply at portal login boundaries. |

## Marketing, content, website, and admin utilities

| Pages / paths inspected | Intended role | Key source/helper | Auth | POST CSRF | Notes |
|---|---|---|---|---|---|
| `admin-site-settings.php`, `admin/website-settings/index.php` | Admin | website settings | Yes | Yes | Verified settings validation/messages and existing preview behavior. |
| `admin-smart-marketing.php` | Admin | smart marketing / campaigns | Yes | Yes | Verified existing filters, state, messages, and empty handling. |
| `admin-blog.php`, `admin-blog-manager.php` | Admin | blog service | Yes | Yes | Verified publishing actions and messages. |
| `admin-ai-studio.php` | Admin | Gemini helper | Yes | Yes | Verified auth and mutation CSRF; provider behavior depends on configured credentials. |

## Routing, safety, and consistency checks

- Major dashboard links referenced by the audited pages were checked against repository files; no deliberate placeholder routes were added.
- Admin-only pages use `require_admin()` or an equivalent role gate; employee/customer portals use their respective portal session helpers.
- Authenticated pages now receive private/no-store and search-engine exclusion headers through shared auth boundaries.
- Mutation forms identified without protection received CSRF validation and hidden fields. JSON API routes retain their existing authenticated header-token pattern.
- Shared styling now provides intentional horizontal table scrolling, single-column mobile forms, visible keyboard focus, reusable empty states, action groups, and normalized status badges.
- Existing public-token document protections and recently added public lead intake flow were not changed.

## Remaining follow-up (honest limitations)

- Complete browser-based visual QA still requires representative admin, employee, and customer accounts plus realistic file-backed records. Check narrow-phone layouts, long names/notes, print pages, and every document lifecycle action before deployment.
- Several legacy pages still contain page-local CSS and could gradually adopt more shared classes. This is cosmetic debt, not an authorization or workflow blocker, and a mass redesign was intentionally avoided.
- Employee record-level permissions remain governed by the existing assignment and portal helper logic. Vishesh should decide whether employees should have narrower access to shared leads/complaints beyond the current rules.
- Public complaint intake intentionally remains available to registered customers by mobile number, as before. Vishesh should decide whether it should require a customer login in a future product change; OTP was not introduced.
- AI Studio and external share/notification actions require environment credentials or external services and need deployment-environment smoke testing.

### Additional privacy fix discovered during the audit

- `status.php` previously returned customer project, meter, finance, and complaint details after only a mobile-number lookup. It now exposes no customer record data and directs customers to the authenticated portal instead.
- `admin-dashboard.js` now attaches the shared CSRF token to every `api/admin.php` request and renders toast messages as text rather than HTML; `admin-dashboard.php` and `admin-records.php` expose the token through an escaped meta tag.

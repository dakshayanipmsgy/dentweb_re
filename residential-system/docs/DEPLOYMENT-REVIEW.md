# Exact production change for owner review

Current status: INSTALLED on 27 October 2026 after the owner's explicit approval. See LIVE-RELEASE-20261027.md for production evidence and the remaining administrator sign-in check. The scope and pre-installation observations below are retained as the original review record. No ad spending or customer outreach was performed.

## Existing state verified on 27 October

- Actual browser control and cPanel session work for tegwf0aqgwek / dakshayani.co.in. Home directory `/home/tegwf0aqgwek`; File Manager's `public_html` lists the live site, solar-enquiry and B2B files. Use home-relative `public_html` in the cPanel path field: typing the full home path was interpreted as a nested relative path on this UI.
- The public residential page opens in Hindi/English. It currently promises a WhatsApp handoff and does not provide the durable-save confirmation implemented here.
- Live commercial B2B page loads with its consent form. Recovered B2B code uses private source/runtime, atomic files, existing administrator auth, CSRF and queued notices. Those patterns are reused separately, not linked to the residential data store.
- Website Git changes already present: modified storage/site/website_settings.json and untracked solar-partners.html and tests/solar-partner-incentives.test.cjs. They were not edited. No applicable AGENTS.md was found in the workspace or checked ancestor locations.
- Existing .cpanel.yml targets `/portal` with rsync --delete. It is not used by this release.
- Gmail identity d.entranchi@gmail.com verified. Search from the portal sender after 24 October returned the two already-recorded selections and no further results in that query. All 13 local historical records are imported only into a separate local review store, preserving raw source and unknown current stage. Reimport adds zero duplicates.
- Existing Codex portal automation file is ACTIVE, twice daily at 09:30/17:30, with no customer outreach authority. Preserved without changes. Exact current scheduler execution/receipt is not proved by the config file alone.
- Meta UI verifies account 1590759519331604 / portfolio 584390436548097, 0 active campaigns, both residential pilots Completed. Account overview shows 160 hybrid and 193 on-grid views in its displayed window, which is not the same scope as historical combined356. No current spend total for exactly the two pilots was reconciled. No campaigns, drafts, budgets or pixels were changed.
- WhatsApp AI settings/ordinary conversation history have not been independently inspected in the current session. Historical configuration is retained as context, not claimed current. No authorised WhatsApp API is available in this build.

## Files to replace, narrowly

Under `/home/tegwf0aqgwek/public_html/solar-enquiry/`:

- index.html: same landing route, required phone + response consent, save-first form, removes the local-only review section awaiting reviewer-relationship confirmation; existing live page showed no such review section.
- app.js: validates, submits with CSRF and retry key, then shows receipt and optional handoff using reference only.
- language.js: matching English/Hindi form copy.
- style.css: consent and confirmation layout.
- pricing.js: package values generated from owner-confirmed source; no automatic hybrid/mixed subsidy deduction.
- privacy.html: explains response consent, optional measurement, correction/opt-out and private storage.

New public files:

- `/solar-enquiry/intake.php`: thin validated wrapper; fails closed until release ready.
- `/residential-enquiries.php`: thin wrapper using existing site administrator authentication. No new public login or password.
- `/residential-assets/desk.css`.

New private directory outside public_html:

`/home/tegwf0aqgwek/dakshayani-residential-live/` holds src/, config/pricebook.json, notify-worker.php, import-portal.php and a private release configuration. Runtime and backups are created privately with locking and restricted modes. The ZIP contains no customer records, runtime files, credentials or enabled release config.

Leave the homepage, root .htaccess, /portal, B2B public/private files, existing cron jobs, customer storage, invoices, uploads, payments and integrations untouched. Existing landing photographic assets are reused and excluded from the ZIP. Do not extract over a parent directory without checking the manifest.

## Installation after approval

1. Recheck cPanel identity, document root and current live-file hashes. Take a recoverable backup of only the files to be replaced outside public_html. Save the current B2B and residential route responses as regression baselines.
2. Upload the reviewed private application outside public_html and public files only to the listed paths. Preserve file permissions and source/runtime separation. Existing server PHP must pass syntax/runtime checks; local tests used PHP8.5 and the implementation requires PHP8+ with JSON, sessions and locking.
3. Before switching the public form, test an isolated authenticated QA wrapper with separate synthetic runtime. Verify administrator gate, unauthenticated denial, CSRF, file privacy, saving, history, mobile form and concurrent writes on hosting. Never use the local router.php or RES_LOCAL_QA bypass in production; neither is packaged.
4. Create release-config.php privately only after these checks: `return ['ready'=>true,'mail_enabled'=>true];`.
5. Add a separate residential cron entry every five minutes using the server's verified PHP CLI executable and `/home/tegwf0aqgwek/dakshayani-residential-live/notify-worker.php`; append output to a private runtime/notification-worker.log. Do not change existing jobs or the existing cron email preference. No SQL or Node production service is required.
6. Switch the listed public landing files, submit one clearly labelled synthetic enquiry, record the saved reference and verify exactly one private record. Retry it; verify same reference. Observe worker execution and actual receipt in d.entranchi@gmail.com. Mark that synthetic record unsuitable/test and exclude it from reporting (use the protected synthetic-test exclusion action, which records a reason/history and removes the fixture from business totals). Do not count this as a customer.
7. Recheck all three B2B routes and protected inbox; verify unchanged portal/customer data. If acceptance or alerts fail, roll back public files from the narrow backup and retain any newly accepted private records for reconciliation.
8. Reconcile historical portal/customer stages in a separate review before optionally importing into the live residential store. Never copy the local historical runtime or overwrite server sales.json.

## Rollback and recovery

Disable the residential worker and set ready=false. Back up the current private runtime first, including records accepted since launch. Restore only replaced public files; leave the private runtime intact. Restoring older code must not discard accepted enquiries.

For data recovery: pause intake and worker; acquire the same sales.lock; copy the damaged state for inspection; validate a chosen private snapshot's JSON schema, lead IDs, history, request keys and counts; restore to an isolated directory first; reconcile any later accepted records from preserved snapshots; atomically replace sales.json while holding the lock; release and rerun synthetic checks before reopening. Never initialise empty state over a corrupt file. Local isolated restore and corruption-fail-closed checks passed.

## Tracking and outreach limits

Local first-party saved-enquiry and handoff-click evidence is recorded separately. A residential dataset/pixel is not selected or enabled. Measurement consent defaults off; no Meta requests or CAPI credentials are included. Do not reuse the B2B dataset or old pixel by assumption. Public acceptance-event delivery must be tested only after the correct dataset and permission are verified. No new ads, messages to customers, paid services or financial commitments are included in this deployment approval.

## Remaining after installation

Actual hosted receipt/worker/authentication verification; residential dataset selection and test events; manual reconciliation of true sales and costs; fuller source-to-sale linkage and exception corrections. No acquisition launch is ready for approval yet. A later ad test requires exact dates, saved previews, audience, conversion event, total budget including tax, response capacity and stop rules.


# Verification record — 27 October 2026

## Verified live

Actual browser control, cPanel account identity and website file listing; public residential Hindi/English page; commercial B2B route; B2B staff route redirects to existing login when unauthenticated; existing B2B cron every five minutes using `/usr/local/bin/php` with a private worker/log; Gmail account identity and scoped recent portal-selection search; Meta authorised account/portfolio and completed residential pilots with zero active campaigns.

Existing Codex follow-up automation is configured ACTIVE at 09:30 and 17:30; configuration inspection is not evidence of the most recent successful scheduled run. No duplicate automation was created.

## Prepared locally, not installed

- Save-first residential form, required contact number and enquiry-specific permission; separate optional measurement permission defaults off.
- Private PHP/JSON record store, global lock, atomic commit, synced writes, pre-change snapshots, strict invalid-storage failure, request idempotency and conflict protection.
- Five accepted enquiries/hour per hashed IP, phone/district/bill/length validation, honeypot and CSRF. This is basic abuse protection, not verified protection against every coordinated spam attack.
- Same-phone review links, retained source/history, owner/action/deadline, outcome recording, cautious historical import, do-not-contact stop rule and correction history.
- Owner notification queue and retry worker, separate from customer outreach. Actual residential production mail delivery is not verified.
- Mobile staff desk, understandable fit/readiness gaps, human-review response drafts, versioned proposal drafts from controlled approved pricing, evidence requirements and separate outcome reporting.
- Private export, isolated restore verification, narrow manifest/ZIP and rollback instructions.

## Local tests passed

Core checks: retry identity, conflicting payload rejection, invalid phone/permission/district/bill/honeypot, matching-phone separation, preview suppression, persistent outcome/history, stale editor rejection, deposit evidence, incomplete qualification rejection, duplicate milestone reference rejection, opt-out suppression and reopen prevention, rate limiting, failed-alert retention/retry, accepted-alert deduplication, isolated exact restore, corruption-fail-closed and no overwrite of corrupt state.

HTTP checks: invalid CSRF403, invalid phone422, accepted201 durable record, same-reference retry, click-only handoff event, staff outcome persistence, and private route denial. Synthetic fixture server is loopback-only. The test router's local-only authentication bypass is excluded from the release.

Concurrent check: 16 separate PHP processes/submissions produced 16 complete distinct records and retry keys without a lost write. A first-run directory-creation race was found and fixed before the passing run.

Proposal check: approved mixed-package price, zero assumed subsidy, retained versions, and draft does not change enquiry into sent proposal.

Finance checks: controlled pricebook parity, known 2kW on-grid illustration, mixed-package price, no assumed mixed/hybrid subsidy, zero-bill and savings caps. Package prices confirmed by owner on 27 October; finance source checked separately below.

Browser checks: English step journey, invalid mobile leaves submission disabled, required response consent, saved reference visible before handoff, repeated retry same reference, preview clearly labelled/no owner alert. Success display initially remained inside a hidden form step; moved outside that step and verified visible on retest.

Mobile checks at actual390px: enquiry page content width390px; staff desk content width375px within390px viewport; required fields, consent and confirmation visually inspected. Temporary viewport reset. Review screenshots contain only synthetic data.

Historical import: 13 records added into isolated local historical-review runtime; repeat import adds0/already_present13. Original source records remain intact; none assigned a sale, deposit, installation or invented current stage.

PHP syntax checks passed across source and generated production PHP wrappers. JavaScript syntax checked. The release requires PHP8+; actual cPanel runtime behaviour remains to be tested after deployment approval.

## Current claim sources checked

- [SBI Surya Ghar loan](https://sbi.bank.in/web/personal-banking/loans/pm-surya-ghar-loan-for-solar-roof-top): published floating illustration tiers5.75% up to₹2lakh and7.90% above₹2lakh up to₹6lakh. The proposal builder blocks financing above the verified loan limit. Actual sanction and moratorium depend on lender terms.
- [JBVNL solar programme](https://www.jbvnl.co.in/solar-program): official local access point for current linked metering amendments, connection process and PM Surya Ghar operational guidance. No automatic approval timeline, tariff saving or export-credit promise is implemented.
- [Government central subsidy summary](https://solar.delhi.gov.in/page/central-subsidy): central residential schedule₹30,000/kW for first2kW and₹18,000 for next1kW, capped₹78,000. This does not settle eligibility of a specific mixed/hybrid package. No Delhi-specific subsidy is applied in Ranchi.

Mixed DCR/non-DCR subsidy remains unresolved. No eligibility conclusion was invented. Original public on-grid figures remain conditional examples; hybrid/mixed defaults deduct no subsidy. Proposal drafts deduct none until a separately confirmed contractual assessment.

## Not verified / not complete

Residential installation on actual hosting, hosted authenticated staff workflow, production email receipt, correct residential dataset/pixel and accepted-event delivery, current WhatsApp AI settings/history, end-to-end historic customer/portal/payment reconciliation, complete acquisition cost and profitability. No ad test has been prepared for activation approval because these operating gates are not yet complete.

No production files, existing B2B jobs, automation settings, campaigns, customer communications or financial commitments were changed. Additional unrelated website changes appeared during the session (index.php and two CSS files); preserved alongside the initial settings/partner work. They are not part of this release.

## Approval needed

Owner review of the exact paths and behaviour in DEPLOYMENT-REVIEW.md, then approval for the narrow residential installation and owner-only alert worker. This approval does not include ads, paid services or outbound customer messaging. No browser/hosting reconnect is currently needed; an existing site administrator sign-in will be required to verify the protected hosted desk.

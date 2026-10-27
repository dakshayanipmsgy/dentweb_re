# Dakshayani residential sales desk — first release

Installed 27 October 2026. Open https://dakshayani.co.in/residential-enquiries.php with your existing administrator login. Production capture and owner email receipt are verified; a signed-in staff-screen save still needs the owner's sign-in. See LIVE-RELEASE-20261027.md for the exact verification record.

## What changes for a homeowner

They choose installation district and paid monthly bill band, then give a name, required valid mobile number and permission to respond. The enquiry is saved privately before an optional WhatsApp button appears. The success message gives a reference. WhatsApp contains only that reference, and the customer still presses Send there. A click is never called a delivered message.

We deliberately require a phone number: without it, a visitor who leaves WhatsApp before Send cannot be followed up. Phone validation checks number format, not number ownership. Staff must verify the contact. The form does not request ID, bank documents, exact address or financial applications.

## Your working day

1. Open `/residential-enquiries.php` using your existing administrator login. Start with Today at opening, midday and before closing. Calls remain 9am–9pm India time.
2. Review new, due, stalled, permission-uncertain and alert-failure records. Check matching-phone references before calling twice about the same project. Separate submissions are retained until identity is confirmed; names alone never merge people.
3. Read the supplied information and the most important missing question. Confirm contact preference, paid bill and roof authority. Request rooftop photos. A manual call comes before the team decides a survey is appropriate.
4. After each attempt, choose the outcome, add a short factual note, confirm owner and set the next action/date. Distinguish “customer said” from “we verified” and your own recommendation. Record the customer's main objection in the note.
5. Only mark Qualified after serviceability, roof rights, actual bill, roof/space/shading, connection/load and requirement are evidenced. Buying timing and budget/finance remain separate. Suitable people who need time become Deferred with an agreed date and reason.
6. A proposal draft is not sent. Check scope, equipment, site assumptions and exclusions before preparing it. Review the saved version and agree payment milestones before sending a formal quote manually. Record the sent quotation reference separately.
7. Record actual receipt/commissioning references before Deposit received, Installed or Payment completed. Keep subsidy, installation, project cost and collections as separate entries. Do not enter the same collected payment twice. A deposit stage record is evidence of that milestone; collections are entered once under collected_payment.
8. Use Hindi/English drafts only after reviewing them. Nothing here sends a message, makes a call, books a visit or enrols a broadcast.

## Follow-up rules

Honour an agreed callback first. Otherwise use a reviewed first response, day-one attempt and day-three attempt at most; record each attempt. Respect calls 9am–9pm. After no response, prepare a final pause/opt-out message for review and pause further attempts. Do not repeatedly send every template. Stop on refusal/STOP; do not reopen an opt-out silently. Deferred cases wait until their agreed date.

The first release does not automatically calculate outreach frequency or send reminders to customers. Today is the staff task list. Same-phone duplicates are visibly flagged; staff resolve identity and designate one active follow-up before contacting.

## Owner alerts

A proposed separate five-minute worker alerts only d.entranchi@gmail.com, with a random reference and protected inbox link. It includes no homeowner contact details. Pending/failed alerts stay visible. The worker retries failures up to five times with at least five minutes between attempts. A process interrupted while sending is marked receipt-unconfirmed; inspect Gmail before requeueing to avoid duplicate notices. “Accepted by mailer” does not establish inbox delivery. Production approval is followed by a clearly labelled test and actual Gmail receipt verification before calling alerts reliable.

## Prices and finance

You confirmed the 21 October referrer book and visit policy on 27 October. The controlled price source is `config/pricebook.json`. Public calculator package data is generated from it when preparing a release; proposal drafts read the same source. Do not edit generated prices separately. Standard scope still requires a site-specific equipment and installation check.

The retained on-grid example uses 120 equal instalments without a moratorium. SBI's current published loan tiers were checked on 27 October. Actual sanction, interest, margin, term and eligible scope belong to the bank. Subsidy is conditional and separate; mixed eligibility remains unresolved. This release deducts no assumed subsidy for hybrid or mixed packages. Draft proposals conservatively deduct no subsidy for any package. Battery voltage, usable storage, load and depth of discharge must be confirmed before promising backup duration. Savings do not become guaranteed EMI cash.

## Visit policy confirmed by owner

Photos → manual call → team decision. Surveys 10am–4pm by manual confirmation. Ranchi city free; outside the city in Ranchi district ₹500. Outside Ranchi ₹1,500/₹2,000/₹2,500/₹3,000 by manually confirmed location/distance. Paid visits are non-refundable and adjustable against the final project bill if the project proceeds. No district automatically creates a fee or booking.

## How to read results

Synthetic/preview records are excluded from live totals. Portal selection is not a qualified opportunity or sale. Imported records have historical status unknown until checked. Reports separate stored website enquiries, clicked handoffs, staff qualification, surveys, proposals, deposits, installations and collected payments. Confirmed WhatsApp conversations, landing visits, paid attribution and profitability remain unknown unless evidence is reconciled. Response times use elapsed calendar minutes; follow-up completion means a saved staff outcome, not proof of a conversation. No sale or profit is inferred from missing data.

## Backup, correction and deletion

Download a private backup daily and keep it in protected storage outside public_html; do not email it broadly. Every changed save also creates a private pre-change snapshot. Make an off-host backup before any code update and test restore monthly. Source archives contain no runtime or customer records.

Name/phone corrections require a reason and retain history. Request other factual or financial corrections through the owner; retain original evidence and a documented correction, never silently overwrite a payment. For deletion, verify identity using an existing contact, stop outreach, identify applicable accounting/contractual retention, then remove/anonymise nonessential data including source copies, exports and expired backups through an audited maintenance task. Automated deletion is not implemented. Review unresolved and lost records monthly for retention necessity. Do not upload personal documents into this form or put them in notes.

## First operating targets — measure from launch, no guaranteed outcomes

- Every valid consented submission receives either a saved reference or a clear retry/call instruction.
- Every active case has an owner, next action and due date; review overdue cases at each daily desk check.
- During staffed hours, aim for first review within 30 minutes; record the actual attempt time. Adjust this target to real response capacity before buying traffic.
- Review stage ageing, reasons lost, quote delays, pending finance and missing receipt/cost evidence weekly.
- Do not expand geography or spend until qualified opportunities, installations, collections, travel burden and project costs can be evaluated together.

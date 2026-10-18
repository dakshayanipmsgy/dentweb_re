# Quotation entry and customer presentation

## Entry

Select the customer, system type and model, then enter the base system price and
any named additional charges. The total updates automatically. Financing prices
can still differ through the optional financing-price controls. Additional
charges are included once; the server rejects an inconsistent breakdown.

The existing rate chart, inventory kit selection, pricing, subsidy and generation
defaults remain the source of automatic values. For a 5 kW proposal using 1,620
kWh/kW/year and Rs. 8/kWh, the suggested monthly bill is Rs. 5,400. A manually
entered bill is retained when the model changes; Reset suggestion restores it.

Customer lookup keeps the current system and pricing. Required fields have
labels, section navigation opens and focuses the relevant input, and Enter moves
between ordinary input fields. Native Tab navigation and multiline text entry
remain available. Less common inputs are optional collapsed groups. Save errors
retain the form; a successful save offers the quotation link and Create another.
The admin form also deduplicates retries using a session-bound request ID.

Project summary appears in the customer document. Salesperson, follow-up date and
outcome are internal. Search and follow-up filters run before pagination, and
revision families link to the existing revision records.

## Financial rules

Explicit admin/employee editor saves opt into `workflow_version: 2`. Merely
viewing historical records does not change their saved financial interpretation.
The editor warns before upgrading a historical quotation. Existing approval,
acceptance, locking, revision, import and subsequent-document routes remain.

* Up to Rs. 2 lakh defaults to min(90% of scenario price, Rs. 2,00,000).
* Margin = scenario price minus the loan.
* Above Rs. 2 lakh defaults to 80% financing. It applies only when
  the resulting loan exceeds Rs. 2,00,000 and this comparison is enabled.
* Both bands have editable Finance mode, Margin %, Loan %, Margin Rs. and Loan
  Rs. fields. Editing a percentage selects Ratio mode; editing either rupee
  amount selects Manual mode and recalculates its complement and both percentages.
  Ratio mode retains the requested percentage across price changes and caps;
  Manual mode retains the loan amount. Switching modes affects only that band.
  The up-to-Rs.-2-lakh cap and loan-not-greater-than-price limits still apply.
  Draft text is retained while typing; limits are finalized when leaving the
  edited field. Merely focusing/tabbing through a field does not change the ratio.
  Saved custom splits survive the admin's normalization and customer rendering.
* Both loan bands use 10 years / 120 months. Submitted nonstandard tenures are
  rejected in the admin editor; derived values are recalculated on the server.
* New customer comparisons exclude subsidy-self-kept scenarios. The payment
  section explains that a loan subsidy is to be applied to the loan account.

For Rs. 2,81,900 + Rs. 13,000 structure, the total is Rs. 2,94,900, capped loan
Rs. 2,00,000 and margin Rs. 94,900. The Rs. 78,000 expected subsidy is shown
separately, giving Rs. 2,16,900 effective cost after its credit.

## Templates and payments

Annexure text has formatted editing and preview. Each quotation block explicitly
uses the saved template, custom content, or Hide. Reset restores inheritance.
Inherited content is snapshotted when saving; subsequent template edits do not
rewrite a saved quotation. Active HTML and event attributes are removed.

Customers enter an amount and choose a UPI link or an exact-amount QR. The payee
is `d.entranchi@ybl`. Opening either option never creates a receipt or marks a
quotation paid. Existing verification and receipt allocation remain authoritative.
Bank details and contractual payment milestones remain visible. The quotation QR
opens the amount-entry page, rather than imposing a fixed Rs. 10,000 advance.

The payment endpoint checks the same token, approval, current-version, expiry and
archive conditions as the public quotation. Staff-only previews require login
and employee ownership. Shared PDF payment links use the public token, rather
than the staff login URL. QR generation uses the locally bundled MIT QRCode.js;
there is no new runtime CDN dependency.

## Verification and current PDF limitation

Run PHP tests with the required mbstring extension enabled, including
`tests/quotation_workflow_test.php`. These exercise the actual financial
normalizer and shared customer renderer, breakdown validation, rich-text
sanitization, template modes, payment URI and search filters. Existing quotation
tests cover imports, status transitions, revisions, acceptance, cloning, list
refresh and print graphics. The bulk export executable test uses a Unix shell
fixture and cannot complete under native Windows.

Run `node tests/quotation_linked_finance_test.cjs` for linked input/change/blur
checks against the actual form script. It covers all four editable numeric
fields and mode switches in both bands, capping, price changes, uninterrupted
multi-digit typing, untouched focus/blur, bill preservation and saved initialization.

Local browser checks used synthetic customer data to create, save, approve and
share a quotation, verify capped financing, preserve manual bill inputs across
model changes, search the list and generate a Rs. 5,000 payment QR. The customer
view and payment page were checked at a 390 px viewport.

**Existing browser PDF download remains blocked:** the checked-in paginator
reports `paginator_output_invalid` for the long quotation. The checked-in
`assets/vendor/browser-export/jspdf.umd.min.js` writer emits literal placeholder
pages (`Quotation PDF page ...`), rather than embedding the supplied page
images. Those export dependencies are outside this change and remain unchanged.
Do not treat successful HTML/print-render tests as proof that downloadable PDF
export is production-ready. Repairing and visually validating that exporter is
still required before relying on downloadable customer PDFs.

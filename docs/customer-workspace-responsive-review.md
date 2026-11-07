# Customer workspace screen improvements

This change extends the existing teal customer workspace. It does not alter quotation values, invoice calculations, receipt qualification, customer ownership checks or confirmation submissions.

## Changes

- Account header with customer status and registered mobile; skip link and section navigation.
- Read-only profile grouped into account/contact, installation address, electricity/application and solar installation. Blank fields say "Not recorded yet"; identifiers retain leading zeros. Corrections go through the existing support form.
- Payment/invoice/complaint tables become labelled, semantic rows below 600px. Tablet layouts use the available space. Long names, addresses and transaction references wrap.
- Invoice tables scroll inside their own focusable region, with explicit wide-tax-table sizing. Accepted quotation details, agreement summaries, receipt and confirmation pages receive screen-only layouts, touch controls and accessible focus states.
- Handover controls and tables adapt to narrow screens. Screen rules are kept separate from existing print styles.
- Navigation works for a customer session without a staff session. Only the actual current page/section is marked active. Customer document links use the same tab, keeping the browser Back path available.
- Failed support forms retain entered values; clipboard success/failure is announced. CSS/JS URLs are versioned so a previous cached asset is not reused after deployment.
- The clean cPanel release whitelist includes the new helper, screen stylesheet, script, shared navigation and confirmation page. Live data and marketing files remain excluded.

## Validation and limits

PHP 8.3 lint and regression scripts cover profile presentation, navigation, receipt access, invoice visibility/tax/project value, bank details, payment links across sessions, document acceptance and signatures. Synthetic dashboard/invoice/receipt/accepted-summary pages render without PHP errors; parsed HTML checks cover profile groups, table labels, unique IDs and active navigation.

The current cloud browser cannot reach the local fixture server, and the previous signed-in hosting/customer sessions have expired. No authenticated live screen audit or native Android/iOS/iPadOS/Safari/Edge/Firefox/macOS certification is claimed. An authorized customer login and a fresh cPanel sign-in are needed for live browser verification and deployment. Check 320/390px phones, 600/768/1024px tablets, 1280/1440px desktops, landscape, 200% zoom, keyboard focus, long document references, support errors, and print preview before release.

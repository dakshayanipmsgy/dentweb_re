# Quotation PDF/ZIP export

Quotation export now has two paths:

* **Server export**: when a healthy Chrome/Chromium server engine is already available, **Download PDF(s)** can still create one PDF for one selected quotation, or one ZIP containing one separate PDF per unique selected quotation. The server path remains non-mutating and preserves selection order.
* **Browser export**: **Download using browser** creates PDFs in the administrator's same-origin browser without Chrome/Chromium, `proc_open`, Node.js, `ZipArchive`, or external services on the web server. It creates one PDF for one selection and a ZIP with one PDF entry per selected quotation for multiple selections.

Browser export is admin-only and starts with a CSRF-protected, short-lived, session-bound token. The render endpoint resolves each normalized quotation ID through `documents_get_quote()`, uses the shared `quotation_render_to_html(...)` renderer, never creates public quotation URLs, and returns a client-export view without admin controls or automatic `window.print()`.

## Privacy and runtime dependencies

Customer quotation HTML stays on the same origin. The application does not send quotation content to a PDF SaaS and does not load export libraries from a CDN at runtime. Browser libraries are expected in `assets/vendor/browser-export/` with pinned versions documented there: Paged.js 0.4.3, html2canvas 1.4.1, jsPDF 2.5.1, and fflate 0.8.2.

## Browser behavior and limits

The browser engine processes quotations sequentially to reduce memory pressure, loads one quotation in a hidden same-origin iframe, waits for `window.__quotationPdfReady === true`, waits for fonts/images/chart images, captures page-sized content page by page, then destroys the iframe and canvas before continuing. It does not capture an entire multi-page quotation into one tall canvas.

The initial supported browser-side batch limit is **10 quotations**. This conservative limit is intended for Safari safety. Test 1, 3, 5, and 10 quotation batches before increasing it.

Desktop Safari, Chrome, Edge, and Firefox are the target browsers. Browser-generated PDFs may rasterize complex cards, diagrams, gradients, charts, QR codes, and Unicode text; they can be larger and may differ slightly from native print/server PDFs.

## Failure and cancellation

Progress text reports stages such as preparing each quotation, rendering each page, creating the ZIP, and download ready. Cancellation is honored between page/quotation steps. Failures identify the quotation being processed when possible, do not download a partial ZIP, and preserve the selection for retry. Blob URLs, iframes, and canvases are cleaned up.

**Print Selected** remains the secondary emergency path. It opens a combined print document only when the administrator explicitly chooses that option; it is not the normal multi-PDF ZIP export.

## Managed-browser correction

The committed managed-browser manifest currently contains an all-zero SHA-256 checksum. Until a real pinned checksum is committed and tested, Install/Repair PDF engine controls are hidden/disabled and the UI directs administrators to browser-side export instead. Existing server Chromium support remains available where a valid browser is already installed.

## Issue #775 browser paginator and export size behavior

The export code now uses explicit preparation paths. Server Chromium export calls `quotation_prepare_server_browser_pdf_html(...)`, which may add a `file://` base URL for temporary local HTML. Authenticated browser-client export calls `quotation_prepare_client_browser_export_html(...)`, which never adds a `file://` base URL or server filesystem path; assets are resolved as same-origin relative URLs so installations at the web root or in a subdirectory can load fonts, images, charts, QR assets, and the paginator from the application origin.

The client paginator asset is `assets/vendor/browser-export/paged.polyfill.min.js`, pinned in the integrity manifest as `DentwebPaginator` 1.0.0. It exposes the application-owned API `window.DentwebPaginator.paginate(...)`; the browser export no longer expects `window.Paged.Previewer`. The iframe sets `onload` and `onerror` before assigning `script.src`, applies a bounded timeout, verifies the API, and only then paginates. Structured error codes include `paginator_asset_load_failed`, `paginator_load_timeout`, `paginator_api_mismatch`, `paginator_preview_rejected`, and `paginator_output_invalid`; the parent renders each code once.

Bulk Tools includes **Export content size** with supported values 50%, 60%, 70%, 80%, 90%, and 100%. The default is 100%, and the administrator's last choice is stored in device-local `localStorage`. The selected percentage is sent through the CSRF-protected browser-export session, validated on the server to the inclusive 50-100 range, and bound to the short-lived token so every quotation in the same ZIP batch uses the same scale.

The percentage is applied before pagination by wrapping the quotation in `.quotation-export-scale-root` and reducing the content font/layout scale. This affects text, headings, cards, tables, spacing, logos, images, charts, diagrams, QR/payment blocks, annexures, badges, and labels while keeping each physical page at A4 dimensions. It is intentionally separate from html2canvas raster scale, which is used only for output resolution. At very small text sizes such as 50%, dense tables and unusually tall unbreakable content may still need careful manual review, but the paginator splits long content rather than capturing one extremely tall page.

The browser export remains non-mutating: it does not alter quotation records, saved typography, style overrides, normal quotation views, or public quotation views. One selected quotation produces one PDF. Multiple unique selections produce one ZIP containing one separate PDF per quotation in submitted order, and no partial ZIP is offered after a render or paginator failure.

Device considerations: iPad Safari, Android Chrome, and Windows Edge use the same same-origin asset loading and explicit Download / Save-Share flow. Diagnostics intentionally exclude customer content, raw HTML, cookies, tokens, secrets, and server paths.

## Application-controlled print content size (issue #777)

Bulk Tools now includes a separate **Print content size** selector beside **Print Selected**. Supported application values are 50%, 60%, 70%, 75%, 80%, 90%, and 100%, with 100% as the safe default. The browser-export **Export content size** control from issue #775 remains available for browser PDF/ZIP export; both controls use the same validated 50-100 server-side range, but print and export preferences are labelled separately so administrators can adjust each workflow before every run.

The print percentage is different from the browser print dialog's own Scale option. Dentweb applies the selected percentage to quotation content before native print pagination starts, while the printed paper remains A4 via `@page { size: A4; }`. Text, headings, cards, grids, pricing/tax tables, spacing, logos, images, charts, QR/payment blocks, badges, and annexures are reduced together inside the A4 sheet. The print toolbar, diagnostic controls, browser controls, and physical page size are not scaled.

The chosen print value is passed through the existing authenticated CSRF-protected print request and validated again on the server. Malformed values, CSS strings, and non-numeric input are ignored or normalized safely and never inserted into CSS or HTML without validation. The value is an output preference only: it does not mutate quotation records, saved typography overrides, templates, customer records, quotation status, public links, revisions, timestamps, or stored page settings.

The combined print page contains a non-printing toolbar showing the active percentage, a selector, **Apply and Print**, and **Reset to 100%**. Changing the selector only applies the preview scale; it does not immediately reopen the print dialog. **Apply and Print** validates the value, applies CSS `zoom` flow scaling with A4 page rules, waits for reflow, fonts, images/charts/QR assets, and two animation frames, then calls `window.print()`. After the dialog closes, the page stays open so the administrator can choose another percentage and print again.

The selected print percentage is remembered on the device with the namespaced `quotationPrintScalePercent` localStorage key. Stored values are restored only when they match the supported 50-100 range; malformed or inaccessible storage falls back to 100%.

For iPad Safari, Android Chrome, and Windows Edge, use the application selector instead of browser dialog scaling when comparing 100%, 75%, and 50%. At 50%, significantly more content should fit per fixed A4 page, but very small text, unusually wide tables, or tall unbreakable annexure blocks may still require manual review.

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

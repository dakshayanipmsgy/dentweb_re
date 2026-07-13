# Quotation browser PDF export

Quotation **Download PDF(s)** uses the same `quotation_render_to_html(...)` output as quotation view and Print Selected, then renders it with a configured headless Chromium/Chrome executable. `SimplePdfDocument` is intentionally not used for quotation HTML exports.

## Requirements

- PHP with `proc_open` enabled.
- Chromium or Google Chrome installed on the web server.
- No Node.js is required for this implementation.
- The web-server user must be able to execute Chromium and create private directories below PHP's `sys_get_temp_dir()`.

## Configuration

Set:

```sh
QUOTATION_CHROMIUM_PATH=/absolute/path/to/chromium
```

Optional timeout:

```sh
QUOTATION_PDF_TIMEOUT_SECONDS=45
```

The exporter rejects missing, relative, non-file, or non-executable browser paths and returns a clear administrator-facing error instead of falling back to text-only PDF generation.

## Rendering behavior

The export pipeline writes a private temporary HTML file with a file `base` URL, launches Chromium with an isolated temporary browser profile, emulates print through Chromium's PDF output, disables browser headers/footers, prints backgrounds, and validates the `%PDF-` signature before returning the file. Temporary HTML, logs, browser profile data, PDFs, and ZIP files are removed on success and failure after the response has been sent.

Quotation JavaScript sets `window.__quotationPdfReady` after DOM work, fonts, images, chart print images, generated diagrams, and final animation frames are complete. Chart.js is loaded from a local vendor path when available; the renderer also includes a local canvas fallback so exported chart regions are not blank when public CDN access is unavailable.

## Troubleshooting

- **Configuration error**: confirm `QUOTATION_CHROMIUM_PATH` is absolute and executable by the web-server user.
- **Timeout**: increase `QUOTATION_PDF_TIMEOUT_SECONDS` up to 180 seconds, then inspect server logs for Chromium dependency failures.
- **Missing system libraries**: install the Linux packages required by your Chromium distribution.
- **Blank images**: confirm quotation assets are readable from the application filesystem and paths resolve relative to the repository root.

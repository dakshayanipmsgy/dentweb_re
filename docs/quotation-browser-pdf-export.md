# Quotation browser PDF export

Quotation **Download PDF(s)** uses the same `quotation_render_to_html(...)` output as quotation view and Print Selected, then renders it with headless Chrome or Chromium when a usable server-side browser is available. `SimplePdfDocument` is intentionally not used for quotation HTML exports.

## Default behavior

No manual browser path is required for normal deployments. On each request the exporter performs a lightweight, cached discovery pass and uses the first valid Chrome/Chromium-family executable found in this order:

1. Trusted explicit overrides: `QUOTATION_CHROMIUM_PATH`, `CHROME_PATH`, then `CHROMIUM_PATH`.
2. Repository-managed private browser directory: `runtime/browsers/` below the application root. This directory is outside public assets and can be provisioned by deployment tooling.
3. Executables in the process `PATH`, scanned directly by PHP without shell interpolation:
   - `google-chrome`
   - `google-chrome-stable`
   - `chromium`
   - `chromium-browser`
   - `chrome`
   - `chrome-headless-shell`
4. Common Linux locations:
   - `/usr/bin/google-chrome`
   - `/usr/bin/google-chrome-stable`
   - `/usr/bin/chromium`
   - `/usr/bin/chromium-browser`
   - `/snap/bin/chromium`
   - `/opt/google/chrome/chrome`
   - `/usr/local/bin/google-chrome`
   - `/usr/local/bin/chromium`
5. Approved Playwright/Chrome cache paths under the service user's home directory.

Every candidate must be an absolute executable file and must successfully answer `--version` within a short timeout with Chrome/Chromium-family output. Candidates are launched with `proc_open()` argument arrays; no shell command string is built from a path.

## Optional administrator override

If a deployment needs to pin a specific browser, set one of these trusted environment variables in server configuration:

```sh
QUOTATION_CHROMIUM_PATH=/absolute/path/to/chromium
```

`CHROME_PATH` and `CHROMIUM_PATH` are still supported for compatibility. Explicit configuration has highest priority, but a broken configured path does not block exports when automatic discovery finds a valid browser. Administrators see only a safe warning; raw environment data and full browser logs are not displayed in the UI.

Optional timeout:

```sh
QUOTATION_PDF_TIMEOUT_SECONDS=45
```

## Download behavior

When Chrome/Chromium is available:

- one unique selected quotation returns a direct `application/pdf` response;
- multiple unique selected quotations return a ZIP containing one Chromium-rendered PDF per quotation;
- selected order, ID deduplication, safe filenames, export limits, admin checks, CSRF checks, and temporary cleanup are preserved.

The export pipeline writes private temporary HTML, launches Chromium with an isolated temporary profile, disables browser headers/footers, prints backgrounds, validates the `%PDF-` signature, and removes temporary HTML, PDF, ZIP, log, and profile data after success or failure.

## Browser Save as PDF fallback

If no usable server browser is available, or if Chromium cannot launch, times out, fails to produce a valid PDF, cannot create its temporary profile, or ZIP support is unavailable for multiple selections, **Download PDF(s)** opens the existing high-quality print document instead of showing a technical Chromium error.

The fallback page preserves quotation branding, colors, hero cards, customer details, pricing and tax tables, finance sections, charts, annexures, QR codes, Unicode, rupee symbols, and print page breaks. It displays this non-printing banner:

> Server PDF download is not available on this hosting environment. Choose Save as PDF in the print window.

For multiple quotations the fallback opens one combined print document with page breaks and explains that the browser will save one combined PDF; it does not claim that a ZIP was generated and it does not open a separate tab for every quotation.

## Bulk Tools status and diagnostics

`admin-quotations.php?tab=bulk` shows an admin-only PDF engine status near the Print/Download controls:

- `PDF engine ready — configured Chromium`
- `PDF engine ready — Google Chrome detected automatically`
- `Browser Save as PDF fallback active`

The page also includes **Test PDF engine**, an authenticated CSRF-protected diagnostic action. It reports in plain language whether `proc_open` is available, whether the temporary directory is writable, whether `ZipArchive` is available, whether Chrome/Chromium was detected, the browser name/version and discovery source, whether a small test PDF was generated, and whether browser Save as PDF fallback is available. Normal diagnostics hide absolute browser paths, full environment contents, cookies, secrets, and raw unrestricted Chromium logs.

## Hosting notes

No external PDF SaaS is used and the application does not automatically download and execute a browser during normal export requests. If a shared host blocks headless Chromium or `proc_open`, administrators can still use Download PDF(s); it will open the browser Print / Save as PDF fallback. Server PDF ZIP downloads require PHP `ZipArchive`; without it, multiple selections fall back to a single combined Save as PDF print document.

## Troubleshooting

- **Fallback active**: install Chrome/Chromium on the server or provision a verified browser under `runtime/browsers/` if direct PDF/ZIP downloads are required.
- **Launch failure or timeout**: run **Test PDF engine** and confirm the web-server user can execute the browser and create directories below PHP's `sys_get_temp_dir()`.
- **Missing system libraries**: install the Linux packages required by the chosen Chromium distribution.
- **Blank images**: confirm quotation assets are readable from the application filesystem and paths resolve relative to the repository root.

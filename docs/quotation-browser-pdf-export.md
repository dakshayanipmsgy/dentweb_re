# Quotation browser PDF export

Quotation **Download PDF(s)** uses `quotation_render_to_html(...)` and headless Chrome/Chromium so exported PDFs keep the branded quotation header, colors/backgrounds, cards/grids, customer/site blocks, item/pricing/tax/finance sections, charts, QR codes, annexures, Unicode, rupee symbols, and print page breaks. `SimplePdfDocument` is intentionally not used for quotation exports.

## Guaranteed export behavior

* One unique selected quotation downloads one high-quality PDF.
* Multiple unique selected quotations download one ZIP containing one separate high-quality PDF per quotation, in selection order.
* Duplicate selected IDs are de-duplicated before export.
* The application no longer treats one combined browser Save as PDF document as a successful replacement for a requested ZIP.

If server export is unhealthy, Download PDF(s) opens a repair/retry page. The original selection is preserved in a short-lived authenticated admin-session token so the administrator does not need to reselect quotations. The emergency **Open combined Save as PDF** action remains secondary and is explicitly described as not equivalent to separate PDFs in a ZIP.

## Admin status and diagnostics

On `admin-quotations.php?tab=bulk`, the status reports one of these states:

* **Separate PDF/ZIP export ready** when server rendering is healthy.
* **PDF engine repair required — one-click repair available** when the platform can potentially be repaired.
* **This hosting platform cannot run the server PDF engine** when PHP process execution is unavailable.

The admin-only diagnostics page includes CSRF-protected actions for:

* **Test PDF engine**
* **Install PDF engine**
* **Repair PDF engine**
* **Update managed browser**

Diagnostics report browser detection/source, test PDF status, `proc_open` availability, temporary directory status, ZIP implementation, and managed-browser installation state. Normal UI messages hide raw browser logs, full paths, secrets, and unrestricted command output.

## Managed browser install and repair

One-click install/repair uses the private `runtime/browsers/` directory, never public assets. Ordinary quotation export requests never download a browser; installation is explicit, admin-only, and CSRF-protected.

The repository-controlled manifest is `includes/quotation_browser_manifest.php`. It pins the approved platform, CPU architecture, version, official HTTPS download URL, SHA-256 checksum, executable relative path, maximum archive size, and maximum extraction size. Approved hosts are allowlisted in the manifest.

Current pinned package entry:

* Browser package/version: Chrome for Testing `126.0.6478.126` (`chrome-for-testing-126.0.6478.126`).
* Supported platform in the committed manifest: Linux `x86_64`.
* Official host: `storage.googleapis.com`.

Security controls include platform/architecture matching, approved-host enforcement, no form-supplied URLs/checksums/paths, SHA-256 verification before extraction, ZIP path-traversal rejection, private staging, minimum executable permissions, bounded `--version` probe, tiny test PDF validation, atomic switch after validation, previous installation preservation on failure, cleanup of downloads/staging/profiles/test PDFs, install lock file, and bounded download/extraction/rendering timeouts.

If the platform is unsupported or the hosting provider disables process execution, the UI explains the limitation without exposing raw command output. A host that blocks all process execution cannot be fixed fully in application code.

## ZIP implementations

Multiple-quotation export prefers PHP `ZipArchive`. If `ZipArchive` is unavailable, the repository pure-PHP ZIP writer (`includes/quotation_zip_writer.php`) creates a standards-compliant ZIP with CRC32 and central-directory records. It supports binary PDFs, preserves selection order, rejects unsafe or duplicate entry paths, avoids silently omitting quotations, and cleans temporary output on failure.

If any quotation fails to render, the whole batch fails, partial PDFs are deleted, and the administrator sees the repair/retry page with a safe failure category.

## Failure categories

The export flow preserves typed safe failure codes:

* `browser_not_found`
* `proc_open_unavailable`
* `temp_unavailable`
* `browser_launch_failure`
* `browser_timeout`
* `invalid_pdf_output`
* `quotation_render_failure`
* `zip_unavailable`
* `zip_creation_failure`
* `managed_browser_install_failure`

For batch rendering, failures identify the safe quotation number or sequence, not raw browser logs or sensitive paths.

## Limitations

Server-generated separate PDFs require PHP process execution and a runnable Chrome/Chromium-compatible browser. If the hosting provider blocks `proc_open` or prohibits browser processes entirely, the application can only offer the secondary combined browser Save as PDF emergency action until hosting policy changes.

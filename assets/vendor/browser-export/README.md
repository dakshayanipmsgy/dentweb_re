# Browser quotation export assets

This directory contains the local browser-side PDF/ZIP export runtime used by `admin-quotations.php?tab=bulk`; production does not require CDN, npm, Node.js, PHP ZipArchive, server Chromium, or administrator uploads.

Integrity, versions, upstream URLs, byte sizes, and SHA-256 hashes are recorded in `integrity-manifest.json` and verified server-side before the Bulk Tools UI reports the exporter as ready.

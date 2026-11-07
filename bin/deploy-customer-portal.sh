#!/bin/sh
# Run only through cPanel's separate clean checkout. Never pull in the live root.
set -eu
umask 077
release_source=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
release_target=/home/tegwf0aqgwek/public_html
if [ "$release_source" != /home/tegwf0aqgwek/repositories/dentweb_re_release ]; then
    echo 'Deployment refused: use the dentweb_re Clean release source repository in cPanel.' >&2
    exit 1
fi
[ -d "$release_target/includes" ] && [ -d "$release_target/assets/css" ]
# This is deliberately a release whitelist: preserve all local marketing edits,
# runtime stores, uploads, handovers, environment files and other applications.
release_files='customer-dashboard.php
customer-document-view.php
customer-handover.php
invoice-view.php
receipt-view.php
includes/customer_receipt_access.php
includes/handover.php
assets/css/customer-portal.css
assets/css/document-print.css
assets/css/handover-document.css'
release_php=/usr/local/bin/php
[ -x "$release_php" ]
for release_file in $release_files; do
    [ -f "$release_source/$release_file" ]
    case "$release_file" in *.php) "$release_php" -l "$release_source/$release_file" ;; esac
done
# Save only the ten deployed code/assets, outside the web root, before writing.
release_backup=$(mktemp -d /home/tegwf0aqgwek/customer-portal-release-backup.XXXXXXXX)
for release_file in $release_files; do
    if [ -L "$release_target/$release_file" ]; then
        echo "Deployment refused: unexpected symlink $release_file" >&2
        exit 1
    fi
    if [ -f "$release_target/$release_file" ]; then
        mkdir -p "$release_backup/$(dirname "$release_file")"
        cp -p "$release_target/$release_file" "$release_backup/$release_file"
    else
        printf '%s\n' "$release_file" >> "$release_backup/new-files.txt"
    fi
done
# rsync uses a temporary file + rename for each replacement; no --delete.
printf '%s\n' "$release_files" | /bin/rsync -a --files-from=- "$release_source/" "$release_target/"
for release_file in $release_files; do
    cmp -s "$release_source/$release_file" "$release_target/$release_file"
done
printf 'Customer portal release verified. Backup: %s\n' "$release_backup"

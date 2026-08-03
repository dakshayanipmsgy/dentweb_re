# Dakshayani Work PWA and optional push

## Architecture and identities

The existing `manifest.webmanifest` remains the general admin/customer Dakshayani identity. Authenticated employee surfaces link `employee-manifest.webmanifest`, whose stable manifest `id`, employee start URL, name, icons, and shortcuts form a second Chromium install identity. Both identities deliberately share the one root `service-worker.js`; no competing worker scope exists. The server session and role checks—not display mode, manifests, or the worker—remain the access boundary.

## Cache and offline policy

The worker has an exact static URL allowlist. Navigations use network with `cache: no-store` and fall back only to generic `offline.html`; PHP/API/cross-origin/arbitrary GET responses are neither read from nor written to Cache Storage. PHP private headers independently forbid browser/proxy storage. There is no offline editing, mutation replay, or private localStorage. Activation deletes obsolete `dakshayani-pwa*` caches.

## Push configuration and secrets

Install the locked PHP dependencies with `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`. Generate the deployment icons with `php bin/generate-employee-pwa-icons.php`, then validate them with `php bin/generate-employee-pwa-icons.php --check` before service-worker activation. Set `PUSH_ENABLED=true`, a base64-encoded 32-byte `PUSH_SUBSCRIPTION_ENCRYPTION_KEY`, VAPID public/private keys, and an HTTPS or `mailto:` subject in protected deployment environment configuration. Generate VAPID keys explicitly with `php bin/generate-vapid-keys.php`. Never store keys in the repository.

Subscription endpoint, `p256dh`, and `auth` capability material is authenticated-encrypted in a versioned `v1` sodium secretbox envelope; only its SHA-256 endpoint hash is deterministic. Configuration without sodium or a valid key fails closed. APIs return device metadata only, never endpoint/key material. Canonical persistence uses SQLite migration `92901`: `push_subscriptions` owns encrypted capabilities and safe device metadata; `push_deliveries` owns one state row per notification/subscription pair. Foreign keys preserve canonical users and notifications, and indexed unique constraints prevent concurrent duplicates. Legacy JSON is never written after this release.

## Queue and delivery

Canonical in-app rows remain authoritative. The worker recovery scan reconstructs missing `(notification_id, subscription_id)` pairs for active devices and recent unread employee notifications only. The new-device/recovery cutoff is seven days. A unique SQLite pair constraint prevents duplicates. One transactional global lease starts with a five-minute lifetime and is renewed, with token ownership and unexpired time verified before every send; only its current token can release it. Delivery claims expire after two minutes and stale claims recover as retries. The worker fetches only rows actually changed to its exact claim token, in ID order, so transport results remain correlated to one delivery ID. Before send, authoritative unread ownership is rechecked. Permanent 404/410 results invalidate the exact delivery, expire its subscription, and skip its unsent rows transactionally. Temporary, missing-report, and thrown-transport outcomes become bounded recoverable retries (or terminal failures at eight attempts). Push failure never changes/deletes the in-app notification or rolls back task work.

Run `php bin/send-task-push.php --limit=25`; dry-run with `--dry-run`, or constrain recovery with `--notification-id=123`. Cron example: `*/2 * * * * cd /var/www/dentweb_re && /usr/bin/php bin/send-task-push.php --limit=25 >> /var/log/dakshayani-push.log 2>&1` (the report contains counts/status only, not endpoints, keys, task text, SQL, or traces).

Payloads contain canonical notification ID, generic safe title/message, and category. The worker never includes customer/financial/contact/document details. The service worker ignores arbitrary URLs and opens only the ownership-checked `notification-open.php?id=<integer>`. When logged out, the intended approved relative employee route is stored once in the session; login consumes it only for an employee. Schemes, hosts, protocol-relative/backslash/encoded bypasses, fragments, and unapproved routes are rejected.

## Installation, permission, badges, updates

Visit `employee-app.php`. Chromium shows the direct Install control only after `beforeinstallprompt`; iOS/iPadOS uses Safari Share → Add to Home Screen. Permission is requested only by the Enable Notifications click. Push availability, denial, unsupported browsers, registered devices, revocation, install state, and network state have explicit UI states. The existing TaskNotifications unread refresh sets/clears a feature-detected app badge after count refresh and every notification mutation; push receipt may set a capped badge. The in-app bell remains authoritative.

A waiting worker shows the existing restrained update banner; SKIP_WAITING occurs only after user action and controller change reloads once. Operators should warn users to save forms before updating.

## Deployment and rollback

1. Back up the application database and `storage/push`; deploy files; run `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`. Run `php bin/migrate-push-json.php` to inspect a sanitized, zero-mutation dry-run, then `php bin/migrate-push-json.php --apply`. Dry-run prospectively maps valid new subscriptions so delivery counts are accurate. Apply creates timestamped read-only backups, records SHA-256 checksums/completion metadata, validates/decrypts every envelope, imports only unambiguous canonical owners, and retains originals through the rollback period. Endpoint collisions belonging to another employee are reported only as sanitized ownership-conflict counts and their deliveries are excluded.
2. Enable PHP GD with PNG support, run `php bin/generate-employee-pwa-icons.php --force`, then `php bin/generate-employee-pwa-icons.php --check`. The generated files are `assets/icons/employee/icon-192.png`, `icon-512.png`, both maskable variants, and `apple-touch-icon.png`; they should be web-server-readable (0644) and owned according to the deployment policy. The generator verifies PNG signature, MIME, and exact dimensions.
3. Ensure PHP sodium and HTTPS, create protected environment secrets, then enable push.
4. Verify manifests/icons, employee login, dry-run worker, cron, and physical-device matrix below before making the PR non-draft. Regenerate icons after any committed SVG source change.
5. Roll back by setting `PUSH_ENABLED=false` and removing cron first. Restore the release and database backup together; retain the read-only JSON originals/backups for the documented rollback window. Never delete authoritative notifications. Rotate the encryption key only with an explicit decrypt/re-encrypt migration; otherwise existing envelopes intentionally become unreadable.

## Manual acceptance matrix (not a claim of execution)

- Android Chrome: identity/start/shortcuts, install, enable/deny, receipt/click, badge, update, offline.
- Android Edge: install identity, permission, receipt/click, update/offline where available.
- iPhone/iPad Safari: Add to Home Screen, standalone safe areas, supported installed-app push, click, graceful absent badge.
- Desktop Chrome/Edge: identity/install, push/click, update flow.
- All: unsupported, denied/revoked, missing VAPID/dependency, already installed, missing prompt, offline, expired session, A→logout→B isolation.

No physical mobile-device validation is automated by this PHP-first repository. Keep the PR draft until production-origin physical-device acceptance is completed.


## Text-only pull request assets

Employee PNG installation assets are deterministic deployment outputs and are narrowly ignored because this pull-request environment does not accept binary diffs. The committed UTF-8 SVG sources contain no linked images, scripts, or encoded blobs. PHP GD renders the fixed approved sizes; the generator refuses unsafe/missing sources and never accepts a client-controlled path in web traffic. A checkout before generation is expected to contain SVG sources but no employee PNGs; a completed deployment must pass `php bin/generate-employee-pwa-icons.php --check` before activation. Rollback restores the previous SVG/generator release and regenerates with `--force`. Screenshots and recordings are also intentionally not committed; maintainers may attach them manually to the GitHub PR. Capture `employee-app.php` at 390×844 and 1280×800 for install unavailable/available, push explanation/enabled/denied, mobile navigation, offline banner, notification badge, and update prompt states.

## Queue state machine and privacy boundary (#929)

`pending`/`retry` rows become `claimed`, then exactly one of `sent`, `skipped`, `invalid`, `failed`, or a new `retry`. Each transport call receives one identified canonical delivery and returns one result for that ID; reports are never correlated by endpoint. Unsupported notification types are transactionally skipped and their claim is cleared. Claims expire after two minutes; recovery makes stale claims retryable. Synchronization is bounded to supported, unread, non-dismissed employee task notifications created in the last **seven days**, which prevents a newly registered browser receiving historical traffic. Unknown types are excluded. The mapping uses canonical type only and intentionally excludes canonical title/message/event payloads, customer/contact/financial data, proof and attachment details, and storage identifiers. Canonical in-app rows are never updated or deleted by push code; queue errors occur outside task workflow transactions.

The service worker caches only the explicit static allowlist, one asset at a time, so a missing generated icon cannot prevent `offline.html` from being cached. It never caches PHP, APIs, authenticated navigation content, POST responses, uploads, documents, generated/storage routes, or arbitrary runtime resources. Badge values are canonical per-recipient unread counts capped at 99; zero clears supported badges. Browser support varies, and the in-app bell remains authoritative.

Deployment must run the schema initializer (any push API/worker invocation does this idempotently), JSON dry-run/apply, Composer production install, icon generation/check, worker dry-run, then the manual browser/device matrix. Keep push disabled until those gates pass. This corrective release does not perform issue #915 rollout work.

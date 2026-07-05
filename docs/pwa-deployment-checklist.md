# PWA Deployment Checklist

Use this checklist before real user testing on cPanel.

## cPanel upload

Upload these text/static PWA files with the normal PHP application files:

- `manifest.webmanifest`
- `service-worker.js`
- `offline.html`
- `assets/js/pwa.js`
- `assets/css/pwa-shell.css`
- `assets/icons/app-icon.svg`
- `assets/icons/app-icon-maskable.svg`
- PHP pages that include `includes/pwa_head.php`
- `admin-pwa-diagnostics.php`

Deploy the same folder structure whether the app is installed at `https://example.com/` or `https://example.com/dentweb/`.

## HTTPS requirement

Service workers and install prompts require HTTPS in production. Confirm the browser address bar shows `https://` before testing installation.

## Manifest test

1. Open `/manifest.webmanifest` from the deployed app folder.
2. Confirm it returns JSON and icon paths are reachable.
3. In Chrome DevTools, open **Application > Manifest** and confirm no blocking errors.

## Service worker test

1. Open the app in Chrome.
2. Open DevTools > **Application > Service Workers**.
3. Confirm `service-worker.js` is registered under the correct root or subdirectory scope.
4. Confirm Cache Storage contains only safe static assets such as CSS, JS, SVG icons, the manifest, and `offline.html`.

## Android install test

1. Open the app in Chrome for Android over HTTPS.
2. Sign in normally, then return to the login/start page if needed.
3. Confirm the install banner or browser install option appears where supported.
4. Install and launch the app from the home screen.
5. Confirm it opens the correct root or subdirectory URL.

## iPhone Add to Home Screen test

1. Open the app in Safari over HTTPS.
2. Tap **Share > Add to Home Screen**.
3. Launch from the home screen.
4. Confirm the app opens to the Dakshayani login/workspace flow.

## Clear old service workers/caches during testing

Chrome desktop:

1. DevTools > Application > Service Workers > **Unregister**.
2. DevTools > Application > Storage > **Clear site data**.
3. Reload the page.

Android Chrome:

1. Site settings > Storage > Clear data, or use DevTools remote debugging.
2. Reopen the app and verify the new cache version.

If testing a new deployment, increment `CACHE_VERSION` in `service-worker.js` so existing users receive updated CSS/JS.

## Confirm private pages are not cached

1. Sign in as admin, customer, and employee.
2. Visit dashboards and generated document pages.
3. Open DevTools > Application > Cache Storage.
4. Confirm no dashboard PHP pages, document pages, uploads, generated files, storage files, POST responses, or API-like responses are stored.
5. Log out and use the browser Back button. Private pages should not remain visible where browser controls allow prevention.

## End-user testing checklist

Admin:

- Login works.
- Dashboard opens.
- PWA Diagnostics is accessible.
- Logout works.
- Browser back after logout does not show sensitive dashboard data if avoidable.

Customer:

- Login works.
- Customer dashboard opens.
- Documents open only through authorized flows.
- PWA Diagnostics is blocked.
- Logout works.

Employee:

- Login works.
- Employee dashboard opens.
- PWA Diagnostics is blocked.
- Logout works.

General:

- Desktop layout opens normally.
- Mobile layout has no horizontal scrolling.
- Manifest loads.
- Service worker registers under the correct scope.
- Install prompt wording is professional.
- Offline navigation shows only the safe offline message.

## Rollback steps

If the service worker causes issues:

1. Temporarily remove the `includes/pwa_head.php` include from affected pages or stop uploading `assets/js/pwa.js`.
2. Replace `service-worker.js` with a minimal unregister/cleanup worker if necessary.
3. Ask testers to clear site data or unregister the worker from DevTools.
4. Restore the previous known-good files from backup.
5. Redeploy with an incremented `CACHE_VERSION` after fixing the issue.

# PWA Release Test Plan

Use this checklist before uploading a release to cPanel or inviting real users to test the app. Do not skip the logout and offline privacy checks.

## Pre-deployment checks

- Confirm the release contains only text/source files. Do not upload new `.png`, `.jpg`, `.jpeg`, `.webp`, `.ico`, `.pdf`, `.zip`, `.apk`, or `.aab` files.
- Open `login.php`, `app-install-help.php`, `privacy-policy.php`, `terms.php`, and `offline.html` in a browser.
- Confirm every public page has a clear title, readable text, and working links back to login/install/legal pages.
- Confirm there is no horizontal scrolling at mobile widths around 360px to 430px.
- Confirm `manifest.webmanifest`, `service-worker.js`, `offline.html`, and SVG icons exist at the app root.
- Confirm the service worker cache version was changed when cached CSS/JS/manifest files changed.

## cPanel upload checks

- Upload the complete release into the exact cPanel folder that will host the app.
- Confirm file names preserve lowercase/uppercase exactly.
- Confirm PHP files load without showing source code.
- Confirm HTTPS is active for the final domain or subdirectory.
- Visit `admin-pwa-diagnostics.php` as an admin and review every checklist item.
- If the app is hosted in a subdirectory, confirm manifest and service worker paths still point to that subdirectory.

## Android Chrome testing

- Open the app in Android Chrome.
- Log in as each test role only with approved test accounts.
- Confirm the install prompt or browser install menu is available.
- Install the app and launch it from the home screen.
- Confirm the mobile bottom navigation is visible after login and does not cover submit buttons.
- Confirm the app works in standalone mode after closing and reopening it.

## iPhone Safari testing

- Open the app in Safari on iPhone.
- Use Share > Add to Home Screen.
- Launch the installed icon from the home screen.
- Confirm the login page and dashboards fit the screen without horizontal scrolling.
- Confirm legal/help links work from public pages.
- Confirm Safari does not show private workspace content after logout and back button use.

## Desktop testing

- Test in Chrome or Edge.
- Confirm the install icon/menu works where supported.
- Confirm desktop layouts are not forced into mobile bottom navigation.
- Open DevTools Console and check for obvious JavaScript errors.
- Open DevTools Application panel and review Manifest, Service Workers, and Cache Storage.

## Admin role test

- Log in as an admin.
- Open `admin-dashboard.php` and confirm dashboard cards, actions, and navigation still work.
- Open `admin-pwa-diagnostics.php` and confirm the smoke test checklist is visible only after admin login.
- Confirm admin navigation links point only to admin-safe pages and logout is easy to find.
- Log out and confirm admin pages redirect to login or deny access.

## Customer role test

- Log in as a customer.
- Open `customer-dashboard.php` and review documents, financials, complaint, and profile sections.
- Confirm the mobile navigation shows only customer-safe links.
- Confirm customer document links still require the customer session.
- Log out and confirm customer pages redirect to login.

## Employee role test

- Log in as an employee.
- Open `employee-dashboard.php` and review tasks, documents, leads, and complaints links.
- Confirm the mobile navigation shows only employee-safe links.
- Confirm employee-only pages remain protected.
- Log out and confirm employee pages redirect to login.

## Logout/security test

- For each role, log in, open the dashboard, then log out.
- Press the browser back button after logout.
- Confirm private dashboard content is not usable and refresh redirects to login.
- Confirm private pages send no-store/no-cache headers where applicable.
- Confirm logged-out users never see mobile private navigation.

## Offline/cache privacy test

- In DevTools Application > Cache Storage, confirm cached entries are limited to safe static assets, manifest, public SVG icons, and `offline.html`.
- Confirm no dashboard HTML is cached.
- Confirm no quotation, agreement, dispatch advice, challan, invoice, receipt, generated PDF, upload, storage, customer data, admin data, employee data, or API response is cached.
- Go offline and open a protected page. Confirm the safe public offline page appears instead of private data.
- Go back online and confirm login/dashboard pages load normally.

## Rollback steps

- Keep a copy of the previous working release before uploading.
- If users report install, login, or cache issues, restore the previous release files in cPanel.
- Increase the service worker cache version in the restored or fixed release so browsers replace old cached static assets.
- Ask affected testers to close all app/browser tabs and reopen the installed app.
- If needed, ask testers to clear site data for the domain and sign in again.

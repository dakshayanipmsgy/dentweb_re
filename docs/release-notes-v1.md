# Release Notes: Version 1.0.0 PWA Release

## What is included

- PWA-ready Dakshayani web app metadata.
- Installable app behavior for supported browsers.
- Secure role-based access for admin, customer, and employee users.
- Mobile-friendly navigation improvements for workspace pages.
- Admin-only diagnostics for PWA setup and release checks.
- Privacy-safe offline fallback that avoids exposing private workspace data.
- Android Play Store readiness notes and listing draft for future packaging.

## Secure access

- Admin users can access admin dashboards and diagnostics after admin login.
- Customer users can access their customer dashboard and approved document/support workflows after login.
- Employee users can access employee workspace workflows after login.

## Known limitations

- Internet access is required for secure login, dashboards, documents, and workspace actions.
- Android Play Store package has not yet been created.
- Final PNG app icons, screenshots, and Play Store artwork are needed later.
- Native push notifications are not included.
- The app is not a full native Android app; it is prepared for PWA/TWA wrapping.

## Post-release testing checklist

- Open the public website over HTTPS.
- Confirm the login page loads.
- Confirm admin login works.
- Confirm customer login works.
- Confirm employee login works.
- Confirm admin diagnostics works for admins only.
- Confirm privacy policy, terms, and install help pages load.
- Confirm `manifest.webmanifest` loads.
- Confirm the service worker registers.
- Confirm no obvious JavaScript console errors appear.
- Confirm private pages and generated documents are not stored in Cache Storage.
- Confirm no binary files were added to the release.

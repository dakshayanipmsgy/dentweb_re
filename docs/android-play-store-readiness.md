# Android Play Store Readiness Checklist

This document prepares the Dakshayani web app for a future Android Play Store release without creating Android binary files in this repository.

## Current status

- The website is now PWA-ready.
- The app has a web app manifest, service worker, install help page, privacy policy, terms page, SVG app icons, and admin-only PWA diagnostics.
- The recommended publishing path is **PWA first**, then an Android wrapper using a **Trusted Web Activity (TWA)** or similar PWA wrapper.

## Recommended path

1. Keep the production website stable and served over HTTPS.
2. Confirm the PWA works well in Chrome for Android and Safari Add to Home Screen.
3. Prepare Play Store listing text, screenshots, privacy policy, and final PNG store artwork.
4. Build a Trusted Web Activity wrapper outside Codex when Android signing details are available.
5. Generate and sign an Android App Bundle (`.aab`) outside this repository.
6. Add the final Digital Asset Links file only after the real package name and signing certificate fingerprint are known.
7. Upload the signed bundle and listing materials through Google Play Console.

## Suggested Android app identity

- Suggested app name: **Dakshayani**
- Suggested package name placeholder: `in.dakshayani.app`
- Version placeholder: `1.0.0`

These values should be reviewed before final Play Store publishing.

## Future items required before publishing

The following items are still required and should be prepared by the business or Android packaging team:

- Google Play Store developer account.
- Final app icon PNG files in required Play Store and Android launcher sizes.
- Phone and tablet screenshots from the real app.
- Play Store feature graphic.
- Public privacy policy URL, for example `/privacy-policy.php` on the final domain.
- Public terms URL, for example `/terms.php` on the final domain.
- Final short and full app descriptions.
- Signed Android App Bundle (`.aab`).
- Final `/.well-known/assetlinks.json` after the signing certificate SHA-256 fingerprint is known.

## Digital Asset Links requirement

A Trusted Web Activity needs Digital Asset Links to prove that the Android app and the website are controlled by the same owner.

This repository includes only an example file:

- `.well-known/assetlinks.example.json`

Do **not** rename it to `assetlinks.json` until the following real production details are available:

- Real Android package name.
- Real SHA-256 signing certificate fingerprint from the final Android signing key.
- Final Android app package details used in the Play Store bundle.

The final production file should be served from:

```text
https://your-final-domain.example/.well-known/assetlinks.json
```

## Important repository rule

Final Android binary files should **not** be committed through Codex. Do not add `.apk`, `.aab`, Android Studio build outputs, ZIP archives, Play Store image exports, or generated binary assets to this repository.

## If a full native app is requested later

A full native Android app would require real authenticated API endpoints later. It should not scrape existing portal pages. Future native work would need API planning for login, dashboards, customer data, documents, complaints, tasks, leads, uploads, authorization, rate limiting, audit logging, and token/session strategy.

## Non-developer checklist

Before asking an Android developer to package the app:

- Confirm the website opens over HTTPS.
- Confirm login works for admin, employees, and registered customers.
- Confirm the install help, privacy policy, and terms pages open publicly.
- Confirm `manifest.webmanifest` opens in the browser without an error.
- Ask an admin to open `admin-pwa-diagnostics.php` and review the checklist.
- Prepare screenshots from the actual hosted app.
- Prepare the Play Store account and app listing text.
- Give the Android developer the final domain, app name, package name, privacy URL, terms URL, and support email.

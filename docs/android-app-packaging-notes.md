# Android app packaging notes

These notes prepare Dakshayani Enterprises for a future Android app release without adding Android Studio project files or binary app outputs to this repository.

## Current recommended path: PWA first

The current app should remain a Progressive Web App first. This keeps the same secure PHP login, server-side role checks, document workflows, and cPanel-friendly deployment while allowing users to install the site from supported browsers.

## Future option: Trusted Web Activity / PWA wrapper

A Trusted Web Activity (TWA) can publish the existing PWA through Google Play while still loading the production website. This is usually the lightest Android packaging path when the web app is already mobile-friendly, installable, served over HTTPS, and has a valid web app manifest.

Future TWA readiness items may include:

- Confirm HTTPS and domain ownership.
- Confirm manifest name, short name, theme color, and icon requirements.
- Configure Digital Asset Links for the production domain.
- Generate a signed Android App Bundle outside this repository.
- Prepare Play Store listing text, screenshots, and privacy policy URL.

## Future option: simple WebView wrapper

A simple WebView wrapper can load the existing portal inside an Android shell. This may be useful if Play Store requirements or device integrations make TWA unsuitable. A WebView wrapper still needs careful handling of cookies, downloads, file uploads, external links, permissions, and security updates.

## Why a full native app would require API endpoints

A full native Android app should not scrape portal pages. It would need authenticated API endpoints for login, dashboards, customer data, documents, complaints, tasks, and uploads. Those APIs would need server-side authorization, CSRF/session or token strategy, audit logging, rate limits, and compatibility planning. That is outside the scope of the current PWA work.

## Likely Play Store assets and information needed later

- App name: **Dakshayani**
- Package name placeholder: `in.dakshayani.app`
- Public privacy policy URL, for example `/privacy-policy.php`
- Terms or acceptable-use URL, for example `/terms.php`
- App icon in required Android sizes and adaptive icon format
- Feature graphic and Play Store screenshots for phone and any supported devices
- Short description and full description
- Contact email, website URL, and support information
- Data safety form answers reviewed by Dakshayani Enterprises
- Signed Android App Bundle (`.aab`) generated outside this repository
- Release signing key and keystore managed securely outside this repository

## Repository rule for binary app files

Do not commit binary Android package files through Codex in this repository. This includes `.apk`, `.aab`, keystores, `.zip` build outputs, raster icons, and generated Android build artifacts. Future packaging work should keep source/configuration changes text-only unless the project owner explicitly approves a separate binary asset workflow.

# App Metadata

Use this text-based metadata file when preparing future Android/PWA packaging.

| Field | Value |
| --- | --- |
| App name | Dakshayani |
| Short name | Dakshayani |
| Website URL placeholder | `https://your-final-domain.example/` |
| Start URL | `login.php?source=pwa` |
| Scope | `./` |
| Support email placeholder | `support@example.com` |
| Privacy policy path | `privacy-policy.php` |
| Terms path | `terms.php` |
| Install help path | `app-install-help.php` |
| Manifest path | `manifest.webmanifest` |
| Service worker path | `service-worker.js` |
| Package name placeholder | `in.dakshayani.app` |
| Version placeholder | `1.0.0` |

## Notes

- Keep the production website URL and legal/support URLs final before packaging.
- Use the same start URL and scope as the deployed manifest unless the hosting folder changes.
- The final Android package name and signing fingerprint must match the production Digital Asset Links file.

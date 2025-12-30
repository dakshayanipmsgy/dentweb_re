# Dakshayani Portal Decommission Report

## Summary of Removals
- Removed public portal entry points (`login.php`, `logout.php`) and all associated navigation links.
- Deleted role-based application bundles, shared auth libraries, and dashboards under `users/` (admin, employee, installer, referrer, customer).
- Removed private CRM and workflow front-end assets (`crm.css`, `crm.js`, `workflow.css`, `workflow.js`).

## Backups
- Backup timestamp (UTC): 20251026-090920
- Backup location: `backups/20251026-090920/`
- Items backed up: 10 (including one directory snapshot)
- Contents: portal entry scripts, entire `users/` tree, CRM/Workflow assets, and shared partial/style files touched during cleanup.

## Navigation & Link Updates
- Removed “Portal Login” links from desktop and mobile headers (both partial and inline fallback template).
- Cleared portal-specific styling rules from `style.css` to avoid unused selectors.

## Residual Stubs
- Header `nav-actions` container and theme badge remain to preserve existing public theming features.

## Post-Cleanup Verification
- Public marketing pages (home, about, contact) load with intact header/footer and without console errors.
- SEO/marketing assets (sitemap references, styles, scripts) unchanged.
- Requests to former portal routes (e.g., `/login.php`, `/users/*`) now respond with 404 due to file removal.

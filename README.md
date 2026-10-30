# MohFam

WordPress Twenty Twenty-Five child theme in `twentytwentyfive.child_.theme_.-.jaimemasc.com_/`.

## Site design and service availability

The current service order is Security, Commercial Cleaning, Pool Cleaning, Landscaping, and Car Wash. Security, Commercial Cleaning, Pool Cleaning, and Car Wash have division websites. Landscaping is marked Coming Soon. Commercial cleaning links to https://mohfamcommercialcleaning.com/, pool cleaning links to https://mohfampoolcleaning.com/, and car wash links to https://mohfamcarwash.com/.

The theme permanently redirects public GET/HEAD requests for `/commercial-cleaning/` and `/services/commercial-cleaning/` to the commercial cleaning website, and `/pool-cleaning/`, `/pool-care/`, `/services/pool-cleaning/`, and `/services/pool-care/` to the pool cleaning website. Car wash routes `/car-wash/` and `/services/car-wash/` redirect to https://mohfamcarwash.com/. WordPress previews remain available. Redirecting the retired `mohfamcleaning.com` domain requires a separate hosting/domain configuration; this repository deploys only the `mohfam.co` theme.

Shared navigation is in `parts/header.html` with progressive enhancement in `assets/js/navigation.js`. The homepage's shared app panel is in `parts/app.html`; its existing app artwork is stored locally under `assets/images`. Shared design refinements are in `assets/css/experience.css`, loaded after the base stylesheet. Both styles and scripts use file timestamps for cache invalidation.

The homepage service selector is in `parts/service-finder.html`; `parts/service-process.html` and `parts/service-faq.html` are shared with the Services page. `assets/js/experience.js` enhances the selector and adds page progress, return-to-top, and scroll effects. Native FAQ controls and the navigation fallback remain usable without JavaScript, and motion respects the visitor's reduced-motion setting.

The sticky navbar slides away on downward scrolling and returns on upward scrolling. It remains visible at the top, while a menu is open, and while navigation has keyboard focus. Small scroll changes are ignored to prevent flicker.

See [the design audit](docs/design-audit.md) for resolved findings, rendered verification, and remaining design priorities.

## Inquiry email

The existing contact and homepage forms use a reusable plain-PHP cURL sender for Brevo. After an accepted notification, WordPress Cron syncs the visitor to Inquiries list #3 and adds Newsletter list #4 only with the optional checkbox's consent; existing consent is preserved. Configuration lives outside `public_html` in the account's `private/mohfam-mail.php`; it is never deployed or committed. See [inquiry mail setup, safeguards, and the delivery test gate](docs/inquiry-mail.md). Automated checks mock the transport and never send email or create contacts. Each live integration test requires the owner's separate authorization.

## Automatic theme deployment

`.github/workflows/deploy-mohfam.yml` deploys the theme on every push to `main`, after checking PHP, JavaScript, and theme JSON syntax. It also supports a manual run from GitHub Actions on `main`.

In this repository's **Settings → Secrets and variables → Actions**, add these dedicated repository secrets. Keep the existing FTP secrets for the other website unchanged.

| Secret | Value |
| --- | --- |
| `MOHFAM_FTP_SERVER` | `p3plmcpnl503624.prod.phx3.secureserver.net` (verified with this account) |
| `MOHFAM_FTP_USERNAME` | `mohfamadmin@mohfam.co` |
| `MOHFAM_FTP_PASSWORD` | The supplied FTP account password; never commit it |

The verified FTP account opens directly at the WordPress site root (`public_html/mohfam.co` in cPanel). The workflow therefore uses this destination relative to the FTP login root:

```text
wp-content/themes/twentytwentyfive.child_.theme_.-.jaimemasc.com_/
```

The supplied hostname `ftp.mohfamsecurity.com` did not resolve during setup. The hosting hostname above successfully authenticated and listed the target theme over explicit FTPS on port 21 with certificate verification. Use that hostname for `MOHFAM_FTP_SERVER`.

Only the contents of the child theme directory are uploaded. The theme uses its own `.mohfam-theme-sync-state.json`; unrelated remote files are preserved. Later deploys can remove files previously deployed by this workflow when they are removed from the repository. Full directory cleanup is disabled.

After adding the settings and pushing this workflow to `main`, check the **Deploy MohFam theme** run in GitHub Actions. Deployment is not active until the workflow is on GitHub and these settings are configured.

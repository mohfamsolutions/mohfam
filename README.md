# MohFam

WordPress Twenty Twenty-Five child theme in `twentytwentyfive.child_.theme_.-.jaimemasc.com_/`.

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

# Inquiry mail integration

## Scope and boundaries

The existing homepage and `/contact/#inquiry` forms keep their design and fields. Both use WordPress AJAX with a framework-independent sender in `inc/inquiry-mail.php`. Only mohfam.co is deployed. No DNS, Brevo settings, or other division sites are changed.

The sender calls Brevo with PHP cURL, never the SDK or `wp_mail()`. A visitor confirmation email is not sent. Success requires HTTP 201 and a nonempty, valid messageId; provider acceptance is not proof of inbox delivery. Preserve the existing private WordPress submission inbox, but its save result never determines mail success.

The caller validates raw scalar input, same-origin POST, WordPress nonce, honeypot, signed fill time (3 seconds to 2 hours), IP rate limit (5 attempts per 15 minutes, at least 20 seconds apart), Turnstile, email, field lengths, service allowlist, and control characters. Never strip header characters and then treat the resulting address as the visitor's original address. Same-origin checks compare scheme, host, and effective port. The IP comes from REMOTE_ADDR, never an untrusted forwarded header. Shared proxies may share a rate limit.

The same-origin `mohfam_form_session` AJAX action performs a local readiness check (PHP cURL/HTTPS, private config syntax/readability, and writable log location) before issuing a fresh WordPress nonce and signed start token. It never calls the mail API and exposes no key or private path. This also avoids stale nonce/timing data from cached public pages. If readiness fails, the visitor gets a friendly unavailable message on submission and can use the backup phone number.

## Private configuration and metadata

For `/home/<account>/public_html/mohfam.co`, use `/home/<account>/private/mohfam-mail.php`, permission 0600:

```php
<?php
return ['brevo_api_key' => 'YOUR_PRIVATE_KEY'];
```

The key is placed by the owner, never requested or committed. `privateDirectory(ABSPATH)` resolves the site's real path, requires its parent to be `public_html`, and resolves a real sibling `private` directory under the account home. Symlinked private directories/config/log files are rejected. There is no environment-variable, web-root, SDK, or WordPress-mail fallback. Missing, malformed, or unreadable configuration fails safely. A preexisting file should remain 0600; the private directory must be writable by the site's PHP process.

`mohfam-mail.log` contains UTC timestamp, random reference ID, HTTP status or a fixed sanitized error category, and messageId on acceptance. Never include response bodies, API keys, email addresses, or inquiry bodies. The log must be writable before sending. `mohfam-inquiry-rate.json` uses an exclusive lock and contains salted IP hashes and counters only. Both runtime files are created with permission 0600. Keep these outside public_html; do not create a public phpinfo/config-check page.

## Sender contract

`MohFam\Mail\sendInquiry(string $sitePrefix, array $fields, string $siteRoot): array` accepts validated fields (`name`, `company`, `email`, `phone`, `service`, `message`, `page_url`). It rechecks generic validation and returns `success`, `reference`, `http_code`, and `messageId`, never secrets or raw provider errors. Prefix is trusted application configuration, e.g. `MohFam` or `MohFam Car Wash` (without brackets). Future sites can reuse the module; configure their root and sender policy explicitly when that work is authorized.

The current sender is `MohFam Website <website@mohfam.co>`; recipient is `MohFam <mohfamsolutions@gmail.com>`. Reply-To is the validated visitor. Subject is `[MohFam] {service} inquiry — {name}`. Plain text and escaped HTML include all visible fields, source URL, reference, and submit time in UTC and America/Los_Angeles (including DST). POST goes only to `https://api.brevo.com/v3/smtp/email`, TLS verification on, no redirects, 10-second total timeout, 5-second connect timeout. Do not retry automatically after an ambiguous timeout.

## Verification and release gate

1. Run PHP syntax, `php tests/inquiry-mail-test.php`, and `node tests/forms-test.cjs`. Tests use synthetic data and mocked cURL; they never contact Brevo or send email. PHP tests cover server validation, origin/CSRF/timing, honeypot, rate limits, provider responses, escaped payloads, DST, secret-safe logging, and readiness. Form tests cover successful clearing, retained typed fields on failure, retries, missing CAPTCHA, and session failures.
2. Deploy through the existing workflow only after checks pass. Production PHP cURL/HTTPS and configuration readability cannot be inferred from local tests or a deployment upload.
3. Phase 3 requires the owner's separate permission before exactly one controlled email. Confirm Gmail inbox placement, the subject, visitor Reply-To, and the original message's `Authentication-Results` showing `dkim=pass` for `header.d=mohfam.co`. Brevo acceptance and Gmail delivery are distinct checks. Only then describe the form as confirmed live.

Phase 2 acceptance: no fake success or mail fallback; friendly errors include +1 (818) 818-1181 and retain typed fields; all protections above exercised by negative tests.

## Controlled delivery verification — 2026-10-30

The owner authorized and submitted one controlled contact-form test. Gmail received it at 04:22:51 UTC (12:22:51 Asia/Taipei), and both the owner's screenshot and Gmail message labels confirmed Inbox placement. No additional test was sent.

- Sender: `MohFam Website <website@mohfam.co>`.
- Recipient: `MohFam <mohfamsolutions@gmail.com>`.
- Subject: `[MohFam] General Inquiry inquiry — Website Test`, matching the specified subject format.
- Reply-To: `Website Test <mohfamsolutions@gmail.com>`, matching the submitted visitor information.
- Gmail `Authentication-Results`: `dkim=pass`, identity `@mohfam.co`, selector `brevo2`; the corresponding DKIM signature has `d=mohfam.co`. SPF and DMARC also passed.
- Brevo message ID: `<202610300422.16942536791@smtp-relay.mailin.fr>`.
- Inquiry reference: `24b14c2fa153a54ea5f68233`.

The form is confirmed live through the controlled Gmail delivery check. This verifies this message's delivery and authentication; ongoing inbox placement may vary.

# Inquiry mail integration

## Scope and boundaries

The existing homepage and `/contact/#inquiry` forms keep their design and fields. Both use WordPress AJAX with a framework-independent sender in `inc/inquiry-mail.php`. Only mohfam.co is deployed. No DNS, Brevo settings, or other division sites are changed.

The sender calls Brevo with PHP cURL, never the SDK or `wp_mail()`. After a successful mohfam.co notification, the after-response worker also attempts one short visitor confirmation. Success requires HTTP 201 and a nonempty, valid messageId; provider acceptance is not proof of inbox delivery. Preserve the existing private WordPress submission inbox, but its save result never determines mail success.

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

The current sender is `MohFam Website <website@mohfam.co>`; recipient is `MohFam <mohfamsolutions@gmail.com>`. Reply-To is the validated visitor. Subject is `[MohFam] {service} inquiry — {name}`. Plain text and escaped HTML include the existing inquiry fields, source URL, reference, and submit time in UTC and America/Los_Angeles (including DST). POST goes only to `https://api.brevo.com/v3/smtp/email`, TLS verification on, no redirects, 10-second total timeout, 5-second connect timeout. Do not retry automatically after an ambiguous timeout.

## Visitor confirmation (mohfam.co only)

The notification payload has the Brevo tag `inquiry-notification`. After it returns HTTP 201 with a valid messageId, the handler passes that accepted receipt into the existing in-memory shutdown registration. Only after finishing the JSON response and running the unchanged contact sync does the mohfam.co worker attempt `sendInquiryConfirmation()`. A failed contact sync does not prevent confirmation. Legacy contact-only jobs without the notification receipt and other site names never send a confirmation. Honeypot, nonce, origin, timing, rate-limit, input-validation, and Turnstile failures return before shutdown registration.

The confirmation uses the same private config and shared TLS-verified cURL email transport, with a single POST to `https://api.brevo.com/v3/smtp/email`, a 10-second total timeout, and no automatic retry. Its sender is `MohFam <website@mohfam.co>`, recipient is the validated visitor name and email, Reply-To is `MohFam <mohfamsolutions@gmail.com>`, subject is `We received your request — MohFam`, and tag is `inquiry-confirmation`. Acceptance again requires HTTP 201 plus messageId. Private log categories have a `confirmation_` prefix, recording only reference, timestamp, HTTP code/fixed error category, and accepted message ID. Confirmation failure cannot alter the completed visitor response or existing contact/list behavior.

Only name, email, service, and reference are accepted by the confirmation payload builder. It never reads the visitor's message, company, phone, or source URL. The body greets the first name, acknowledges the service request, promises a reply within one business day, gives the reference and backup phone, and signs off from the MohFam Team in Los Angeles. HTML-escape all personalized HTML values; plain text stays plain text. The inline HTML uses the existing local site's logo (static mohfam.co image URL, no per-recipient parameters), charcoal #1a1a1b, gold #c5a059, ivory #f9f9f7, a 600px maximum-width table, and email-safe system fonts. No new tracking pixels, scripts, remote fonts, or tracking settings are added; Brevo's existing defaults are unchanged.

Contact work retains its original ordering and up to 25 seconds of lock/API waits. Confirmation adds up to 10 seconds after that, entirely after response completion; the worker has a 45-second PHP budget. This remains best effort if hosting terminates the process. Offline tests cover successful ordering, notification/spam rejection, confirmation failure and single attempt, body redaction, escaping, sender/Reply-To/tags, and both confirmation acceptance and missing-messageId rejection.

Live confirmation verification is pending: obtain the owner's separate permission for one realistic inquiry with visitor `michaelcaligner@gmail.com`, notification to `mohfamsolutions@gmail.com`, and confirmation to the visitor. The owner submits the form; do not send an additional synthetic live test. Check the paired reference, subject, sender, Reply-To, body redaction, Brevo tags, delivery placement, and authentication when the messages are available. Do not change DNS, Brevo settings, or any list for this test.

## Contact sync and optional newsletter consent

After notification acceptance, the handler registers an in-memory PHP shutdown callback via `register_shutdown_function()`. It does not schedule WP-Cron or make a loopback request. The normal JSON success response is sent using the existing WordPress response function. During shutdown, the worker calls `fastcgi_finish_request()` when available, or `litespeed_finish_request()`; only after that succeeds does it run contact lookup/upsert. A last-resort fallback frames the JSON with Content-Length and Connection: close, disables PHP output compression, and flushes buffers. PHP flush cannot override every host/proxy buffer, so confirm the production response mode before live tests. The readiness response reports `contact_response_mode` without exposing secrets or contact data.

The worker starts in the same PHP request immediately after response completion, independent of later visits or cron. Low traffic does not defer it. Allow up to five seconds for the account-local contact lock, then up to ten seconds each for the GET and POST requests (about 25 seconds maximum for those waits, plus PHP/log overhead). Successful API requests will usually finish sooner; this has not yet been timed with a live contact. Ignore client disconnects and allow a 45-second PHP execution budget for shutdown work, including the additional confirmation attempt described below. This is best effort, not a durable queue: process termination can lose a pending sync. No automatic retry or email resend is performed. Keep the old cron action callback solely to drain events from the previous deployment; new inquiries never enqueue it. Failures only produce private sanitized metadata and never rewrite the completed JSON response.

Both forms have an optional checkbox named `marketing_opt_in`, value `1`, explicitly labeled and unchecked by default. Absence means false; arbitrary values and arrays are rejected before sending. The existing notification payload and success response are unchanged. The submitted checkbox value is stored in the existing private WordPress inquiry record, separate from historical contact consent.

`siteSettings()` is the single trusted map:

| Site | Inquiry list | Newsletter list | Prefix |
| --- | --- | --- | --- |
| mohfam.co | 3 | 4 | [MohFam] |
| mohfamsecurity.com | 11 | null | [MohFam Security] |
| mohfamcommercialcleaning.com | 12 | null | [MohFam Commercial Cleaning] |
| mohfampoolcleaning.com | 13 | null | [MohFam Pool Cleaning] |
| mohfamlandscaping.com | 14 | null | [MohFam Landscaping] |
| mohfamcarwash.com | 15 | null | [MohFam Car Wash] |

Only mohfam.co is deployed. The in-memory shutdown data is limited to name, email, service, the submitted consent, trusted site, reference, accepted notification message ID, and the inquiry date in America/Los_Angeles; it excludes the message body and API key. `syncInquiryContact()` uses the same private config. It GETs the URL-encoded contact email to distinguish new and existing contacts, then POSTs to `https://api.brevo.com/v3/contacts` with `updateEnabled: true`. Contact requests verify TLS, prohibit redirects, and have a 10-second total timeout each. Create 201 and update 204 responses count as successful syncs.

Attributes are FIRSTNAME/LASTNAME (split on the first space; single-word names have empty LASTNAME), SERVICE_INTEREST, SOURCE_SITE, LAST_INQUIRY_AT (the inquiry's Los Angeles date), and MARKETING_OPT_IN. New unchecked contacts get false; checked contacts get true and add the configured newsletter list when non-null. Existing true remains true. Existing unchecked updates never write false, preserving a concurrent opt-in rather than overwriting it. A private 0600 `mohfam-contact-sync.lock` serializes lookup/upsert operations from integrations using this module under this hosting account, with a five-second acquisition deadline. If lookup fails or is malformed, the worker skips the update rather than guessing consent. Never send `unlinkListIds`, `emailBlacklisted`, or any suppression reset; listIds only adds the inquiry list and, on explicit opt-in, the newsletter list. Membership in other lists is preserved by Brevo's additive list behavior.

Log statuses include `contact_deferred`, `contact_defer_failed`, `contact_after_response_started`, `contact_response_finish_failed`, `contact_after_response_failed`, `contact_lock_timeout`, `contact_synced`, `contact_lookup_failed`, `contact_lookup_invalid`, `contact_upsert_failed`, and `contact_sync_internal_error`. Only reference, timestamp, HTTP code, and a fixed status are logged, never contact data, raw API errors, or keys. New contact-sync live verification requires a separate authorized test and confirmation in list #3; the earlier email-only verification below does not verify this extension.

## Verification and release gate

1. Run PHP syntax, `php tests/inquiry-mail-test.php`, `node tests/forms-test.cjs`, and `node tests/after-response-test.cjs`. Tests use synthetic data and mocked cURL; they never contact Brevo or send email. PHP tests cover server validation, origin/CSRF/timing, honeypot, rate limits, provider responses, escaped payloads, DST, secret-safe logging, and readiness. Form tests cover successful clearing, retained typed fields on failure, retries, missing CAPTCHA, and session failures. The local HTTP timing test proves the fallback returns JSON before simulated slow shutdown work; it never calls Brevo. Unit tests also prove native response finishing precedes contact calls.
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


## Completed two-step contact verification - 2026-10-30

The owner authorized each test separately and submitted each once using Website Test / mohfamsolutions@gmail.com / General Inquiry. The owner verified the following contact state directly in Brevo; no reset or deletion was performed to manufacture test results.

- Starting state: existing contact #1, list #2 only, all inquiry attributes and MARKETING_OPT_IN empty; email campaign and transactional subscription states enabled.
- Test 1, unchecked: same contact #1, lists #2 and #3, no #4. FIRSTNAME=Website, LASTNAME=Test, SERVICE_INTEREST=General Inquiry, SOURCE_SITE=mohfam.co, LAST_INQUIRY_AT=2026-10-29 (Los Angeles date). MARKETING_OPT_IN stayed empty because the code omits false on existing-contact updates. The owner observed sync within one minute. The notification reached Gmail Spam.
- Test 2, checked, reference `d6b872d1d380837184e64615`: same contact #1 with no duplicate, lists #2, #3, and #4, MARKETING_OPT_IN=Yes (true). All other inquiry attributes remained correct, including the Los Angeles date 2026-10-29.
- Test 2 notification independently located through connected Gmail: received 2026-10-30 05:07:53 UTC / 13:07:53 Asia/Taipei, labeled SPAM. Subject `[MohFam] General Inquiry inquiry — Website Test`, sender `MohFam Website <website@mohfam.co>`, Reply-To `Website Test <mohfamsolutions@gmail.com>`. Gmail Authentication-Results confirms DKIM pass for mohfam.co (brevo2), SPF pass, and DMARC pass. Message ID: `<202610300507.35432596088@smtp-relay.mailin.fr>`.

Production readiness reported `litespeed_finish_request` for the after-response worker. Both contact-sync paths and notification delivery are confirmed, with spam placement on these contact tests. Inbox placement is not guaranteed by successful authentication. The owner is managing the Gmail sender filter; no sending-code, DNS, Brevo-setting, or mailbox-filter changes were made for this result. No additional test email was sent.

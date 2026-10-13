# MohFam site design audit

Review scope: the homepage, services, service detail pages, About, Contact, blog archive and article templates, category template, privacy policy, terms, and shared navigation/footer. Review the WordPress theme and public pages together; existing WordPress content and inquiries remain in place.

## Delivery plan

1. Correct commercial cleaning availability and link to https://mohfamcleaning.com/.
2. Present services everywhere in this order: Security, Commercial Cleaning, Pool Cleaning, Landscaping, Car Wash. Keep the final three marked Coming Soon.
3. Introduce the shared iPhone app on the homepage using the existing app assets and verified App Store destination.
4. Repair mobile navigation and keyboard access; improve service comparison, content hierarchy, readable labels, and shared page spacing while retaining the existing brand.
5. Check rendered desktop/mobile pages, service order, app links, navigation, and theme syntax before publishing through the existing deployment workflow.

## Initial findings

| Priority | Finding | Planned resolution |
| --- | --- | --- |
| High | Primary navigation is hidden below 900px with no mobile replacement. | Fixed: mobile menu, explicit service submenu button, Escape handling, current-page state, and accessible fallback. |
| High | Commercial cleaning is marked Coming Soon in homepage, services, footer, and inquiry options. | Fixed: new division website links, updated detail page, current-service cards, and inquiry options. |
| High | Contact inquiry options differ from homepage options. | Fixed: consistent ordered options; future-service links preselect the relevant interest. |
| High | Detail pages and About copy imply future divisions are already operating. | Fixed: Pool Cleaning and Car Wash now clearly communicate Coming Soon; About distinguishes current and planned divisions. Added the correctly named pool-cleaning template. |
| Medium | Four-column service layouts cannot accommodate five services comfortably. | Fixed: two prominent current divisions followed by three upcoming divisions; responsive stacking. |
| Medium | Unavailable cards reduce opacity on all text; several labels are 8–10px. | Fixed: readable text, larger shared labels, explicit statuses, and stronger contrast. |
| Medium | Homepage contains two main landmarks and lacks a skip link. | Fixed: one main landmark, global skip link, and a main-content target in every theme template. |
| Medium | Long generic descriptions and repeated corporate claims obscure the visitor's next step. | Improved: concise homepage/service introductions and a clear browse → explore division → inquiry path. |
| Medium | Shared app is absent from the parent company site. | Fixed: existing screenshot, App Store badge, QR code, service-family copy, and header/footer links. |

## Brand and page journey

Retain Inter for body copy, Playfair Display for editorial headings, and the approved charcoal/gold/ivory palette. Keep a consistent spacing scale and visible focus states. The homepage journey is introduction → service comparison → shared standards → app → security detail → company background → insights → inquiry. Services should distinguish live division websites from future offerings before a visitor submits an inquiry.

## Verification

- Rendered the homepage, Services, About, Contact, all four published service-detail routes, blog archive, sample article, security category archive, privacy policy, and terms. Reviewed the edited theme at 1440px, 390px, and 320px widths using the site's existing WordPress styles and real blog content.
- No horizontal overflow, duplicate main landmark, or missing/duplicate H1 remained in the checked layouts after fixing the narrow values strip.
- Automated WCAG A/AA checks reported no violations in the checked rendered states after contrast and verification-container fixes. This is a focused automated check, not a complete accessibility certification.
- Verified mobile and desktop menus, submenu controls, Escape behavior, keyboard skip-link focus, and a visible navigation fallback with JavaScript disabled.
- Verified service ordering, commercial-cleaning links, inquiry options, future-service prefilling, app asset loading, and App Store destination (`https://apps.apple.com/app/id6793692825`).
- JavaScript syntax and theme JSON checks passed locally. The deployment workflow checks PHP syntax before upload and now checks every theme JavaScript file.
- Visual review included the full homepage and service portfolio at desktop/mobile sizes, plus the app panel. Temporary preview files and screenshots are excluded from Git.
- Live inquiry submissions and email delivery were not exercised during this design review.

## Remaining design priorities

1. **Optimize the approved logo asset.** The existing `mohfam-logo.png` is about 1.7MB but displays at 34–40px. A small, approved web export would reduce the shared header/footer image payload considerably. Preserve the original brand artwork.
2. **Use original MohFam photography.** Existing office/lobby images are stock illustrations. Replace them with approved team and property photography as available; avoid presenting stock scenes as client evidence.
3. **Confirm corporate proof points.** Existing About and security content includes licensing, bonding, insurance, and operating-hours statements. Have the business owner keep those claims aligned with the specific division and current documentation.
4. **Refresh app previews as services launch.** The real screenshot currently shows the security category. Update it when other divisions appear in the app; the new copy does not claim upcoming services are already bookable.
5. **Keep future-service states consistent.** When Pool Cleaning, Landscaping, or Car Wash launches, update cards, navigation, footer, inquiry options, detail pages, and app copy together.

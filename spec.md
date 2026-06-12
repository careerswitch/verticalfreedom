# Spec: HealthFest Workshop Registration System (Custom WordPress Plugin)

## Problem Statement
The Vertical Freedom Foundation runs **HealthFest**, a one-day event with multiple workshops, presentations, and wellness activities, each with a limited number of seats. There is currently no way for visitors to reserve a seat in advance or for organizers to manage attendance and consent records.

Build a **self-contained custom WordPress plugin** (no recurring license, data owned in the site's own MySQL DB, fully managed from the WP admin) that lets visitors register for specific workshops and gives organizers a manageable, exportable participant database with an audit-grade GDPR consent record. The participant database is intended for reuse across future Foundation events.

## Success Criteria
**Visitor (front end, mobile-first):**
- View a list of workshops showing title, description, time, and **live remaining seats**.
- Select a workshop and submit a registration with contact details (name, email, phone).
- A workshop showing 0 remaining seats is **visibly closed** and cannot be submitted.
- Consent capture on the form:
  - **Required** privacy/GDPR consent — must be ticked to submit (unticked by default).
  - **Photo/video recording consent** — captured explicitly (see Open Questions re: required vs optional).
  - **Optional** future-events marketing opt-in — separate checkbox, unticked by default, never bundled with the required consent.
- On success the participant receives an **automatic confirmation email**.

**Organizer (WP admin):**
- Create/edit workshops and set a **seat limit** per workshop.
- Registration **auto-closes** for a workshop the moment its confirmed count reaches the seat limit.
- View all registrations in an admin list (filter by workshop), backed by a **custom DB table**.
- **Export to CSV/Excel** (all registrations or per workshop), including consent values.
- For every registration, a **consent audit record** is stored: each consent type, its boolean value, timestamp, IP address, and the privacy-policy version in effect at submission.

**Engineering (definition of done):**
- Seat enforcement is **atomic** — concurrent submissions cannot oversell the last seat.
- Server-side validation on every field; nonce/CSRF protection; capability checks on all admin actions; prepared statements for all DB access; **per-IP rate limiting** on the public registration endpoint (nonce alone is insufficient — guest nonces are shared).
- Developed and verified on **staging** (`staging.verticalfreedom.org`) before any production deploy.
- Confirmation emails deliver reliably (SMTP configured, not raw PHP `mail()`).

## Out of Scope
- **Payments / fees** — registration is free; no payment gateway, invoicing, or refunds.
- Modifying WordPress core, `wp-config.php`, or the existing theme beyond what's needed to render the registration form (shortcode/block).
- User accounts / login for participants — registration is anonymous (email-identified), no front-end account system.
- Check-in / on-site attendance scanning, badge printing.
- A separate analytics dashboard beyond the admin list + CSV export.
- Migrating or restructuring the existing WordPress site content.

## Environment (staging — confirmed by download 2026-06-11)
- **Theme:** `Vertical Freedom Custom` (child of **Holistic Center**, ThemeREX). Page builder: **WPBakery** (`js_composer`) + Revolution Slider — not Gutenberg/Elementor → form ships as a **shortcode** `[healthfest_registration]`.
- **Multilingual: Polylang Pro** (+ Polylang-WC). **Romanian is primary**, English secondary. Workshop CPT registered translatable; plugin strings via `pll_register_string()`/`pll__()`.
- **Seat pool shared across translations:** EN/RO versions are separate posts; all seat ops canonicalize to the default-language (RO) post ID (`HF_Seats::canonical_id`).
- **WooCommerce** (Romanian: EuPlatesc gateway, donations, SmartBill, SameDay). Registration is **free** → plugin stays independent of Woo.
- **Caching: LiteSpeed Cache** (+ SpeedyCache, QuickWebP) → availability + submit go via **AJAX (no-cache + nonce)**; document a LiteSpeed exclusion for the form page.
- **Email: `bws-smtp` installed** → `wp_mail()` confirmations have a delivery path. Mailchimp-for-WP present (marketing opt-ins may feed it later).
- **Security:** Sucuri + AIOS + wp-file-manager → standard nonces/AJAX should pass; watch for firewall blocks on POST.
- **Roles:** `Members` plugin → expose capability `manage_healthfest` (falls back to `manage_options`).
- **DB prefix** `IO9Dnj_` (hardened) → plugin uses `$wpdb->prefix`, adapts automatically.
- **Multisite** allowed (`WP_ALLOW_MULTISITE`) but **not active** → single-site; revisit activator if it's ever networked.
- Already installed: **Forminator** + Contact Form 7 (evaluated; custom plugin chosen for seat caps + consent audit + cross-language seat pool).

## Known Constraints
- **Platform:** Existing WordPress site on shared cPanel hosting; data must stay in the site's MySQL DB and be managed from the WP admin.
- **Deploy pipeline:** Local git (source of truth, GitHub `careerswitch/verticalfreedom`) → FTPS upload via VS Code SFTP extension → **staging first**, then production. No direct production edits.
- **Email deliverability:** Shared cPanel mail commonly lands in spam; requires an SMTP plugin (e.g., free FluentSMTP) + a provider and SPF/DKIM on the domain.
- **GDPR:** Granular, unbundled consent; durable consent audit trail; defined retention policy.
- **Security:** Treat all form input as hostile; least-privilege admin capabilities; no secrets committed to git.
- **UX:** Must be simple and mobile-friendly for visitors and non-technical for organizers.

## Open Questions
1. **Hosting environment:** Confirmed PHP version and WordPress version on staging/production? (Sets minimum plugin compatibility.)
2. **Scale:** How many workshops, and rough expected total registrations? (Informs DB indexing / no-pagination concerns.)
3. ~~Multiple workshops per person~~ — **RESOLVED: multiple** (see Decisions Locked).
4. ~~When full: waitlist?~~ — **RESOLVED: hard close.**
5. ~~Cancellations~~ — **RESOLVED: admin-cancellable + must be reported by participant.**
6. ~~Photo/video consent required?~~ — **RESOLVED: optional.**
7. **Email details:** Sender name/address, confirmation email content/branding, and any organizer notification email on each new registration?
8. **SMTP provider:** Which service for outbound mail (host SMTP, or a provider like a transactional-email service)? Determines deliverability setup.
9. **Language/localization:** Is the form English, Romanian, or bilingual? (Host is `.ro`.)
10. **Privacy policy:** URL of the privacy policy to link from the consent, and how its **version** is tracked for the audit log.
11. **Data retention:** How long are participant records and consent logs kept before purge?

## Decisions Locked
- Build approach: **Custom WordPress plugin** (chosen over off-the-shelf events plugin and form-plugin+inventory).
- Registration is **free** — no payment gateway.
- Develop/test on **staging** before production.
- **Multiple workshops per participant** — one participant record, many registrations (one per workshop). A participant cannot register for the same workshop twice.
- **Hard close** when a workshop is full — no waitlist.
- **Admin-cancellable** registrations (cancelling frees the seat). The registration form and confirmation email must include **a clear sentence instructing participants that cancellations have to be reported** to the organizer so the seat can be released. A participant whose registration was cancelled **may sign up again** for the same workshop (re-registration revives the existing record rather than being blocked by the duplicate-prevention key).
- **Photo/video consent is optional** (captured separately; not required to register). Privacy/GDPR consent remains required.
- **Event: HealthFest, 26–28 June 2026** (Fri–Sun), Romanian-primary content. Source schedule: `Program terapeuti Healthfest.xlsx` (note: a "27.05" in the sheet means **27.06**).
- **Phase 1 = group workshops only** (seat-limited *ateliere*, ~19 across 3 days). The **individual 1-on-1 sessions** (therapist availability windows) are **deferred** — they're appointment-style, a different model.
- Workshop CPT carries: title, description, **presenter/therapist**, **start + end datetime**, location, seat limit. (Presenter + end time added after reviewing the program.)
- Therapists in the program: Mira, Andreea, Lacra, Izabela, Anamaria, Oana, Virgi, Roxana, Mirela, Timea, Leo Lions, Ioana D., Ioana.

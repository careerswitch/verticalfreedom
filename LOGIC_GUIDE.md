# LOGIC_GUIDE.md

## Purpose
Tracks architectural decisions, non-obvious tradeoffs, and resolved bugs across sessions.
Update immediately when: architecture shifts, a tradeoff is made, a recurring bug is resolved.
Do not defer updates to end-of-session.

## Entries

### [2026-06-17] — v0.5.1: bilingual (RO + EN) UI shipped in code; workshop logistics canonicalized
**What:** Made the registration form, validation, confirmation email, and availability genuinely bilingual without any manual Polylang string-translation step.
1. **Built-in EN string table.** `HF_Strings::en()` mirrors `all()` (RO). `t()` precedence: organizer Polylang String-translation override → built-in EN when the active language is not the default → Romanian. `HF_Strings::use_english()` decides by comparing the current Polylang slug to `pll_default_language()` — NOT a hardcoded `'en'` — so it works for any English slug (`en`, `en-gb`, `en-us`).
2. **Language propagation through admin-ajax.** The shortcode passes the real Polylang slug to JS (`HF_DATA.lang`); the JS echoes it on submit + availability; the handler calls `HF_Strings::set_lang()` so the email + JSON messages localize despite admin-ajax having no page language.
3. **Logistics canonicalized.** Seat limit, schedule, presenter, location are authored once on the canonical (RO) post and read from it everywhere (form, email, admin column). Secondary-language posts render these fields read-only and don't save them — an EN copy needs only a translated Title + Description.
4. **Query hardening.** `get_workshops()` and the availability endpoint are scoped to the active language slug (belt-and-suspenders on Polylang's own filter; stops RO+EN both listing on one page) and the workshop list is sorted in PHP by the canonical start time.
**Why:** v0.4.0 relied entirely on Polylang String-translations being filled in by hand (they never were), so every EN page fell back to Romanian. Two bugs surfaced on staging: (a) the check `'en' === $slug` failed because the site's English slug isn't literally `en`, leaving EN pages in Romanian while content was correctly EN-scoped; (b) canonicalizing logistics removed `_hf_start_datetime` from EN posts, and the old SQL meta-ordered query then excluded those posts entirely (empty EN list).
**Decision — single-page day layout kept:** Considered per-day pages/tabs; kept the one-page, day-grouped form because it is one submission → one confirmation email even when a participant attends multiple days. Per-day separate pages would fragment that (multiple submissions/emails). A `day=""` shortcode attribute was discussed but not built.
**Accepted risks:** Organizer wording overrides entered in Polylang → String translations show on the page but NOT inside the confirmation email (admin-ajax resolves overrides in the default language); the built-in EN keeps the email correctly English. Workshop *content* (titles/descriptions) is still organizer-translated in Polylang — EN drafts provided in `WORKSHOPS_TO_CREATE.md`.
**Verified:** Staging, 2026-06-17 — EN page renders English UI + the translated workshop(s) with correct schedule and live seat counts; RO page unchanged; shared seat pool confirmed across languages.

### [2026-06-12] — v0.4.0 hardening pass: cancel/re-register fix, rate limiting, orphaned-seat guard, migration routine
**What:** Four changes after an architecture review of v0.3.0.
1. **Cancel → re-register bug (correctness).** `insert_registration()` now *revives* a cancelled row (UPDATE status→confirmed, clear `cancelled_at/by`) instead of a plain INSERT. A confirmed row still returns 0 so the caller releases the just-reserved seat.
2. **Per-IP rate limiting (abuse).** Transient-backed throttle on `hf_register` — `HF_RATE_LIMIT_MAX` (10) submissions per `HF_RATE_LIMIT_WINDOW` (600s) per IP, both overridable via wp-config. Fails OPEN when the IP is unknown.
3. **Orphaned-seat guard (resilience).** `HF_Seats::canonical_exists()` — `availability()` reports a trashed/deleted canonical workshop as full; `reserve()` refuses it, instead of incrementing a ghost counter.
4. **Schema migration routine.** `HF_Activator::maybe_upgrade()` runs on `plugins_loaded`, compares `get_option('hf_db_version')` to `HF_DB_VERSION`, and re-runs idempotent `dbDelta` on a forward bump. (No-op today; `HF_DB_VERSION` stays `1` — no schema changed.)
**Why:** (1) The `UNIQUE(participant_id, workshop_id)` key keeps cancelled rows on file, so re-registration hit the constraint, failed silently, and locked the participant out permanently. (2) The nonce is weak — anonymous `nopriv` nonces are shared across all guests for ~24h — so it doesn't stop a scripted flood. (3) Polylang's canonical (default-language) post can be trashed by an editor mid-event, orphaning the seat row. (4) `activate()` only fires on activation, so a plugin update that bumped the schema never migrated existing installs.
**Review outcome — claims rejected as already-correct (do not re-litigate):** *Monolithic DB* — schema already splits the 3-col `hf_workshop_seats` ledger from PII tables. *App-level-only dedup* — DB-level `UNIQUE(participant_id, workshop_id)` + `UNIQUE(email)` already enforce one-per-workshop at the engine. *Premature CPT registration* — the CPT class is instantiated on `plugins_loaded` but `register_post_type()` is hooked to `init`, which is correct.
**Decision — kept admin-ajax over REST:** The "admin-ajax exhausts CPU, switch to REST" claim is overstated — both transports bootstrap full WP + all plugins, and POST submissions are uncacheable on LiteSpeed either way. The real lever for the availability poll is caching the counts (deferred), not changing transport. Kept admin-ajax; added rate limiting instead.
**Accepted risks:** Rate-limit window resets on each counted hit (a sustained attacker stays blocked; a quiet IP recovers after the window) — acceptable for a free, one-event endpoint. Cancel/re-register reuses the same registration row id, so its consent audit rows accumulate across both sign-ups (append-only audit, by design).
**Verified:** Staging, 2026-06-12 — cancel→re-register succeeds; rate limit returns the friendly message; trashed workshop drops from the form; seat math confirmed 1/10 across admin + front end. Step 5 admin (list, filter, consents, CSV diacritics, cancel-frees-seat) also verified the same day.

### [2026-06-11] — HealthFest registration: custom plugin, Polylang-driven i18n, canonical seat pool
**What:** Building a custom WP plugin (`healthfest-registration`) for workshop sign-ups. Workshop CPT is Polylang-translatable; all seat accounting canonicalizes to the default-language (Romanian) post via `HF_Seats::canonical_id`. Atomic seat reservation via a single guarded `UPDATE … WHERE seats_taken < seat_limit`. Form delivered as a shortcode; availability + submit over AJAX (no-cache) due to LiteSpeed.
**Why:** Site runs Polylang Pro (RO primary, EN secondary). EN and RO versions of a workshop are separate posts with different IDs — without canonicalization their seat counts would split, letting a workshop oversell. LiteSpeed would otherwise serve stale seat counts. Custom chosen over installed Forminator because Forminator can't do per-workshop seat caps + audit-grade consent logging + a shared cross-language seat pool.
**Alternatives rejected:** Off-the-shelf events plugin / Forminator (compromise on seat caps + consent audit + language pool); rolling a custom i18n layer instead of Polylang (would duplicate the site's existing translation system).
**Accepted risks:** Seat limit is authoritative only on the RO workshop (secondary-language field is read-only) — organizers must set limits on the Romanian version. Security plugins (AIOS/Sucuri) may need a firewall allowance for the AJAX endpoint.

### [2026-06-11] — Version control wired to GitHub while deploying via SFTP/cPanel
**What:** Initialized local git, set default branch `main`, added origin `git@github.com:careerswitch/verticalfreedom` (HTTPS URL rewritten to SSH by a global `insteadOf` rule). Active `gh` account set to `careerswitch`.
**Why:** SFTP-to-cPanel is the deploy channel, not a VCS remote. `/initproject`'s remote gate needs a real git origin, so history lives in GitHub while SFTP continues to push files to the cPanel docroot.
**Alternatives rejected:** cPanel native Git over SSH (SFTP-only plan likely has no SSH); local-only git with no remote (no off-machine backup).
**Accepted risks:** Deploy (SFTP) and source-of-truth (GitHub) are decoupled — drift is possible if files are edited directly on the server outside git.

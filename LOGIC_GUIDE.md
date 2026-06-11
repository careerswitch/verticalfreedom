# LOGIC_GUIDE.md

## Purpose
Tracks architectural decisions, non-obvious tradeoffs, and resolved bugs across sessions.
Update immediately when: architecture shifts, a tradeoff is made, a recurring bug is resolved.
Do not defer updates to end-of-session.

## Entries

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

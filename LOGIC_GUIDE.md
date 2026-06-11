# LOGIC_GUIDE.md

## Purpose
Tracks architectural decisions, non-obvious tradeoffs, and resolved bugs across sessions.
Update immediately when: architecture shifts, a tradeoff is made, a recurring bug is resolved.
Do not defer updates to end-of-session.

## Entries

### [2026-06-11] — Version control wired to GitHub while deploying via SFTP/cPanel
**What:** Initialized local git, set default branch `main`, added origin `git@github.com:careerswitch/verticalfreedom` (HTTPS URL rewritten to SSH by a global `insteadOf` rule). Active `gh` account set to `careerswitch`.
**Why:** SFTP-to-cPanel is the deploy channel, not a VCS remote. `/initproject`'s remote gate needs a real git origin, so history lives in GitHub while SFTP continues to push files to the cPanel docroot.
**Alternatives rejected:** cPanel native Git over SSH (SFTP-only plan likely has no SSH); local-only git with no remote (no off-machine backup).
**Accepted risks:** Deploy (SFTP) and source-of-truth (GitHub) are decoupled — drift is possible if files are edited directly on the server outside git.

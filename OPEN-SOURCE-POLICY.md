# Open-source policy

How Rushes handles the open-source software it is built on: its licenses, and
its known security holes. Written to meet OpenChain ISO/IEC 5230 (license
compliance) and ISO/IEC 18974 (security assurance).

Rushes Media Management Software is made by Alejandro Renteria, who is
responsible for this policy.

## 1. The policy

- Every open-source part Rushes ships, downloads for the user, or needs on the
  machine it runs on is listed in [CREDITS.md](CREDITS.md) with its license and
  where it comes from. Nothing is added to Rushes without being added there.
- Rushes follows the license of each part, and claims none of them as its own.
- Rushes watches those parts for known security holes, fixes or replaces what
  is affected, and says so.

## 2. What this covers

**Covered:** everything published at github.com/x0on/rushes and in its
releases: the pages, the helper's code, the Mac apps (Rushes and Rushes
Watcher) and the Premiere panel. It also covers what Rushes downloads onto a
user's machine during setup (its own Python, FFmpeg, the describing tools and
their models).

**Not covered:** the operating system, the NAS's own software, the editing
programs, and whatever else is already on the machine. Rushes uses them and
does not supply them.

The scope is decided by one question: does Rushes hand this software to the
user, or fetch it for them? If yes, it is covered. The scope is looked at again
at each review (section 11).

## 3. Who does what

Rushes has one maintainer. He holds every role.

| Role | Who | What the role needs |
|---|---|---|
| Owner of this policy | Alejandro Renteria | Knows what Rushes ships and how it is built |
| License review | Alejandro Renteria | Can read a license and tell which group of section 4 it is in |
| Security of the parts | Alejandro Renteria | Can read a vulnerability notice and tell whether Rushes uses the affected part |
| Releases | Alejandro Renteria | Runs the build and its checks |
| Legal advice | Not named yet | Licensed to practise; asked when a license is unclear or a complaint arrives |

**Checked competence.** 2026-11-25: Alejandro Renteria wrote this policy,
keeps CREDITS.md, and set up the scanning in section 9. Checked again at each
review.

**Awareness.** Anyone who changes Rushes' code, a person or an AI coding
assistant, is pointed here by README.md and HANDOFF.md before they start. They
are expected to know: this policy and where it is; its aim (section 1); that
their part is to list every new component and its license in the same change;
and that a change which adds an unlisted part, or breaks a license, is not
released.

**Legal advice is the one role not filled.** Until a lawyer or firm is named
here, an unclear license means the part is not added (section 4).

**Time and money.** The work is the maintainer's own time. The tools are free.
Legal advice is paid for when it is needed.

## 4. Which licenses, and how each is used

| Group | Examples | How Rushes may use it |
|---|---|---|
| Permissive | MIT, BSD, Apache-2.0, PSF, PHP License, public domain | Shipped inside Rushes, with its notice and license text |
| Weak copyleft | MPL-2.0, LGPL | Shipped unmodified, with its license text and where its source is. When one is built into a program Rushes ships, the public recipe that builds that program is published, so anyone can rebuild it with their own copy of the part |
| Strong copyleft | GPL, AGPL | Never copied into Rushes' own code and never linked into it. Only run as a separate program |
| Anything else, or unclear | | Not added until legal advice says how |

## 5. Adding or updating a part

1. **Identify it:** its name, version, license, and where it comes from.
2. **Review it:** find its group in section 4. Read what its license asks of
   someone who passes it on.
3. **Approve it:** the maintainer decides. An unclear license goes to legal
   advice first.
4. **Record it:** a line in CREDITS.md and an entry in `mac/parts.json` in the
   same change, with its exact version and its SHA-256 fingerprint. A test
   holds the two to each other and to what the code really downloads.
5. **Archive it:** the git history keeps every version of CREDITS.md. Each
   release keeps its own list of parts (section 7).

The cases the license review answers each time:

- **Passed on as a built program** (the Mac apps): the part's notice and license
  text go inside the app.
- **Passed on as source** (the repository): Rushes' own code only. No
  third-party source is copied into it; ideas taken from other projects are
  credited in CREDITS.md.
- **Joined with other software:** Rushes runs other programs (FFmpeg, PHP)
  as separate programs. It links none of them into its own code. PHP and
  FFmpeg are compiled from their projects' own source by the public recipes at
  github.com/x0on/rushes-parts, with only the parts Rushes uses.
- **Modified open source:** Rushes ships none. If that changes, the changes are
  marked, published, and listed in CREDITS.md.
- **Licenses that do not go together:** a strong-copyleft part is never built
  into the same program as Rushes' own code or as a part whose license forbids it.
- **Attribution:** CREDITS.md is shown inside the app and is in the repository.

## 6. Questions and complaints about licenses

Anyone can ask: open an issue at https://github.com/x0on/rushes/issues.

1. The maintainer answers within 14 days.
2. He checks the question against CREDITS.md and the release it is about.
3. If Rushes is not following a license: the affected release stops being
   offered if the problem is serious, the fix is made, a new release is
   published, and CHANGES.md says what was wrong.
4. The question and its answer stay public in the issue.

## 7. What goes with each release

- CREDITS.md, inside the app and in the repository.
- The license text of each part shipped inside the app, in the app's
  `LICENSES` folder. The build stops if one is missing.
- A list of parts with exact versions (an SBOM, `Rushes <version>.cdx.json`,
  in CycloneDX format), attached to the release.

These are kept for as long as a release is offered, and at least three years
after: in the git history and in the releases on GitHub.

## 8. Giving back to other projects

- Contributions to other open-source projects (a bug report, a fix) are made by
  the maintainer in his own name, under that project's own license and rules.
- Only work he wrote himself is contributed. Nothing is contributed that
  belongs to someone else.
- Anyone working on Rushes is told this by this section.

## 9. Security of the open-source parts

**Finding threats.** When a part is added, the review asks: does it read files
from outside (footage, uploads)? Does it reach the network? What can it change?

**Finding known holes.**
- GitHub dependency alerts, on.
- CodeQL on every push and every week (Python, JavaScript, C, the workflow).
- At each release, every part on that release's list is checked against the
  public vulnerability databases (osv.dev and the part's own security page).

**Following up.** A hole in a part Rushes really uses is fixed, or the part is
updated or replaced, before the next release. One that cannot be reached the
way Rushes uses the part is written down with the reason.

**After a release.** The weekly scan and the dependency alerts keep running on
what is published. A hole found later is handled the same way.

**Before a release.** Every check must pass (the `tests` check is required on
main, and the build refuses a commit that did not pass). No release is made
with an open alert of high severity that has no written reason.

**Telling users.** A fix for a hole that affects users is said in CHANGES.md
and the release notes, and as a GitHub security advisory when it is serious.

**Reports from others.** See [SECURITY.md](SECURITY.md): private reports, an
answer within 7 days.

**Giving the information to others.** The release's list of parts, the
advisories and the log below are public.

**The record.** [SECURITY-LOG.md](SECURITY-LOG.md): one line for each known
hole looked at, with what was done, including "nothing needed, because…".

## 10. How it is measured

- Open scanning alerts (aim: none of high severity).
- Days from a report or alert to its fix.
- Parts with a known hole at release time (aim: none without a written reason).
- Parts in a release that are missing from CREDITS.md (aim: none).

## 11. Review

This policy, its scope and its roles are reviewed at least once a year, and
never later than 18 months after the last review. Each review is written here.

| Date | By | What was looked at, and what changed |
|---|---|---|
| 2026-11-25 | Alejandro Renteria | Policy written. Scanning, private reports and SECURITY.md set up. Parts list and license texts added to the build. Python, PHP and FFmpeg moved to current releases. Rushes Community License 1.0 published. |

# Audiovisual Intelligence Services — pitch preview

Provisional English descriptor for the complementary Rushes + StoryTown service offering. Independent static pitch site; no application dependencies, shared assets, backend, analytics, or submission endpoint. The pilot CTA opens a discussion guide and downloads a plain-text brief. No inquiry is sent.

## Isolation and deployment

Prepared against x0on/rushes main at `05779da523b5e72f68cd03080d6fc1160275fe86`. Repository inspection confirms `app/` contains installed application code and `docs/` is the existing GitHub Pages website (documented in DEVELOPING.md). The only checked-in workflow is the test workflow, triggered on push and pull request; no deployment workflow was found.

All proposed files belong in root `pitch-site/`, outside both directories. No existing files, workflows, navigation, product behavior, Pages settings, or production deployments are changed. Keep on the review branch until explicitly approved. Do not move into `docs/`, merge, or change Pages configuration without approval. Publishing later requires a separately approved hosting decision; this directory is not automatically routed into the live Rushes website.

## Preview

Open index.html directly, or run `python3 -m http.server 4173 --directory pitch-site` from the repository root and visit http://localhost:4173. No build or installation required. Google Fonts are an optional external stylesheet request; system font fallbacks keep the site usable offline.

## Content basis

Rushes claims are limited to the current repository README (archive organization, search, verified copying, local archive processing). StoryTown language follows the supplied pitch brief, without additional verified technical claims. No automatic integration is claimed. The city example is explicitly illustrative; no customer, results, endorsement, pricing, or performance statistic is invented. The pilot establishes compatibility, costs, rights, governance, and suitability before wider adoption. The working descriptor is not a final brand.

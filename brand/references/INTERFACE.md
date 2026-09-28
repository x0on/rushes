# Rushes interface concept

See interface-search-ingest.png. This is a proposed visual direction, not implemented software or a final feature specification.

## Navigation

Search is the default view. Ingest is a peer tab, always accessible. Manage sits at the upper right with a lock indicator; administrative actions require the owner's local admin password. No account/profile UI is needed. A global transfer indicator lets people browse while queued work continues.

## Search

Use the familiar visual browsing patterns of professional stock libraries: prominent search, media-type tabs, useful filters, readable thumbnails, and an asset-details panel. Results come from the user's archive. No shopping, licensing, or stock download controls.

Group results by event/shoot by default. Show filename, media type, duration for video/audio, and relevant technical metadata. Still images should not display duration badges (the generated image incorrectly gives Harbor_041.jpg a duration). Audio gets a waveform preview when available. Keep Archive, Projects, and Library as browsing scopes. Saved searches are a proposed enhancement.

Search initially means indexed names, folders, events, and tags. The mockup does not imply AI scene recognition. Filters should only be offered for metadata the application actually indexes.

The inspector can show preview, resolution, frame rate, date, tags, and path. A Reveal file action needs platform-aware behavior: a browser accessing remote storage may need Copy path instead of opening the host's file manager.

## Ingest

Use a focused source → shoot details → destination workflow. Let the user confirm the event/date/owner tag and destination before queuing a job. Show what is copying, bytes transferred, and any queued jobs. Distinguish copying, verification, complete, interrupted, and failed states.

The screenshot shows an active transfer, so its upper-right “Transfers idle” label is a generation error: replace it with “1 transfer running.” The screenshot is an in-progress state; the pre-transfer form needs a clear “Start ingest” action. Pause should be included only if the runner supports safe interruption and resumption. Do not promise pause/resume solely because it appears in the concept.

Advanced transfer options can expand within Ingest, keeping the basic workflow short. Explain the actual verification method and show a verified state only once the required checks pass. Originals remain on the source.

## Manage

Open a separate password-protected workspace rather than crowding search with maintenance tools. Show conditions with clear actions: possible duplicates to review, rebuildable cache to review, organization proposals, storage health, and job failures. Show an all-clear state when there is nothing requiring attention. Holding-folder actions must explain that moving files does not recover space until the folder is deliberately emptied.

## Visual rules

Follow README.md and ocean-tokens.css. The generated mockup has some shading and approximate colors; implementation should use the exact tokens, restrained flat surfaces, visible focus states, and tested contrast. Preserve the selected Together logo. The dark interface is a starting direction; light mode may share the same layout.

## Reference research

- Artlist catalog and filtering: https://help.artlist.io/hc/en-us/articles/29595878738589-Browsing-Artlist-s-Footage-catalog
- Envato video browsing: https://elements.envato.com/stock-video/search
- Shutterstock footage: https://www.shutterstock.com/video
- OffShoot verification and transfer distinctions: https://docs.hedge.video/offshoot/features/verification

These inform general browsing and transfer patterns; Rushes should retain its own branding and workflow. “artisli.ai” was interpreted as Artlist for this first concept.

## Generation

Created with the built-in image-generation tool. Prompt: two stacked high-fidelity Rushes desktop screens using the selected layered R logo and Ocean palette; a professional stock-library-inspired Search view of local harbor documentary assets with filters, shoot grouping, and inspector; and an Ingest view with source, shoot details, destination, copying progress, verification status, and queue. Shared Search/Ingest tabs and separate Manage entry. No accounts, prices, or licensing controls.

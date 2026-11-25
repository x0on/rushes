# Security

## Reporting a problem

Report it privately: on this repository, open the Security tab and choose
"Report a vulnerability". Please do not open a public issue for a security
hole, so it can be fixed before anyone can use it.

## What to expect

- An answer within 7 days.
- Rushes is maintained by one person. You will be told plainly how long a fix
  will take.
- Your name in the release notes when it is fixed, if you want it there.

## Which versions get fixes

Only the latest release. Rushes never updates itself: the user presses Update.

## How Rushes is meant to run

These are decisions, not holes:

- Rushes runs on a local network, or through a VPN. It is never meant to be
  put on the public internet.
- Search, pulls and ingest need no password: anyone on that network can use
  them. The Manage page has a password.
- Rushes never phones home.

What counts as a hole: anything that lets someone on the network read, change
or delete files outside the archive, run commands on the machine, or get past
the Manage page's password.

## How Rushes is checked

- Every push is scanned by CodeQL, and again every week.
- Every change must pass the tests before it reaches main.
- Every release is signed (app/release.sig) and checked before an update is
  taken.

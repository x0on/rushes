<p align="center"><img src="docs/assets/logo.svg" width="90" alt="Rushes"></p>

# Rushes

**Less lost. More made.**

Rushes looks after a video archive on your own storage. It is for filmmakers,
video departments and production teams whose footage is spread over servers,
drives and camera cards. You run it on your own computers, and your footage
never leaves them.

[Website](https://x0on.github.io/rushes/)

## What it does

- **Brings footage in safely.** It copies camera cards and old servers into the
  archive. Every copy is read back and compared with the original before it
  counts, and is recorded in the film industry's copy-proof format (ASC MHL).
  Nothing is copied twice.
- **Finds it again.** You can search by file name, camera, reel, what the
  picture shows, the words on screen, and what was said. The descriptions and
  transcripts are made on your own computer.
- **Hands it to the edit.** You collect clips into a *pull* and download it for
  Premiere, Final Cut or DaVinci Resolve, with markers, or as a list of paths,
  or the files themselves.
- **Keeps the archive tidy.** It sorts footage into your departments or clients,
  and edit projects can be pointed at the new places. It also finds
  duplicate copies and editing caches and moves them to a holding folder.
- **Keeps checking.** It re-reads copies against their fingerprints, counts how
  many copies of each file exist, and warns when one is lost.

## What it never does

- It never deletes your footage. Duplicates and caches are *moved* to a holding
  folder, and only you empty it. What Rushes does delete is listed, one by one,
  in [HOW-IT-WORKS.md](HOW-IT-WORKS.md#what-rushes-deletes-or-moves).
- It never sends your footage, descriptions or transcripts outside your own
  network.
- It never installs new scripts or pages on your server until you approve them.
  The one exception is the helper's own copying code, which updates itself,
  but only to a release signed by the Rushes author.
  [HOW-IT-WORKS.md](HOW-IT-WORKS.md#updates) says how.

## Status

Rushes is used every day on one NAS-based archive, with a Mac doing the
copying. It is still in development: expect changes. The
[roadmap](ROADMAP.md) lists what is proven, what is not, and the known problems.

Today Rushes is built for an office network. To use it from elsewhere, connect
through a VPN. Putting it directly on the internet is not supported yet.

## Read more

| If you want to… | Read |
|---|---|
| understand everything Rushes does, in plain words | [HOW-IT-WORKS.md](HOW-IT-WORKS.md) |
| install it, update it, or fix it when something looks wrong | [INSTALL.md](INSTALL.md) |
| change the code | [DEVELOPING.md](DEVELOPING.md) |
| see what comes next and what is not right yet | [ROADMAP.md](ROADMAP.md) |
| see what it is built from | [CREDITS.md](CREDITS.md) |

## License

Rushes is **source-available**: anyone can read all of its code, and check it
against [HOW-IT-WORKS.md](HOW-IT-WORKS.md). It is not "open source" in the
strict sense, because large organisations pay.

The license being prepared (the Rushes Community License, by Alejandro
Renteria) will make it:

- **free** for individuals, and for organisations with less than US $10 million
  a year in revenue, including for commercial work;
- **paid** for organisations above that, through a commercial license.

Until that license is published in this repository, the code can be read but not
reused.

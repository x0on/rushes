# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Two things every transfer leans on:

- storing a file safely: the few operations that can lose footage when done
  carelessly, written once here and used by ingest.py and runner.py alike
  (HOW-IT-WORKS.md → How a file is copied);
- local durable checkpoints and a retryable search outbox; no mounted drive needed.

It lives in this file, not one of its own, because the helper's code is a fixed,
signed set of four files (release.py): a fifth would not reach a helper that
updates itself from an older release."""
import errno
import json
import os
import secrets
import sqlite3
import sys
import time
import urllib.parse
import urllib.request


# ── storing a file safely ───────────────────────────────────────────────────
CHUNK = 8 << 20


def same_bytes(a, b, tick=None):
    """Every byte of both files, read and compared. True only when they are the
    same file's content; False when they differ; None when either cannot be
    read (never "the same"). tick(): called between chunks (a heartbeat)."""
    try:
        if os.path.getsize(a) != os.path.getsize(b):
            return False
        with open(a, "rb") as fa, open(b, "rb") as fb:
            while True:
                x, y = fa.read(CHUNK), fb.read(CHUNK)
                if x != y:
                    return False
                if not x:
                    return True
                if tick:
                    tick()
    except OSError:
        return None


def file_key(st):
    """What a remembered fingerprint is tied to (st: the file's os.stat): size, the
    time it last changed to the nanosecond, and the file's own number on its drive
    (a file replaced by another of the same size and date has a new one)."""
    return f"{st.st_size}|{st.st_mtime_ns}|{st.st_ino}"


def inside(path, root):
    """True when path really leads into root: shortcuts (symlinks) on the way
    are followed first, so a folder that is a shortcut to somewhere else does
    not count as inside. The path itself need not exist yet."""
    r = os.path.realpath(root).rstrip(os.sep)
    p = os.path.realpath(path)
    return p == r or p.startswith(r + os.sep)


def new_part(dest):
    """A temporary file beside dest that did not exist before and is this
    process's alone: (file opened for writing, its path). Never opens a file
    that is already there, nor writes through a shortcut."""
    d, n = os.path.split(dest)
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    for _ in range(20):
        tmp = os.path.join(d, f"{n}.{secrets.token_hex(4)}.part")
        try:
            return os.fdopen(os.open(tmp, flags, 0o644), "wb"), tmp
        except FileExistsError:
            continue
    raise OSError(errno.EEXIST, "could not make a temporary file", dest)


def _rename_excl(src, dst):
    """The operating system's own rename that refuses to replace: macOS
    renamex_np(RENAME_EXCL), Linux renameat2(RENAME_NOREPLACE). True when done,
    False when this system or drive does not offer it; raises otherwise."""
    try:
        import ctypes
        libc = ctypes.CDLL(None, use_errno=True)
        if sys.platform == "darwin":
            fn, args = libc.renamex_np, (os.fsencode(src), os.fsencode(dst), 0x4)
        else:
            fn, args = libc.renameat2, (-100, os.fsencode(src), -100, os.fsencode(dst), 0x1)   # AT_FDCWD
    except (ImportError, OSError, AttributeError):
        return False
    if fn(*args) == 0:
        return True
    e = ctypes.get_errno()
    if e in (errno.EINVAL, errno.ENOSYS, errno.ENOTSUP, getattr(errno, "EOPNOTSUPP", errno.ENOTSUP)):
        return False
    raise OSError(e, os.strerror(e), dst)


def rename_new(tmp, dest):
    """tmp (a finished copy, or a file being moved on the same drive) takes the
    name dest, only if nothing has that name: a file that
    appeared there meanwhile (Finder, an editor, another copy) is never
    replaced; FileExistsError says so. The folder's new entry is then stored
    on the disk, so the name survives a power cut as well as the bytes."""
    if not _rename_excl(tmp, dest):
        try:
            os.link(tmp, dest)                 # fails when dest exists: one step, no gap
            os.unlink(tmp)
        except FileExistsError:
            raise
        except OSError:
            # ponytail: a drive with neither (exFAT, some shares): look, then
            # rename. The gap between the two is microseconds, not zero.
            if os.path.lexists(dest):
                raise FileExistsError(errno.EEXIST, "a file of that name is already there", dest)
            os.rename(tmp, dest)
    try:
        fd = os.open(os.path.dirname(dest) or ".", os.O_RDONLY)
        try: os.fsync(fd)
        finally: os.close(fd)
    except OSError:
        pass                                   # a share may not allow it; the bytes are stored already



class TransferState:
    def __init__(self, path, url):
        self.url = url.rstrip('/')
        self.db = sqlite3.connect(str(path))
        self.db.execute('PRAGMA journal_mode=WAL')
        self.db.execute('CREATE TABLE IF NOT EXISTS search (path TEXT PRIMARY KEY, bytes INTEGER, attempted REAL DEFAULT 0)')
        self.db.execute('''CREATE TABLE IF NOT EXISTS completed (
            job TEXT, source TEXT, path TEXT, bytes INTEGER, kind TEXT,
            PRIMARY KEY(job,source,path))''')
        self.db.execute('CREATE TABLE IF NOT EXISTS arrivals (path TEXT PRIMARY KEY, bytes INTEGER)')
        self.last_search = self.last_report = 0
        self.search_delay = self.report_delay = 10

    def post(self, endpoint, data):
        body = urllib.parse.urlencode(data).encode()
        with urllib.request.urlopen(self.url + endpoint, data=body, timeout=5) as r:
            return json.loads(r.read().decode())

    def landed(self, path, size):
        with self.db:
            self.db.execute('INSERT OR REPLACE INTO arrivals VALUES (?,?)', (path, size))
            self.db.execute('INSERT INTO search (path,bytes) VALUES (?,?) ON CONFLICT(path) DO UPDATE SET bytes=excluded.bytes', (path, size))

    def flush(self, force=False):
        if not force and time.monotonic() - self.last_search < self.search_delay:
            return
        self.last_search = time.monotonic()
        rows = self.db.execute('SELECT path,bytes FROM search ORDER BY attempted,path LIMIT 200').fetchall()
        if not rows:
            return
        with self.db:
            self.db.executemany('UPDATE search SET attempted=? WHERE path=?', [(time.time(), p) for p, _ in rows])
        try:
            got = self.post('/db/landed.php', {'files': '\n'.join(f'{p}\t{b}' for p, b in rows)})
            # Only acknowledged paths leave the outbox. A timeout or temporarily
            # unavailable mount retries safely, including after a helper restart.
            accepted = set(got.get('accepted', []))
            self.search_delay = 10 if accepted else min(300, self.search_delay * 2)
            with self.db:
                self.db.executemany('DELETE FROM search WHERE path=?', [(p,) for p, _ in rows if p in accepted])
        except Exception:
            self.search_delay = min(300, self.search_delay * 2)

    def complete_file(self, job, source, path, size, kind):
        with self.db:
            self.db.execute('''INSERT INTO completed VALUES (?,?,?,?,?)
                ON CONFLICT(job,source,path) DO UPDATE SET bytes=excluded.bytes''',
                (job, source, path, size, kind))

    def counts(self, job, source):
        rows = self.db.execute('SELECT kind,COUNT(*),SUM(bytes) FROM completed WHERE job=? AND source=? GROUP BY kind', (job, source)).fetchall()
        kinds = {k: (n, b) for k, n, b in rows}
        return dict(done_files=sum(n for _, n, _ in rows), done_bytes=sum(b for _, _, b in rows),
                    copied_bytes=kinds.get('copied', (0, 0))[1], already_bytes=kinds.get('already', (0, 0))[1])

    def report(self, job, source, phase, force=False, **counts):
        if not job or (not force and time.monotonic() - self.last_report < self.report_delay):
            return
        self.last_report = time.monotonic()
        try:
            self.post('/db/transfer.php', dict(job=job, source=source, phase=phase, **counts))
            self.report_delay = 10
        except Exception:
            self.report_delay = min(120, self.report_delay * 2)

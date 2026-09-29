"""Local durable checkpoints and a retryable search outbox; no mounted drive needed."""
import json
import sqlite3
import time
import urllib.parse
import urllib.request


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

"""meaning.py — Search by what a search means, not only by its words.

"abuelos", "grandparents" and "old people" find the same shots; "old building"
does not find them. Each moment Search knows (a shot's description and themes, a
line of speech) becomes a meaning fingerprint: 384 numbers from a small
multilingual model (paraphrase-multilingual-MiniLM-L12-v2: English, Spanish and
about fifty more). A search becomes one too, and the closest moments come back.

Runs in the AI's own Python (onnxruntime, tokenizers, numpy: Manage → Describe →
Install meaning search), on the Mac that runs Rushes, started and watched by
runner.py. Listens on this Mac only (127.0.0.1). Reads the catalogue, never writes
it; its fingerprints are kept in a file of their own (--store), by the words they
came from, so the same words are never worked out twice, even after search is
rebuilt. Ends when the process that started it ends.

  GET /state                      {"ready", "moments", "understood", "model"}
  GET /search?q=…&k=400&min=0.3   [[fp, kind, start_s, score], …] best first

python meaning.py --model DIR --db rushes.sqlite --store meaning.sqlite --port 18652
python meaning.py --check DIR      the model answers, and close meanings score close
"""
import argparse, hashlib, json, os, sqlite3, sys, threading, time, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import numpy as np

DIMS = 384


class Model:
    def __init__(self, folder):
        import onnxruntime as ort
        from tokenizers import Tokenizer
        self.tok = Tokenizer.from_file(os.path.join(folder, "tokenizer.json"))
        self.tok.enable_truncation(128)               # a description is a few sentences: 128 pieces of words is plenty
        self.tok.enable_padding()
        o = ort.SessionOptions()
        o.intra_op_num_threads = 2                     # beside describing and copying, never instead of them
        self.s = ort.InferenceSession(os.path.join(folder, "model.onnx"), o, providers=["CPUExecutionProvider"])
        self.inputs = {i.name for i in self.s.get_inputs()}
        self.name = os.path.basename(os.path.normpath(folder))

    def __call__(self, texts):
        """Texts -> one row of 384 numbers each, of length 1 (so a dot product is the cosine)."""
        e = self.tok.encode_batch(texts)
        ids = np.array([x.ids for x in e], dtype=np.int64)
        mask = np.array([x.attention_mask for x in e], dtype=np.int64)
        feed = {"input_ids": ids, "attention_mask": mask}
        if "token_type_ids" in self.inputs:
            feed["token_type_ids"] = np.zeros_like(ids)
        h = self.s.run(None, feed)[0]
        v = (h * mask[..., None]).sum(1) / np.maximum(mask.sum(1, keepdims=True), 1)     # the mean of the words
        return (v / np.maximum(np.linalg.norm(v, axis=1, keepdims=True), 1e-9)).astype(np.float32)


def text_of(kind, what, themes, tags):
    """What a moment means, in words: a shot is its description and what it is about
    (not the text on screen, which is often a stray word); speech is what was said."""
    if kind == "speech":
        return (what or "").strip()
    return ". ".join(x.replace(" · ", ", ") for x in (what, themes, tags) if x and x.strip()).strip()


class Index:
    """The moments, each with its fingerprint. Rebuilt in the background when the
    catalogue changes; a search always uses a whole one (never one half-made)."""

    def __init__(self, model, db, store):
        self.model, self.db = model, db
        self.st = sqlite3.connect(store, check_same_thread=False)
        self.st.execute("CREATE TABLE IF NOT EXISTS v (h TEXT PRIMARY KEY, v BLOB NOT NULL)")
        self.keys, self.m = [], np.zeros((0, DIMS), np.float32)
        self.state = {"ready": False, "moments": 0, "understood": 0, "model": model.name}
        self.sig = None

    def moments(self):
        c = sqlite3.connect(f"file:{self.db}?mode=ro", uri=True, timeout=30)
        try:
            sig = c.execute("SELECT COUNT(*), MAX(rowid) FROM moments").fetchone()
            if sig == self.sig:
                return sig, None
            rows = c.execute("SELECT fp, kind, start_s, what, themes, tags FROM moments "
                             "WHERE kind IN ('shot', 'speech') AND what != ''").fetchall()
            return sig, rows
        except sqlite3.OperationalError:              # no moments table yet: nothing described
            return None, []
        finally:
            c.close()

    def refresh(self):
        sig, rows = self.moments()
        if rows is None:
            return
        texts = [text_of(k, w, t, g) for _, k, _, w, t, g in rows]
        hs = [hashlib.sha1(t.encode()).hexdigest()[:20] for t in texts]
        have = {}
        for i in range(0, len(hs), 900):              # SQLite takes up to 999 values at a time
            part = list(set(hs[i:i + 900]))
            q = "SELECT h, v FROM v WHERE h IN (%s)" % ",".join("?" * len(part))
            have.update(self.st.execute(q, part).fetchall())
        todo = sorted({h: t for h, t in zip(hs, texts) if h not in have and t}.items())
        self.state.update(moments=len(rows), understood=len(rows) - sum(1 for h in hs if h not in have))
        for i in range(0, len(todo), 32):             # a batch at a time, said as it goes (Manage → Describe)
            batch = todo[i:i + 32]
            vs = self.model([t for _, t in batch])
            self.st.executemany("INSERT OR REPLACE INTO v VALUES (?, ?)",
                                [(h, v.astype(np.float16).tobytes()) for (h, _), v in zip(batch, vs)])
            self.st.commit()
            have.update((h, v.astype(np.float16).tobytes()) for (h, _), v in zip(batch, vs))
            self.state["understood"] = len(rows) - sum(1 for h in hs if h not in have)
        # ponytail: every fingerprint in memory as float32, 1.5 KB a moment (150 MB at 100,000);
        # past a few hundred thousand, keep float16 and search in parts.
        keep = [i for i, h in enumerate(hs) if h in have]
        m = np.frombuffer(b"".join(have[hs[i]] for i in keep), dtype=np.float16).reshape(-1, DIMS).astype(np.float32) \
            if keep else np.zeros((0, DIMS), np.float32)
        self.keys, self.m = [(rows[i][0], rows[i][1], rows[i][2]) for i in keep], m
        self.sig = sig
        self.state.update(ready=True, understood=len(keep))

    def search(self, q, k=400, floor=0.3):
        keys, m = self.keys, self.m                   # one whole index, even if a new one lands meanwhile
        if not q.strip() or not len(keys):
            return []
        s = m @ self.model([q])[0]
        top = np.argsort(-s)[:k]
        return [[*keys[i], round(float(s[i]), 4)] for i in top if s[i] >= floor]


def serve(idx, port):
    class H(BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def do_GET(self):
            u = urllib.parse.urlsplit(self.path)
            a = dict(urllib.parse.parse_qsl(u.query))
            try:
                if u.path == "/state":
                    out = idx.state
                elif u.path == "/search":
                    out = idx.search(a.get("q", ""), int(a.get("k", 400)), float(a.get("min", 0.3)))
                else:
                    self.send_error(404); return
            except Exception as e:                    # said to Search, which then finds by words alone
                out, code = {"error": str(e)}, 500
            else:
                code = 200
            b = json.dumps(out).encode()
            self.send_response(code)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(b)))
            self.end_headers()
            self.wfile.write(b)

    ThreadingHTTPServer(("127.0.0.1", port), H).serve_forever()


def check(folder):
    """The model loads and means what it should: assert-based, run by tests and by Install."""
    m = Model(folder)
    d = m(["An elderly man in a red shirt sits in the back of a car beside a little girl.",
           "Black and white photograph of an old brick building on a city street.",
           "A drone shot over a park with palm trees and a lake."])
    assert d.shape == (3, DIMS)
    for q, want in (("abuelos", 0), ("old building", 1), ("toma con dron", 2), ("grandparents", 0)):
        s = d @ m([q])[0]
        assert int(np.argmax(s)) == want, (q, s.round(2).tolist())
    print("meaning search: the model answers, and close meanings score close ✓")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model"); ap.add_argument("--db"); ap.add_argument("--store")
    ap.add_argument("--port", type=int, default=18652); ap.add_argument("--check")
    a = ap.parse_args()
    if a.check:
        check(a.check); return
    parent = os.getppid()
    idx = Index(Model(a.model), a.db, a.store)
    threading.Thread(target=serve, args=(idx, a.port), daemon=True).start()
    print(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  meaning search on 127.0.0.1:{a.port} ({idx.model.name})", flush=True)
    while os.getppid() == parent:                     # Rushes stopped: so does this
        try:
            idx.refresh()
        except Exception as e:                        # said, then tried again: never stops answering searches
            print(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  ! {e}", flush=True)
        time.sleep(15)


if __name__ == "__main__":
    main()

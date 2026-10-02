"""Run with: python3 -m unittest discover -s tests -v. No NAS or network used."""
import importlib.util
import io, threading, time
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

APP = Path(__file__).resolve().parents[1] / 'app'
sys.path.insert(0, str(APP))
from transfer_state import TransferState


class OutboxTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name) / 'state.sqlite'
        self.cp = TransferState(self.path, 'http://test')

    def tearDown(self):
        self.cp.db.close()
        self.tmp.cleanup()

    def test_network_failure_survives_restart_and_ack_is_idempotent(self):
        self.cp.landed('/archive/a.mov', 30)
        with patch.object(self.cp, 'post', side_effect=OSError('offline')):
            self.cp.flush(force=True)
        self.cp.db.close()
        self.cp = TransferState(self.path, 'http://test')
        self.assertEqual(self.cp.db.execute('SELECT COUNT(*) FROM search').fetchone()[0], 1)
        with patch.object(self.cp, 'post', return_value={'accepted': ['/archive/a.mov']}):
            self.cp.flush(force=True)
        self.assertEqual(self.cp.db.execute('SELECT COUNT(*) FROM search').fetchone()[0], 0)
        self.assertEqual(self.cp.db.execute('SELECT path FROM arrivals').fetchone()[0], '/archive/a.mov')

    def test_refused_files_do_not_starve_later_batches(self):
        for i in range(201): self.cp.landed(f'/archive/{i:03}.mov', i)
        with patch.object(self.cp, 'post', return_value={'accepted': []}): self.cp.flush(force=True)
        with patch.object(self.cp, 'post', return_value={'accepted': []}) as post:
            self.cp.flush(force=True)
            self.assertTrue(post.call_args.args[1]['files'].startswith('/archive/200.mov'))

    def test_restart_does_not_count_copied_files_twice_or_relabel_them(self):
        self.cp.complete_file('job', '/source', '/source/a', 80, 'copied')
        self.cp.complete_file('job', '/source', '/source/a', 80, 'already')
        self.cp.complete_file('job', '/source', '/source/b', 20, 'already')
        self.assertEqual(self.cp.counts('job', '/source'),
                         dict(done_files=2, done_bytes=100, copied_bytes=80, already_bytes=20))
        self.assertEqual(self.cp.counts('another-job', '/source')['done_bytes'], 0)


class CopyTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.source = self.root / 'source'; self.source.mkdir()
        self.archive = self.root / 'archive'; self.archive.mkdir()
        self.dest = self.archive / 'shoot'
        spec = importlib.util.spec_from_file_location('rushes_ingest_test', APP / 'ingest.py')
        self.mod = importlib.util.module_from_spec(spec)
        with patch('urllib.request.urlopen', side_effect=OSError('offline')): spec.loader.exec_module(self.mod)
        m = self.mod
        m.HOME = self.root / 'helper'; m.HOME.mkdir()
        for key, name in [('PLAN','plan.tsv'),('LOG','log.tsv'),('DONE','done.txt'),('CACHE','cache.json')]:
            setattr(m, key, m.HOME / name)
        m.STATUS = self.archive / '_rushes'
        m.ORIGIN = m.STATUS / 'origin'
        m.NAS_MOUNT = str(self.archive)
        m.SETTINGS = {'archive': {'local': str(self.archive)}}
        m._CHECKPOINTS = TransferState(m.HOME / 'state.sqlite', 'http://test')
        self.reports = []
        def post(endpoint, data):
            if endpoint.endswith('transfer.php'): self.reports.append(data.copy())
            return {'accepted': [line.split('\t')[0] for line in data.get('files','').splitlines()]}
        m._CHECKPOINTS.post = post

    def tearDown(self):
        self.mod._CHECKPOINTS.db.close()
        self.tmp.cleanup()

    def run_copy(self):
        args = ['ingest.py','--source',str(self.source),'--into',str(self.dest),'--apply','--job','test-job']
        with patch.object(sys, 'argv', args), patch('sys.stdout', new_callable=io.StringIO):
            try: self.mod.main()
            except SystemExit as e: return e.code      # a stopped folder exits non-zero
        return 0

    def test_a_file_that_fails_once_is_tried_again_and_lands(self):
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        (self.source / 'b.mov').write_bytes(b'b' * 20)
        rename = os.rename
        calls = [0]
        def hiccup(src, dst):
            calls[0] += 1
            if calls[0] == 2: raise OSError('network hiccup')
            return rename(src, dst)
        with patch.object(self.mod.os, 'rename', side_effect=hiccup), patch.object(self.mod.time, 'sleep'):
            self.assertEqual(self.run_copy(), 0)
        self.assertEqual(self.reports[-1]['phase'], 'done')
        self.assertEqual(self.reports[-1]['failed'], 0)
        self.assertEqual(self.reports[-1]['done_bytes'], 30)
        self.assertEqual(self.reports[-1]['copied_bytes'], 30)
        self.assertEqual((self.dest / 'b.mov').read_bytes(), b'b' * 20)
        self.assertEqual(self.mod._CHECKPOINTS.db.execute('SELECT COUNT(*) FROM search').fetchone()[0], 0)

    def test_every_copy_is_verified_and_its_fingerprint_kept(self):
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        real = self.mod.read_back
        bad = [True]
        def once_bad(path):                            # the first copy comes back damaged
            if bad[0]:
                bad[0] = False; return 'damaged'
            return real(path)
        with patch.object(self.mod, 'read_back', side_effect=once_bad), patch.object(self.mod.time, 'sleep'):
            self.assertEqual(self.run_copy(), 0)
        self.assertEqual((self.dest / 'a.mov').read_bytes(), b'a' * 10)
        self.assertFalse((self.dest / 'a.mov.part').exists())
        h, algo = self.mod.new_fingerprint(); h.update(b'a' * 10); want = f'{algo} {h.hexdigest()}'
        record = ''.join(p.read_text() for p in (self.archive / '_rushes' / 'origin').glob('*.tsv'))
        self.assertIn(f'verified {want}', record)

    def test_an_original_that_changes_while_copied_is_not_kept(self):
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        grow = [True]
        real = self.mod.shutil.copystat
        def still_writing(s, d):
            if grow[0]:                                # the camera writes more, mid-copy (first try only)
                grow[0] = False
                with open(s, 'ab') as f: f.write(b'more')
                os.utime(s, ns=(1, 1))
            return real(s, d)
        with patch.object(self.mod.shutil, 'copystat', side_effect=still_writing), patch.object(self.mod.time, 'sleep'):
            self.run_copy()
        # Neither version is kept this run, nothing half-copied is left, and the
        # record says why; the next run lists it at its new size and copies it.
        self.assertFalse((self.dest / 'a.mov.part').exists())
        self.assertFalse((self.dest / 'a.mov').exists())
        record = ''.join(p.read_text() for p in (self.archive / '_rushes' / 'origin').glob('*.tsv'))
        self.assertIn('the original has changed since it was listed', record)

    def test_a_full_archive_stops_once_and_leaves_no_half_file(self):
        import errno
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        (self.source / 'b.mov').write_bytes(b'b' * 10)
        def full(fd): raise OSError(errno.ENOSPC, 'No space left on device')
        with patch.object(self.mod.os, 'fsync', side_effect=full), patch.object(self.mod.time, 'sleep'):
            self.assertNotEqual(self.run_copy(), 0)
        self.assertEqual(list(self.dest.glob('*.part')), [])
        self.assertEqual(self.reports[-1]['failed'], 0)          # not written down as failed files: it stopped

    def test_an_accented_name_already_there_in_the_other_form_is_not_copied_again(self):
        import unicodedata
        nfc, nfd = unicodedata.normalize('NFC', 'Día.mov'), unicodedata.normalize('NFD', 'Día.mov')
        (self.source / nfc).write_bytes(b'x' * 10)
        self.dest.mkdir(parents=True)
        (self.dest / nfd).write_bytes(b'x' * 10)                 # an earlier copy, stored the other way
        with patch.object(self.mod.time, 'sleep'):
            self.assertEqual(self.run_copy(), 0)
        self.assertEqual(sorted(p.name for p in self.dest.iterdir() if p.suffix == '.mov'), [nfd])

    def test_disconnection_stops_the_folder_and_it_resumes(self):
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        (self.source / 'b.mov').write_bytes(b'b' * 20)
        away = self.root / 'away'
        rename = os.rename
        calls = [0]
        def unplug(src, dst):
            calls[0] += 1
            if calls[0] == 2:
                rename(self.source, away)             # the share drops mid-copy
                raise OSError('network disconnected')
            return rename(src, dst)
        with patch.object(self.mod.os, 'rename', side_effect=unplug):
            self.assertEqual(self.run_copy(), 1)
        self.assertEqual(self.reports[-1]['phase'], 'interrupted')
        self.assertLess(self.reports[-1]['done_bytes'], 30)
        self.assertFalse(self.mod.DONE.exists())
        os.rename(away, self.source)                   # it comes back
        self.assertEqual(self.run_copy(), 0)
        self.assertEqual(self.reports[-1]['phase'], 'done')
        self.assertEqual(self.reports[-1]['done_bytes'], 30)
        self.assertEqual((self.dest / 'b.mov').read_bytes(), b'b' * 20)

    def test_same_name_different_content_is_not_completed_or_overwritten(self):
        (self.source / 'a.mov').write_bytes(b'new')
        self.dest.mkdir(); (self.dest / 'a.mov').write_bytes(b'old')
        with patch.object(self.mod.time, 'sleep'): self.run_copy()
        # written down as a failure, never counted as done, never overwritten
        self.assertEqual(self.reports[-1]['failed'], 1)
        self.assertEqual(self.reports[-1]['done_bytes'], 0)
        self.assertEqual((self.dest / 'a.mov').read_bytes(), b'old')

    def test_missing_source_never_becomes_a_completed_empty_job(self):
        self.source.rmdir()
        self.run_copy()
        self.assertEqual(self.reports[-1]['phase'], 'blocked')
        self.assertFalse(self.mod.DONE.exists())

    def test_an_unreadable_folder_is_recorded_not_skipped(self):
        def bad_walk(*args, **kwargs):
            kwargs['onerror'](OSError(13, 'Permission denied', str(self.source / 'locked')))
            return iter(())
        with patch.object(self.mod.os, 'walk', side_effect=bad_walk): self.run_copy()
        self.assertEqual(self.reports[-1]['failed'], 1)
        record = next(self.mod.ORIGIN.glob('*.tsv')).read_text()
        self.assertIn('failed\t' + str(self.source / 'locked'), record)

    def test_a_copy_aimed_outside_the_archive_is_refused(self):
        (self.source / 'a.mov').write_bytes(b'a')
        outside = self.root / 'elsewhere'
        args = ['ingest.py','--source',str(self.source),'--into',str(outside),'--apply']
        with patch.object(sys, 'argv', args), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(SystemExit) as e: self.mod.main()
        self.assertEqual(e.exception.code, 1)
        self.assertFalse(outside.exists())

    def test_pause_from_manage_stops_between_files_and_resumes(self):
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        (self.source / 'b.mov').write_bytes(b'b' * 20)
        state = {'paused': False}
        rename = os.rename
        def press_pause_after_first(src, dst):
            rename(src, dst); state['paused'] = True       # someone presses Pause mid-folder
        with patch.object(self.mod, 'control', side_effect=lambda: dict(state)), \
             patch.object(self.mod.os, 'rename', side_effect=press_pause_after_first):
            self.assertEqual(self.run_copy(), 1)
        self.assertEqual(self.reports[-1]['phase'], 'paused')
        self.assertEqual(len([p for p in self.dest.iterdir() if p.name != 'ascmhl']), 1)   # beside the proof of it
        self.assertFalse(self.mod.DONE.exists())
        with patch.object(self.mod, 'control', return_value={}):   # Resume
            self.assertEqual(self.run_copy(), 0)
        self.assertEqual(self.reports[-1]['phase'], 'done')
        self.assertEqual(self.reports[-1]['done_bytes'], 30)

    def test_a_second_helper_on_the_same_computer_refuses_to_start(self):
        self.mod.only_one()
        spec = importlib.util.spec_from_file_location('rushes_ingest_second', APP / 'ingest.py')
        other = importlib.util.module_from_spec(spec)
        with patch('urllib.request.urlopen', side_effect=OSError('offline')): spec.loader.exec_module(other)
        other.HOME = self.mod.HOME
        with self.assertRaises(SystemExit): other.only_one()

    def test_unreadable_hashes_are_not_duplicate_matches(self):
        self.assertIsNone(self.mod.already_here('/missing/source', 10, {10: ['/missing/archive']}))


    def test_watcher_reconnect_keeps_archive_destination(self):
        self.source.rmdir()
        m = self.mod
        job = {'id':'job', 'items':[{'source':str(self.source),'phase':'queued'}]}
        sleeps = [0]
        def sleep(_):
            sleeps[0] += 1
            if sleeps[0] == 1: self.source.mkdir()
            else: raise StopIteration()
        with patch.object(m, '_remote_json', return_value=job), \
             patch.object(m.urllib.request, 'urlopen', side_effect=lambda *a, **k: io.BytesIO(f'copy\t{self.source}\n'.encode())), \
             patch.object(m.threading.Thread, 'start'), patch.object(m.time, 'sleep', side_effect=sleep), \
             patch.object(m, 'wait', side_effect=sleep), patch.object(m, 'update_self'), \
             patch.object(m, 'control', return_value={}), patch.object(m, 'within', side_effect=lambda k, t, fn: fn()), \
             patch.object(m, 'free_bytes', return_value=10**15), patch.object(m, 'needs_trace', return_value=False), \
             patch.object(m, 'run_self', return_value=0) as run, patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(StopIteration): m.watch(str(self.archive))
        args = run.call_args.args
        self.assertEqual(args[args.index('--root')+1], str(self.archive))


class TraceResumeTests(unittest.TestCase):
    """Matching earlier copies keeps the originals it has listed, folder by folder."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown
    def test_a_stop_part_way_reads_only_the_folders_not_yet_read(self):
        m = self.mod
        src = self.root / 'server'
        for name in ('A', 'B'):
            (src / name).mkdir(parents=True)
            (src / name / f'{name}.mov').write_bytes(name.encode() * 50)
        (self.archive / '2020').mkdir()
        (self.archive / '2020' / 'old.mov').write_bytes(b'A' * 50)          # an earlier copy of A.mov
        m.SETTINGS = {'archive': {'local': str(self.archive)}, 'sources': [{'label': 'server', 'path': str(src)}]}
        m.STATUS.mkdir(parents=True, exist_ok=True)
        (m.STATUS / 'ingest-sections.tsv').write_text(f"section\t{src}/A\t1\t50\nsection\t{src}/B\t1\t50\n")
        walked = []
        real = m.walk
        def spy(path, everything=False):
            walked.append(os.path.basename(path)); return real(path, everything)
        with patch.object(m, 'walk', spy), patch('sys.stdout', new_callable=io.StringIO):
            m.trace(str(src), str(self.archive))
        self.assertEqual(sorted(w for w in walked if w in 'AB'), ['A', 'B'])
        rec = next(m.ORIGIN.glob('* traced.tsv')).read_text()
        self.assertIn(f'traced\t{src}/A/A.mov', rec)
        # B was cut off part-way: its "# read" line never made it
        saved = next(m.HOME.glob('trace-originals-*.tsv'))
        saved.write_text(saved.read_text().replace(f'# read\t{src}/B\n', ''))
        walked.clear()
        with patch.object(m, 'walk', spy), patch('sys.stdout', new_callable=io.StringIO):
            m.trace(str(src), str(self.archive))
        self.assertEqual([w for w in walked if w in 'AB'], ['B'])        # A is not read again
        self.assertEqual(saved.read_text().count('B.mov'), 1)            # and B's half-list was not kept twice

    def test_after_a_pause_the_copies_already_matched_are_not_checked_again(self):
        m = self.mod
        src = self.root / 'server'; (src / 'A').mkdir(parents=True)
        for n in ('1', '2'): (src / 'A' / f'{n}.mov').write_bytes(n.encode() * 50)
        (self.archive / '2020').mkdir()
        for n in ('1', '2'): (self.archive / '2020' / f'old{n}.mov').write_bytes(n.encode() * 50)
        m.SETTINGS = {'archive': {'local': str(self.archive)}, 'sources': [{'label': 'server', 'path': str(src)}]}
        m.STATUS.mkdir(parents=True, exist_ok=True)
        (m.STATUS / 'ingest-sections.tsv').write_text(f"section\t{src}/A\t2\t100\n")
        checked = []
        real = m.digest
        def dig(path, full=False):
            if '/2020/' in str(path): checked.append(os.path.basename(path))
            return real(path, full)
        # the first run is stopped (Pause, a drop) right after its first match
        real_add = m.Origin.add
        def add_then_stop(self, *r):
            real_add(self, *r); self.f.flush(); raise SystemExit(1)
        with patch.object(m, 'digest', dig), patch.object(m.Origin, 'add', add_then_stop), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(SystemExit): m.trace(str(src), str(self.archive))
        self.assertTrue(m.needs_trace(str(src), str(self.archive)))      # not finished: still needed
        first = list(checked); checked.clear()
        with patch.object(m, 'digest', dig), patch('sys.stdout', new_callable=io.StringIO):
            m.trace(str(src), str(self.archive))
        self.assertEqual(set(first) | set(checked), {'old1.mov', 'old2.mov'})   # both checked, each in one run only
        self.assertFalse(set(first) & set(checked))
        self.assertFalse(m.needs_trace(str(src), str(self.archive)))     # and now it counts as done


class ReconnectTests(unittest.TestCase):
    """A share that drops is connected again by the helper, and the drop is recorded."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_a_dropped_share_reconnects_by_itself_and_is_recorded(self):
        m = self.mod
        share = self.root / 'Volumes' / 'server'
        m.SHARES = m.HOME / 'shares.json'
        m.SHARES.write_text(json.dumps({str(share): '//fileserver/server'}))
        d = m.Dropped(str(share / 'Departments'))
        self.assertEqual((d.root, d.srv), (str(share), '//fileserver/server'))
        calls = []
        def fake(root, srv):
            calls.append(srv)
            if len(calls) == 2: os.makedirs(root)          # the second try works
            return os.path.isdir(root)
        with patch.object(m, 'reconnect', side_effect=fake), patch('sys.stdout', new_callable=io.StringIO):
            self.assertFalse(d.try_again())
            self.assertIn('reconnecting by itself (try 1)', d.note())
            self.assertFalse(d.try_again())                  # too soon: waits 2 minutes between tries
            d.next = 0
            self.assertTrue(d.try_again())
            d.back()
        self.assertEqual(calls, ['//fileserver/server'] * 2)
        hist = (m.STATUS / 'ingest-history.tsv').read_text()
        self.assertIn(f'dropped\t{share}\t0\t0', hist)
        self.assertIn('reconnected by itself', hist)

    def test_a_share_it_never_saw_mounted_asks_for_finder(self):
        self.mod.SHARES = self.mod.HOME / 'shares.json'
        d = self.mod.Dropped('/Volumes/unknown')
        with patch('sys.stdout', new_callable=io.StringIO):
            self.assertFalse(d.try_again())
        self.assertIn('connect it again in Finder', d.note())


class DescribeTests(unittest.TestCase):
    """Describing a folder: the analysis tools run, progress reaches the page, the result is recorded."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_progress_lines_become_status_and_the_run_is_recorded(self):
        m = self.mod
        stub = self.root / 'fake-python'
        stub.write_text('#!/bin/sh\necho "loading"\n'
                        'echo \'@@ {"file": "a.mov", "n": 1, "of": 2, "shot": 3, "shots": 9, "per_shot": 5.9}\'\n'
                        'echo \'@@ {"finished": true, "done": 1, "already": 1, "failed": 0}\'\n')
        stub.chmod(0o755)
        m.SETTINGS['analysis'] = {'python': str(stub), 'model': 'm', 'whisper': ''}
        seen = []
        with patch.object(m, 'status', side_effect=lambda **kw: seen.append(kw)), \
             patch.object(m, 'control', return_value={}), patch('sys.stdout', new_callable=io.StringIO):
            self.assertEqual(m.describe_folder(str(self.archive)), 0)
        live = [s for s in seen if s.get('shot')]
        self.assertEqual((live[0]['n'], live[0]['of'], live[0]['shot'], live[0]['shots']), (1, 2, 3, 9))
        self.assertIn(f'analysed\t{self.archive}\t2\t0', (m.STATUS / 'ingest-history.tsv').read_text())

    def test_no_tools_on_this_computer_says_so(self):
        m = self.mod
        m.SETTINGS['analysis'] = {'python': str(self.root / 'nowhere')}
        seen = []
        with patch.object(m, 'status', side_effect=lambda **kw: seen.append(kw)), patch.object(m, 'wait'), \
             patch('sys.stdout', new_callable=io.StringIO):
            self.assertEqual(m.describe_folder(str(self.archive)), 2)
        self.assertIn('not installed', seen[-1]['note'])


class QuietReconnectTests(unittest.TestCase):
    """A server that does not answer is not asked to mount: no macOS error window every two minutes."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_server_not_answering_means_no_mount_attempt(self):
        m = self.mod
        self.assertFalse(m.reachable("//someone@127.0.0.1/share", timeout=1))
        with patch.object(m.sys, "platform", "darwin"), patch.object(m.subprocess, "run") as run:
            self.assertFalse(m.reconnect(str(self.root / "gone"), "//someone@127.0.0.1/share"))
        run.assert_not_called()


class ProxyFollowsTests(unittest.TestCase):
    """A tidy-up moves each original's proxy with it, so the two stay linked."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_proxy_moves_with_its_original_and_never_over_another(self):
        m = self.mod
        a, b = self.archive / 'ARCHIVE' / 'x' / 'clip.MXF', self.archive / 'Parks' / '2024' / 'clip.MXF'
        pa = self.archive / 'PROXIES' / 'ARCHIVE' / 'x' / 'clip.mp4'
        pb = self.archive / 'PROXIES' / 'Parks' / '2024' / 'clip.mp4'
        pa.parent.mkdir(parents=True); pa.write_text('proxy')
        seen = []
        o = type('O', (), {'add': lambda self, *r: seen.append(r)})()
        m.move_proxy(str(a), str(b), o)
        self.assertTrue(pb.is_file()); self.assertFalse(pa.exists())
        self.assertEqual(seen[0][0], 'proxy moved')
        pa.write_text('another'); m.move_proxy(str(a), str(b), o)       # something already there: left alone
        self.assertEqual(pb.read_text(), 'proxy'); self.assertEqual(pa.read_text(), 'another')


class TidyPauseTests(unittest.TestCase):
    """A tidy-up stops at the next file for Pause, keeps what it moved, and the
    rest follows when work resumes."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_pause_stops_it_part_way_and_it_carries_on_later(self):
        m = self.mod
        m.ARCHIVE = str(self.archive / 'ARCHIVE')
        m.SETTINGS = dict(m.SETTINGS, organise={'shelves': 'Library'})
        srcs = []
        for n in range(4):
            p = self.archive / 'ARCHIVE' / 'old' / 'Kite' / f'A00{n}.MXF'
            p.parent.mkdir(parents=True, exist_ok=True); p.write_text('x'); srcs.append(p)
        rows = {str(p): [('copied', f'/src/Kite/{p.name}', '1')] for p in srcs}
        plan = f"map\t/src\t{self.archive / 'Library' / 'PARKS'}\n"
        asked = [0]
        def control():
            asked[0] += 1
            return {'paused': asked[0] > 2}                    # paused after two files
        common = dict(_fetch=lambda u: plan, fetch_queue=lambda *a: '', control=control, tell_moved=lambda *a: None,
                      history=lambda *a: None, status=lambda **k: None, mhl_follow=lambda *a: None)
        with patch.multiple(m, replay=lambda: rows, **common), patch('sys.stdout', new_callable=io.StringIO) as out:
            m.tidy('t1')
        moved = sorted(p.name for p in (self.archive / 'Library' / 'PARKS' / 'Kite').glob('*.MXF'))
        self.assertEqual(moved, ['A000.MXF', 'A001.MXF'])
        self.assertIn('stopped part-way (paused)', out.getvalue())
        self.assertNotIn('tidy t1', m.DONE.read_text().splitlines())   # asked again when work resumes
        left = {k: v for k, v in rows.items() if os.path.exists(k)}
        common['control'] = lambda: {}
        with patch.multiple(m, replay=lambda: left, **common), patch('sys.stdout', new_callable=io.StringIO):
            m.tidy('t1')
        self.assertEqual(len(list((self.archive / 'Library' / 'PARKS' / 'Kite').glob('*.MXF'))), 4)
        self.assertIn('tidy t1', m.DONE.read_text().splitlines())


class SignedUpdateTests(unittest.TestCase):
    """The helper takes a new version of its code only as a signed release,
    checked with the release.py it already has."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def _serve(self, files, sig):
        import hashlib
        want = {n: hashlib.sha256(d).hexdigest() for n, d in files.items()}
        def urlopen(url, *a, **k):
            if url.endswith('?hash'): return io.BytesIO(json.dumps(want).encode())
            if url.endswith('?code=release.sig'): return io.BytesIO(sig.encode())
            return io.BytesIO(files[url.split('?code=')[1]])
        return urlopen

    def test_only_a_signed_release_is_taken(self):
        import hashlib
        m = self.mod
        spec = importlib.util.spec_from_file_location('rel_t', APP / 'release.py')
        rel = importlib.util.module_from_spec(spec); spec.loader.exec_module(rel)
        seed = bytes(range(32)); pub = rel.public_key(seed).hex()
        here = self.root / 'code'; here.mkdir()
        relsrc = (APP / 'release.py').read_text().replace(rel.PUBLIC, pub)
        old = {'ingest.py': b'print(1)\n', 'transfer_state.py': b'x = 1\n', 'analyze.py': b'y = 1\n', 'release.py': relsrc.encode()}
        for n, d in old.items(): (here / n).write_bytes(d)
        new = dict(old, **{'ingest.py': b'print(2)\n'})
        hashes = {n: hashlib.sha256(d).hexdigest() for n, d in new.items()}
        good = rel.body(hashes).decode() + 'sig ' + rel.sign(seed, rel.body(hashes)).hex() + '\n'
        bad = rel.body(hashes).decode() + 'sig ' + rel.sign(bytes(32), rel.body(hashes)).hex() + '\n'
        m.HERE_DIR = str(here)
        with patch.object(m.urllib.request, 'urlopen', side_effect=self._serve(new, bad)), \
             patch.object(m.os, 'execv') as execv, patch('sys.stdout', new_callable=io.StringIO) as out:
            m._checked[0] = 0; m.update_self()
        self.assertEqual((here / 'ingest.py').read_bytes(), b'print(1)\n')        # signed with another key: not taken
        self.assertIn('not a signed release', out.getvalue()); execv.assert_not_called()
        with patch.object(m.urllib.request, 'urlopen', side_effect=self._serve(new, good)), \
             patch.object(m.os, 'execv') as execv, patch('sys.stdout', new_callable=io.StringIO):
            m._checked[0] = 0; m.update_self()
        self.assertEqual((here / 'ingest.py').read_bytes(), b'print(2)\n')        # signed: taken, and restarted
        execv.assert_called_once()


class ProofTests(unittest.TestCase):
    """ASC MHL records beside the footage: written from the copy's own fingerprints,
    following tidy-up moves, and filled in and re-checked by the checker."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown
    run_copy = CopyTests.run_copy

    def mhl_ok(self, root):
        """The reference tool agrees, when it is installed here."""
        try:
            from click.testing import CliRunner
            from ascmhl.cli.ascmhl import mhltool_cli
        except ImportError:
            return
        if sys.version_info < (3, 11): return        # ascmhl itself needs 3.11 (datetime.UTC)
        # `ascmhl create` re-reads every file, checks it against the record
        # (chain included) and fails on any difference; on a copy, so the
        # record under test is not changed.
        import shutil
        twin = self.root / 'twin'; shutil.rmtree(twin, ignore_errors=True); shutil.copytree(root, twin)
        r = CliRunner().invoke(mhltool_cli, ['create', '-h', 'xxh128', str(twin)])
        self.assertEqual(r.exit_code, 0, r.output)

    def setUp2(self):
        if self.mod.new_fingerprint()[1] != 'xxh128': self.skipTest('xxhash not installed')

    def test_a_copy_writes_its_proof_and_a_second_run_adds_a_generation(self):
        self.setUp2(); m = self.mod
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        (self.source / 'sub').mkdir(); (self.source / 'sub' / 'b.mov').write_bytes(b'b' * 20)
        self.assertEqual(self.run_copy(), 0)
        h = m.new_fingerprint()[0]; h.update(b'b' * 20)
        self.assertEqual(m.mhl_read(str(self.dest))['sub/b.mov'][0], h.hexdigest())
        self.assertIn('shoot', (m.STATUS / 'proof-roots.txt').read_text())
        self.mhl_ok(self.dest)
        (self.source / 'c.mov').write_bytes(b'c' * 5)
        self.assertEqual(self.run_copy(), 0)
        self.assertEqual(len(list((self.dest / 'ascmhl').glob('*.mhl'))), 2)
        self.assertEqual(len(m.mhl_read(str(self.dest))), 3)
        self.mhl_ok(self.dest)

    def test_fingerprints_follow_a_move_and_an_emptied_record_is_kept(self):
        self.setUp2(); m = self.mod
        (self.source / 'x').mkdir(); (self.source / 'x' / 'a.mov').write_bytes(b'a' * 10)
        self.assertEqual(self.run_copy(), 0)
        old, new = self.dest / 'x' / 'a.mov', self.archive / 'Shelf' / '2024 Parks' / 'x' / 'a.mov'
        new.parent.mkdir(parents=True); os.rename(old, new)
        seen = []
        o = type('O', (), {'add': lambda self, *r: seen.append(r)})()
        m.mhl_follow([(str(old), str(new))], o, 'tidy-up 1')
        self.assertIn('x/a.mov', m.mhl_read(str(self.archive / 'Shelf' / '2024 Parks')))
        self.assertFalse((self.dest / 'ascmhl').exists())
        self.assertTrue(list((self.archive / '_rushes' / 'ascmhl-moved').rglob('*.mhl')))
        self.mhl_ok(self.archive / 'Shelf' / '2024 Parks')

    def post(self, url, data=None, timeout=None):
        from urllib.parse import parse_qs
        self.posted = getattr(self, 'posted', []) + [(url, parse_qs(data.decode()) if data else {})]
        return io.BytesIO(b'{}')

    def test_copies_are_counted_where_each_file_came_from(self):
        m = self.mod
        for n in ('kept.mov', 'gone.mov'): (self.source / n).write_bytes(b'k' * 5)
        (self.archive / 'old').mkdir()
        m.ORIGIN.mkdir(parents=True)
        (m.ORIGIN / '20200101-000000 old folder.tsv').write_text(''.join(
            f'copied\t{self.source / n}\t{self.archive / "old" / n}\t5\t\n' for n in ('kept.mov', 'gone.mov')))
        (self.source / 'gone.mov').unlink()                       # the old server was cleared of this one
        m.SETTINGS = dict(m.SETTINGS, sources=[{'path': str(self.source), 'label': 'oldserver'}])
        with patch.object(m, 'control', return_value={}), patch.object(m, '_push'), patch('sys.stdout', new_callable=io.StringIO), \
             patch('urllib.request.urlopen', side_effect=self.post), patch.object(m, 'new_fingerprint', return_value=(None, 'xxh128')), \
             patch.object(m, '_older_todo', return_value={}):
            m.check_some()
        sent = [q for u, q in self.posted if u.endswith('/db/copies.php')]
        self.assertEqual(sorted(sent[-1]['copies'][0].split('\n')),
                         [f'{self.archive}/old/gone.mov\toldserver\t0', f'{self.archive}/old/kept.mov\toldserver\t1'])
        self.assertEqual(sent[-1]['done'], ['1'])
        # the server not connected: nothing is said about it, so no false "gone"
        self.posted = []
        m.SETTINGS = dict(m.SETTINGS, sources=[{'path': str(self.root / 'unplugged'), 'label': 'elsewhere'}])
        pf = m.HOME / 'proof.json'; st = json.loads(pf.read_text()); st['copies'] = {}; pf.write_text(json.dumps(st))
        (m.ORIGIN / '20200101-000000 old folder.tsv').write_text(f'copied\t{self.root}/unplugged/x.mov\t{self.archive}/old/x.mov\t5\t\n')
        with patch.object(m, 'control', return_value={}), patch.object(m, '_push'), patch('sys.stdout', new_callable=io.StringIO), \
             patch('urllib.request.urlopen', side_effect=self.post), patch.object(m, 'new_fingerprint', return_value=(None, 'xxh128')), \
             patch.object(m, '_older_todo', return_value={}):
            m.check_some()
        self.assertEqual([q.get('copies', [''])[0] for u, q in self.posted if u.endswith('/db/copies.php')], [''])

    def test_the_checker_proves_older_copies_then_finds_damage(self):
        self.setUp2(); m = self.mod
        good, bad = self.source / 'good.mov', self.source / 'bad.mov'
        good.write_bytes(b'g' * 30); bad.write_bytes(b'b' * 30)
        (self.archive / 'old').mkdir()
        (self.archive / 'old' / 'good.mov').write_bytes(b'g' * 30)
        (self.archive / 'old' / 'bad.mov').write_bytes(b'x' * 30)          # same size, different bytes
        m.ORIGIN.mkdir(parents=True)
        (m.ORIGIN / '20200101-000000 old folder.tsv').write_text(''.join(
            f'copied\t{s}\t{self.archive / "old" / s.name}\t30\t\n' for s in (good, bad)))
        with patch.object(m, 'control', return_value={}), patch.object(m, '_push'), patch('sys.stdout', new_callable=io.StringIO), \
             patch('urllib.request.urlopen', side_effect=self.post):
            while m.check_some(): pass
        known = m.mhl_read(str(self.archive / 'old'))
        self.assertEqual(sorted(known), ['good.mov'])
        hist = (m.STATUS / 'ingest-history.tsv').read_text()
        self.assertIn('1 differ (bad.mov)', hist)
        self.assertIn('checked', hist)                                   # then the archive check ran over it
        # a disk damages the good one: the next check finds it
        (self.archive / 'old' / 'good.mov').write_bytes(b'G' * 30)
        pf = m.HOME / 'proof.json'; st = json.loads(pf.read_text()); st['checked'] = {}; pf.write_text(json.dumps(st))
        with patch.object(m, 'control', return_value={}), patch.object(m, '_push'), patch('sys.stdout', new_callable=io.StringIO), \
             patch('urllib.request.urlopen', side_effect=self.post):
            while m.check_some(): pass
        self.assertIn('1 differ from their fingerprint (good.mov)', (m.STATUS / 'ingest-history.tsv').read_text())


class DescribeLaneTests(unittest.TestCase):
    """Describing has its own lane: it runs the queue's describing jobs beside the copies,
    has its own pause, and reports its own status."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    class Stop(BaseException):
        pass

    def run_lane(self, control, sleeps=2):
        m = self.mod; ran = []
        def fake(path, asked=""):
            ran.append((path, asked)); m.status(phase="analysing", source=path); return 0
        n = [0]
        def sleep(_):
            n[0] += 1
            if n[0] >= sleeps: raise self.Stop()
        with patch.object(m, "describe_folder", side_effect=fake), patch.object(m, "control", return_value=control), \
             patch.object(m.time, "sleep", side_effect=sleep), patch("sys.stdout", new_callable=io.StringIO):
            try: m.describe_lane()
            except self.Stop: pass
        return ran

    def test_it_describes_the_queued_folder_once_and_says_so_in_its_own_status(self):
        m = self.mod
        m._describe_jobs[:] = [(str(self.archive), "123")]
        pushed = []
        with patch.object(m, "_push", side_effect=lambda text, lane="": pushed.append((lane, text))):
            self.assertEqual(self.run_lane({}, sleeps=3), [(str(self.archive), "123")])
        self.assertIn(f"analyze {self.archive} 123", m.DONE.read_text())
        self.assertTrue(pushed and all(lane == "describe" for lane, _ in pushed))      # its own lane
        self.assertIn("idle", pushed[-1][1])
        self.assertFalse((m.STATUS / "describe-status.tsv").exists())               # nothing on the share

    def test_its_pause_holds_it_and_says_so(self):
        m = self.mod
        m._describe_jobs[:] = [(str(self.archive), "")]
        pushed = []
        with patch.object(m, "_push", side_effect=lambda text, lane="": pushed.append(text)):
            m.status(phase="paused", note="describing paused")
        self.assertEqual(self.run_lane({"describe_paused": True}), [])
        # Paused is paused: nothing written on the archive share; Rushes is told over the network
        self.assertFalse((m.STATUS / "describe-status.tsv").exists())
        self.assertFalse((m.STATUS / "ingest-status.tsv").exists())
        self.assertIn("paused", pushed[0])


class StallTests(unittest.TestCase):
    """rule 1-4 of DEVELOPING.md in the helper: idle touches no share, a share that does
    not answer is walked away from and then stopped, a dead Rushes is asked less."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_a_stuck_question_is_walked_away_from_and_not_asked_again_until_it_returns(self):
        m = self.mod; gate = threading.Event(); calls = []
        def stuck(): calls.append(1); gate.wait(); return "ok"
        t0 = time.time()
        with self.assertRaises(m.Stalled): m.within("x", 0.2, stuck)
        self.assertLess(time.time() - t0, 2)
        with self.assertRaises(m.Stalled): m.within("x", 0.2, stuck)
        self.assertEqual(len(calls), 1)                       # never two at once
        gate.set(); m._asking["x"].join(1)
        self.assertEqual(m.within("x", 1, lambda: 7), 7)

    def test_a_read_that_stops_getting_data_is_said_then_stops_the_helper(self):
        m = self.mod
        m.fed("/VIDEO/A001.MXF"); self.assertEqual(m.watch_feeding(), "")          # data moving
        m._feeding[1] = time.time() - 130
        self.assertIn("A001.MXF — no data for 2 min", m.watch_feeding())          # said on the pages
        self.assertFalse(m._stopped_file().exists())
        m._feeding[1] = time.time() - 700
        with patch.object(m, 'control', return_value={'nudge': 5}), patch('sys.stdout', new_callable=io.StringIO):
            m.watch_feeding()
            self.assertIn("A001.MXF", m.stopped())                                # stopped until Try again
        m.fed(); self.assertEqual(m.watch_feeding(), "")
        try: m._stopped_file().unlink()
        except OSError: pass
        m._stalls[0] = 0

    def test_its_own_log_is_kept_under_5_mb(self):
        m = self.mod; log = self.root / 'helper.log'
        log.write_bytes(b'old line\n' * 700_000 + b'the newest line\n')
        with open(log, 'a') as out, patch.dict(os.environ, {'RUSHES_LOG': str(log)}), patch.object(m.sys, 'stdout', out):
            m.trim_own_log()
            print('written after', file=out); out.flush()
        data = log.read_bytes()
        self.assertLess(len(data), 1_100_000)
        self.assertTrue(data.startswith(b'(older lines trimmed'))
        self.assertTrue(data.endswith(b'the newest line\nwritten after\n'))

    def test_three_stalls_stop_it_until_try_again(self):
        m = self.mod
        with patch.object(m, 'control', return_value={'nudge': 5}), patch('sys.stdout', new_callable=io.StringIO):
            for _ in range(3): m.stall("the archive share")
            self.assertIn("stopped answering", m.stopped())
        with patch.object(m, 'control', return_value={'nudge': 6}), patch('sys.stdout', new_callable=io.StringIO):
            self.assertEqual(m.stopped(), "")                 # Try again now in Manage
        self.assertFalse(m._stopped_file().exists())
        with patch.object(m, 'control', return_value={'nudge': 6}), patch('sys.stdout', new_callable=io.StringIO):
            for _ in range(3): m.stall("x")
            m._stopped_file().unlink()                                # Try again in the Rushes Helper window
            self.assertEqual(m.stopped(), ""); self.assertEqual(m._stalls[0], 0)

    def test_idle_touches_no_share(self):
        m = self.mod; n = [0]
        def sleep(_):
            n[0] += 1
            if n[0] >= 3: raise StopIteration()
        with patch.object(m.urllib.request, 'urlopen', side_effect=lambda *a, **k: io.BytesIO(b'')), \
             patch.object(m.threading.Thread, 'start'), patch.object(m, 'within', side_effect=lambda k, t, fn: fn()), patch.object(m, 'wait', side_effect=sleep), \
             patch.object(m, 'update_self'), patch.object(m, 'control', return_value={}), \
             patch.object(m, 'check_due', return_value=False), patch.object(m, '_push'), \
             patch.object(m, 'server_of', side_effect=AssertionError('looked at the shares with nothing to do')), \
             patch.object(m, '_archive_here', side_effect=AssertionError('looked at the archive with nothing to do')), \
             patch.object(m, 'send_file'), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(StopIteration): m.watch(str(self.archive))

    def test_a_rushes_that_does_not_answer_is_asked_less_and_less(self):
        m = self.mod; m._control[:] = [0.0, {}, 0]; waits = []
        with patch.object(m.urllib.request, 'urlopen', side_effect=OSError('down')):
            for _ in range(5):
                t = time.time(); m.control(); waits.append(round(m._control[0] - t)); m._control[0] = 0
        self.assertEqual(waits, [20, 60, 300, 900, 900])
        self.assertTrue(m.rushes_down())


    def test_paused_or_stopped_no_share_is_remembered(self):
        m = self.mod; m._shares_seen[0] = 0
        with patch.object(m, 'server_of', side_effect=AssertionError('looked at a share while paused')):
            with patch.object(m, 'control', return_value={'paused': True}): m.remember_shares()
            m.HOME.mkdir(parents=True, exist_ok=True); m._stopped_file().write_text('0\nx\n')
            with patch.object(m, 'control', return_value={}): m.remember_shares()
        m._stopped_file().unlink()

    def test_network_shares_are_looked_at_rarely_and_never_for_cards(self):
        m = self.mod; base = Path(self.tmp.name) / 'vols'
        for v in ('CARD', 'SHARE'): (base / v / 'DCIM').mkdir(parents=True)
        looks = []
        real = m._look
        def look(r, card=True): looks.append(r); return real(r, card)
        m._netlook.clear()
        with patch.dict(os.environ, {'VOLUMES_DIR': str(base)}), patch.object(m, '_look', side_effect=look), \
             patch.object(m, '_network_mounts', return_value={str(base / 'SHARE')}):
            first = {v['name']: v['card'] for v in m.volumes()}
            m.volumes(); m.volumes()
        self.assertEqual(first, {'CARD': True, 'SHARE': False})
        self.assertEqual(looks.count(str(base / 'SHARE')), 1)       # once in ten minutes
        self.assertEqual(looks.count(str(base / 'CARD')), 3)        # a card shows within 20 s


class PairingTests(unittest.TestCase):
    """HOW-IT-WORKS.md → Pairing, the helper's side: the queue comes from the paired door."""
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown

    def test_the_queue_is_asked_at_the_paired_door_and_an_older_rushes_still_works(self):
        m = self.mod; asked = []
        def urlopen(url, *a, **k):
            asked.append(url)
            if url.endswith('?queue'): raise m.urllib.error.HTTPError(url, 400, 'nothing asked', {}, None)
            return io.BytesIO(b'copy\t/x\n')
        with patch.object(m.urllib.request, 'urlopen', side_effect=urlopen):
            self.assertEqual(m.fetch_queue(), 'copy\t/x\n')
        self.assertEqual([u.split('/')[-1] for u in asked], ['helper.php?queue', 'ingest-queue.tsv'])

    def test_a_helper_rushes_refuses_touches_nothing_and_asks_less(self):
        m = self.mod; waits = []
        def wait(s):
            waits.append(s)
            if len(waits) >= 3: raise StopIteration()
        def urlopen(url, *a, **k):
            raise m.urllib.error.HTTPError(url, 403, 'not the paired helper', {}, None)
        with patch.object(m.urllib.request, 'urlopen', side_effect=urlopen), patch.object(m.threading.Thread, 'start'), patch.object(m, 'within', side_effect=lambda k, t, fn: fn()), \
             patch.object(m, 'wait', side_effect=wait), patch.object(m, 'update_self'), patch.object(m, 'remember_shares'), \
             patch.object(m, 'control', return_value={}), patch.object(m, '_push'), patch.object(m, 'send_file'), \
             patch.object(m, '_archive_here', side_effect=AssertionError('touched the archive while refused')), \
             patch('sys.stdout', new_callable=io.StringIO) as out:
            with self.assertRaises(StopIteration): m.watch(str(self.archive))
        self.assertEqual(waits, [20, 60, 300])
        self.assertEqual(out.getvalue().count('paired with another helper'), 1)


class BadRecordTests(unittest.TestCase):
    setUp, tearDown = CopyTests.setUp, CopyTests.tearDown
    def test_a_damaged_chain_file_is_said_not_crashed_on(self):
        m = self.mod; d = Path(self.tmp.name) / 'rec' / 'ascmhl'; d.mkdir(parents=True)
        (d / 'ascmhl_chain.xml').write_text('<not closed')
        with self.assertRaises(ValueError):      # what callers catch, like any other bad record
            m.mhl_write(str(d.parent), [(str(d.parent / 'a.mov'), 1, 'ab', 'original', '')], 'x')


class AddressTests(unittest.TestCase):
    """The helper follows Rushes to a new address, and its saved progress goes with it."""
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        spec = importlib.util.spec_from_file_location('rushes_ingest_where', APP / 'ingest.py')
        self.m = importlib.util.module_from_spec(spec)
        with patch('urllib.request.urlopen', side_effect=OSError('offline')): spec.loader.exec_module(self.m)
        m = self.m
        m.HOME = Path(self.tmp.name); m.WHERE = m.HOME / 'where.json'
        m.NAS_URL = m.ARG_URL = 'http://169.254.1.1'

    def tearDown(self):
        self.tmp.cleanup()

    def key(self, url):
        import hashlib
        return self.m.HOME / f"transfer-{hashlib.sha256(url.encode()).hexdigest()[:16]}.sqlite"

    def test_stopped_answering_moves_to_the_name_and_keeps_progress(self):
        m = self.m
        m._save_where({'from': m.ARG_URL, 'known': ['http://169.254.1.1', 'http://nas.local']})
        self.key('http://169.254.1.1').write_text('progress')
        with patch.object(m, 'answers', side_effect=lambda u: u == 'http://nas.local'):
            self.assertEqual(m.rushes_elsewhere(), 'http://nas.local')
        class Restarted(Exception): pass
        with patch('os.execv', side_effect=Restarted), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(Restarted):
                m.move_to('http://nas.local', 'test')
        self.assertEqual(self.key('http://nas.local').read_text(), 'progress')
        self.assertEqual(m._where()['current'], 'http://nas.local')

    def test_memory_of_another_rushes_is_ignored(self):
        m = self.m
        m.WHERE.write_text(json.dumps({'from': 'http://other', 'current': 'http://elsewhere'}))
        self.assertNotIn('current', m._where())

    def test_a_new_address_in_setup_is_followed_only_if_it_answers(self):
        m = self.m
        class R(io.BytesIO):
            def __enter__(self): return self
            def __exit__(self, *a): pass
        where = lambda *a, **k: R(json.dumps({'url': 'http://nas.local', 'name': 'http://nas.local'}).encode())
        with patch('urllib.request.urlopen', side_effect=where), patch.object(m, 'answers', return_value=False), \
             patch.object(m, 'move_to') as moved:
            m.learn_where()
        moved.assert_not_called()
        self.assertIn('http://nas.local', m._where()['known'])
        m._learned[0] = 0
        with patch('urllib.request.urlopen', side_effect=where), patch.object(m, 'answers', return_value=True), \
             patch.object(m, 'move_to') as moved:
            m.learn_where()
        moved.assert_called_once()


class RunnerTests(unittest.TestCase):
    def test_idle_runner_requests_search_reconciliation_without_browser(self):
        import subprocess
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            web = root / 'web'; web.mkdir()
            archive = root / 'archive'; archive.mkdir()
            bins = root / 'bin'; bins.mkdir()
            curl = bins / 'curl'
            curl.write_text('#!/bin/sh\nprintf "%s\\n" "$@" > "$TEST_CALLS"\nprintf \'{"state":"current"}\\n\'\n')
            curl.chmod(0o755)
            runner = root / 'runner.sh'
            source = (APP / 'runner.sh').read_text()
            source = source.replace('/share/Web', str(web)).replace('/share/VIDEO', str(archive))
            source = source.replace('/tmp/.archive-runner.lock', str(root / 'lock'))
            runner.write_text(source)
            env = dict(os.environ, PATH=str(bins)+os.pathsep+os.environ['PATH'],
                       TEST_CALLS=str(root/'calls'), RUSHES_URL='http://fixture.invalid:8080')
            subprocess.run(['sh', str(runner)], env=env, check=True, capture_output=True)
            self.assertIn('http://fixture.invalid:8080/db/import.php', (root/'calls').read_text())


class ScriptUpdateTests(unittest.TestCase):
    def test_runner_installs_exactly_the_approved_scripts(self):
        import hashlib, subprocess
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            web = root / 'web'; (web / 'queue').mkdir(parents=True)
            archive = root / 'archive'; scripts = archive / '_rushes' / 'scripts'; scripts.mkdir(parents=True)
            bins = root / 'bin'; bins.mkdir()
            (bins / 'curl').write_text('#!/bin/sh\necho no\n'); (bins / 'curl').chmod(0o755)
            (bins / 'mount').write_text('#!/bin/sh\nexit 0\n'); (bins / 'mount').chmod(0o755)
            good = scripts / 'verify.sh'; good.write_text('#!/bin/sh\necho approved\n')
            bad = scripts / 'dedupe.sh'; bad.write_text('#!/bin/sh\necho approved\n')
            h = lambda f: hashlib.sha256(f.read_bytes()).hexdigest()
            (web / 'queue' / 'x.job').write_text(f'ACTION=update-scripts\nSCRIPT=verify.sh:{h(good)}\n'
                                                 f'SCRIPT=dedupe.sh:{h(bad)}\nSCRIPT=../evil.sh:{h(good)}\n')
            bad.write_text('#!/bin/sh\necho swapped after approval\n')          # changed after the yes
            source = (APP / 'runner.sh').read_text().replace('/share/Web', str(web)).replace('/share/VIDEO', str(archive))
            source = source.replace('/tmp/.archive-runner.lock', str(root / 'lock'))
            (root / 'runner.sh').write_text(source)
            env = dict(os.environ, PATH=str(bins) + os.pathsep + os.environ['PATH'])
            subprocess.run(['sh', str(root / 'runner.sh')], env=env, check=True, capture_output=True)
            self.assertEqual((web / 'verify.sh').read_text(), '#!/bin/sh\necho approved\n')
            self.assertFalse((web / 'dedupe.sh').exists())
            self.assertFalse((root / 'evil.sh').exists())
            log = (web / 'job.log').read_text()
            self.assertIn('installed verify.sh', log)
            self.assertIn('refused dedupe.sh', log)
            self.assertIn('refused ../evil.sh', log)


if __name__ == '__main__': unittest.main()

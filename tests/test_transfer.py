"""Run with: python3 -m unittest discover -s tests -v. No NAS or network used."""
import importlib.util
import io
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
        self.assertEqual(len(list(self.dest.iterdir())), 1)
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
             patch.object(m, 'control', return_value={}), \
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

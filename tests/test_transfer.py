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
        with patch.object(sys, 'argv', args), patch('sys.stdout', new_callable=io.StringIO): self.mod.main()

    def test_interrupted_copy_resumes_with_same_total_and_search_reports(self):
        (self.source / 'a.mov').write_bytes(b'a' * 10)
        (self.source / 'b.mov').write_bytes(b'b' * 20)
        rename = os.rename
        calls = [0]
        def disconnect(src, dst):
            calls[0] += 1
            if calls[0] == 2: raise OSError('network disconnected')
            return rename(src, dst)
        with patch.object(self.mod.os, 'rename', side_effect=disconnect): self.run_copy()
        self.assertEqual(self.reports[-1]['phase'], 'interrupted')
        self.assertLess(self.reports[-1]['done_bytes'], 30)
        self.assertFalse(self.mod.DONE.exists())
        self.run_copy()
        self.assertEqual(self.reports[-1]['phase'], 'done')
        self.assertEqual(self.reports[-1]['done_bytes'], 30)
        self.assertEqual(self.reports[-1]['copied_bytes'], 30)
        self.assertEqual((self.dest / 'b.mov').read_bytes(), b'b' * 20)
        self.assertEqual(self.mod._CHECKPOINTS.db.execute('SELECT COUNT(*) FROM search').fetchone()[0], 0)

    def test_same_name_different_content_is_not_completed_or_overwritten(self):
        (self.source / 'a.mov').write_bytes(b'new')
        self.dest.mkdir(); (self.dest / 'a.mov').write_bytes(b'old')
        self.run_copy()
        self.assertEqual(self.reports[-1]['phase'], 'interrupted')
        self.assertEqual(self.reports[-1]['done_bytes'], 0)
        self.assertEqual((self.dest / 'a.mov').read_bytes(), b'old')

    def test_missing_source_never_becomes_a_completed_empty_job(self):
        self.source.rmdir()
        self.run_copy()
        self.assertEqual(self.reports[-1]['phase'], 'blocked')
        self.assertFalse(self.mod.DONE.exists())

    def test_unreadable_walk_does_not_finish(self):
        def bad_walk(*args, **kwargs):
            kwargs['onerror'](OSError('disconnected during scan'))
            return iter(())
        with patch.object(self.mod.os, 'walk', side_effect=bad_walk):
            with self.assertRaises(OSError): self.run_copy()
        self.assertNotIn('done', [x['phase'] for x in self.reports])

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
             patch.object(m, 'free_bytes', return_value=10**15), patch.object(m, 'needs_trace', return_value=False), \
             patch.object(m, 'run_self', return_value=0) as run, patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(StopIteration): m.watch(str(self.archive))
        args = run.call_args.args
        self.assertEqual(args[args.index('--root')+1], str(self.archive))


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


if __name__ == '__main__': unittest.main()

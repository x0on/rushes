<?php
// pull-export.php — a pull, written for the computer that is downloading it.
//
//   ?p=<slug>&fmt=premiere&base=/Volumes/VIDEO     an XML Premiere opens as a bin
//   ?p=<slug>&fmt=list&base=Z:\                    every path, one per line
//   ?p=<slug>&fmt=zip                              the files themselves (small pulls)
//
// 'base' is where THIS computer sees the archive. The pull only knows where a
// clip sits inside the archive, so the same pull downloads as /Volumes/... on
// a Mac and Z:\... on a PC. The server never opens 'base'; it only writes it
// into the file.
//
// ponytail: the Premiere file is a bin of clips, not a timeline. A timeline
// needs each clip's length and frame rate, which come with previews.
//
// What describing found goes with each clip: a marker at every shot (what it
// shows, and any text on screen) and at every line spoken, and the first shot's
// description in the clip's Description. That is how descriptions reach
// Premiere for every kind of file — MP4 and MOV ignore sidecar .xmp files — and
// nothing is written beside the footage.
require_once __DIR__ . '/schema.php';
db_init();
$db = db();

function stop(string $why) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); exit($why . "\n"); }

$st = $db->prepare('SELECT * FROM pulls WHERE slug = ?');
$st->bindValue(1, (string)($_GET['p'] ?? ''));
$p = $st->execute()->fetchArray(SQLITE3_ASSOC);
if (!$p) stop('There is no pull with that link.');

$root = archive_dir() . '/';
$st = $db->prepare('SELECT i.rel, i.name, i.bytes FROM pull_items i JOIN files f ON f.path = ? || i.rel
                    WHERE i.pull_id = ? ORDER BY i.pos, i.added');
$st->bindValue(1, $root); $st->bindValue(2, $p['id']);
$r = $st->execute(); $items = [];
while ($x = $r->fetchArray(SQLITE3_ASSOC)) $items[] = $x;
if (!$items) stop('This pull has no clips that are still in the archive.');

$fmt  = (string)($_GET['fmt'] ?? 'premiere');
$base = rtrim(trim((string)($_GET['base'] ?? '')), '/\\');
$file = trim(preg_replace('/[^\w\- ]+/u', '', $p['name'])) ?: 'pull';
$win  = (bool)preg_match('/^([A-Za-z]:|\\\\\\\\)/', $base);

// Where a clip is, on the computer downloading this.
$local = function (string $rel) use ($base, $win): string {
    return $win ? $base . '\\' . str_replace('/', '\\', $rel) : $base . '/' . $rel;
};

if ($fmt === 'zip') {
    // The real files, for a handful of stills or a short clip or two — not
    // for footage, which is what the XML is for.
    $total = array_sum(array_column($items, 'bytes'));
    if ($total > 1073741824 || count($items) > 1000)
        stop('Too big to download as a zip (' . round($total / 1073741824, 1) . ' GB). Use the Premiere file or the list of paths — they point at the originals instead of copying them.');
    if (!class_exists('ZipArchive')) stop('This server cannot make zip files. Use the list of paths instead.');
    $tmp = tempnam(sys_get_temp_dir(), 'pull');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $used = [];
    foreach ($items as $it) {
        $src = $root . $it['rel'];
        if (str_contains($it['rel'], '..') || !is_file($src)) continue;
        $n = $it['name']; $k = 2;
        // two clips called A001_C001.mov from different shoots both go in
        while (isset($used[strtolower($n)])) {
            $n = pathinfo($it['name'], PATHINFO_FILENAME) . " ($k)." . pathinfo($it['name'], PATHINFO_EXTENSION); $k++;
        }
        $used[strtolower($n)] = true;
        $z->addFile($src, $n);
        $z->setCompressionName($n, ZipArchive::CM_STORE);   // footage does not compress; do not try
    }
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

if ($base === '') stop('Say where this computer sees the archive first.');

if ($fmt === 'list') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.txt"');
    echo implode($win ? "\r\n" : "\n", array_map(fn($it) => $local($it['rel']), $items)) . ($win ? "\r\n" : "\n");
    exit;
}

// ── Premiere (and Final Cut 7 XML readers): a bin of clips ─────────────────
// Each path as a file:// address. A Windows drive letter keeps its place
// (file://localhost/Z%3A/...), a network share becomes its host.
$url = function (string $rel) use ($base, $win): string {
    $segs = fn(string $s) => implode('/', array_map('rawurlencode', array_filter(preg_split('#[/\\\\]+#', $s), 'strlen')));
    if (str_starts_with($base, '\\\\')) {
        $parts = array_values(array_filter(explode('\\', $base), 'strlen'));
        return 'file://' . rawurlencode(array_shift($parts)) . '/' . $segs(implode('/', $parts) . '/' . $rel);
    }
    return 'file://localhost/' . $segs($base . '/' . $rel);
};
$x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '.xml"');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE xmeml>\n<xmeml version=\"4\">\n";
echo "  <bin>\n    <name>" . $x($p['name']) . "</name>\n    <children>\n";
require_once __DIR__ . '/analysis.php'; analysis_init();
$ledger = $db->prepare('SELECT m.fps, m.duration FROM media m JOIN files f ON f.id = m.file_id WHERE f.path = ?');
// ponytail: by the path the description was made at; after a tidy-up a clip keeps
// its file but its markers wait for describing to see it at its new place
$said = $db->prepare("SELECT kind, start_s, what, on_screen, themes FROM moments WHERE path = ? AND kind IN ('shot', 'speech') ORDER BY start_s");
foreach ($items as $i => $it) {
    $n = $i + 1;
    $ledger->bindValue(1, $root . $it['rel']); $m = $ledger->execute()->fetchArray(SQLITE3_ASSOC) ?: []; $ledger->reset();
    $said->bindValue(1, $root . $it['rel']); $r = $said->execute(); $mo = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $mo[] = $row;
    $said->reset();
    $fps = (float)($m['fps'] ?? 0); $tb = (int)round($fps); $ntsc = $fps && abs($fps - $tb) > 0.01 ? 'TRUE' : 'FALSE';
    $rate = $tb ? "<rate><timebase>$tb</timebase><ntsc>$ntsc</ntsc></rate>" : '';
    $dur = $tb && !empty($m['duration']) ? '<duration>' . (int)round($m['duration'] * $fps) . '</duration>' : '';
    echo "      <clip id=\"clip-$n\">\n        <name>" . $x($it['name']) . "</name>\n";
    if ($rate) echo "        $dur$rate\n";
    echo "        <file id=\"file-$n\">\n          <name>" . $x($it['name']) . "</name>\n";
    echo "          <pathurl>" . $x($url($it['rel'])) . "</pathurl>\n";
    if ($rate) echo "          $rate$dur\n";
    echo "        </file>\n";
    $shots = array_values(array_filter($mo, fn($r) => $r['kind'] === 'shot'));
    if ($shots) {
        $themes = implode(', ', array_unique(array_filter(array_map('trim', explode(' · ', implode(' · ', array_column($shots, 'themes')))))));
        echo "        <logginginfo><description>" . $x($shots[0]['what']) . "</description><lognote>" . $x($themes) . "</lognote></logginginfo>\n";
    }
    if ($tb) foreach ($mo as $r) {         // a marker needs the clip's frame rate: only for clips whose proxy was made
        $name = $r['kind'] === 'speech' ? '“' . $r['what'] . '”' : $r['what'];
        $note = $r['kind'] === 'speech' ? 'Said' : ($r['what'] . ($r['on_screen'] ? "\nOn screen: " . $r['on_screen'] : ''));
        echo "        <marker><name>" . $x(mb_strimwidth($name, 0, 80, '…')) . "</name><comment>" . $x($note) . "</comment>"
           . "<in>" . (int)round($r['start_s'] * $fps) . "</in><out>-1</out></marker>\n";
    }
    echo "      </clip>\n";
}
echo "    </children>\n  </bin>\n</xmeml>\n";

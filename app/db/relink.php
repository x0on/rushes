<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// relink.php — a Premiere project, pointed at where its footage is now.
//
// A tidy-up moves footage onto the shelf; a Premiere project that used it then
// opens with "media offline", still naming the old places. Every move is in the
// tidy-up records (_rushes/origin/… tidy.tsv, and their undos), so the project
// can be pointed at the new places without anyone hunting for files.
//
// The project itself never comes here. The page (Manage → Editors' projects) opens it on
// the editor's own computer and sends only the file paths written in it:
//
//   POST paths=["/Volumes/VIDEO/ARCHIVE/2019/x/A001.MXF", …]   (JSON)
//     -> {map: {old path: new path}, moved: n, kept: n, missing: [...]}
//
// Paths are matched by their part inside the archive, so it works however the
// editor's computer reaches it (/Volumes/VIDEO/…, Z:\…, \\nas\VIDEO\…): that
// beginning, and the kind of slashes, are kept exactly as they were.
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
// Manage is behind the Manage password, and so is this.
if (!may_act((string)($_POST['pass'] ?? ''))) { http_response_code(403); echo '{"error":"sign in first"}'; exit; }
@ini_set('memory_limit', '512M');       // ponytail: every move in memory; fine to hundreds of thousands

// Every tidy-up and undo played forward, oldest first: any place a file has
// ever been (inside the archive) -> where it is now.
function relink_moves(string $dir, string $from): array {
    $files = array_merge(glob("$dir/* tidy.tsv") ?: [], glob("$dir/* untidy.tsv") ?: []);
    sort($files);                                   // named by when they ran: oldest first
    $cur = []; $who = []; $from = rtrim($from, '/') . '/';
    $rel = fn(string $p) => str_starts_with($p, $from) ? substr($p, strlen($from)) : null;
    foreach ($files as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $p = explode("\t", $l);
            if (count($p) < 3 || $p[0] !== 'moved' || !($o = $rel($p[1])) || !($n = $rel($p[2]))) continue;
            $ps = array_unique(array_merge($who[$o] ?? [], [$o]));   // every place that means this file
            unset($who[$o]);
            foreach ($ps as $x) $cur[$x] = $n;
            $who[$n] = array_merge($who[$n] ?? [], $ps);
        }
    }
    return array_filter($cur, fn($n, $o) => $n !== $o, ARRAY_FILTER_USE_BOTH);     // put back where it was: nothing to do
}

// A path as the project wrote it -> [the same path pointed at where the file is
// now, its place inside the archive]; null when no tidy-up moved it. Matched by its end: the part inside the
// archive (at least a folder and a file, so a lone "A001.MXF" never matches).
function relink_path(string $path, array $moves): ?array {
    $win = str_contains($path, '\\') && !str_contains($path, '/');
    $c = preg_split('#[\\\\/]#', $path);
    for ($i = 0; $i < count($c) - 1; $i++) {
        $tail = implode('/', array_slice($c, $i));
        if (isset($moves[$tail])) {
            $new = implode('/', array_slice($c, 0, $i)) . ($i ? '/' : '') . $moves[$tail];
            return [$win ? str_replace('/', '\\', $new) : $new, $moves[$tail]];
        }
    }
    return null;
}

if (isset($_POST['paths'])) {
    $paths = json_decode((string)($_POST['paths'] ?? '[]'), true);
    if (!is_array($paths)) { http_response_code(400); echo '{"error":"no paths"}'; return; }
    $moves = relink_moves(archive_dir() . '/_rushes/origin', helper_archive());
    $map = []; $missing = [];
    $paths = array_slice(array_unique(array_filter($paths, 'is_string')), 0, 50000);
    foreach ($paths as $p) {
        if (!($r = relink_path($p, $moves))) continue;
        $map[$p] = $r[0];
        if (!is_file(archive_dir() . '/' . $r[1])) $missing[] = $r[0];     // moved since, outside Rushes: said, not hidden
    }
    echo json_encode(['map' => $map, 'moved' => count($map), 'kept' => count($paths) - count($map),
                      'missing' => array_slice($missing, 0, 50), 'tidyups' => count(glob(archive_dir() . '/_rushes/origin/* tidy.tsv') ?: [])],
                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

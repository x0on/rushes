<?php
// config.php — reads rules.json and settings.json, and answers questions about them.
//
// The rule this file exists to enforce: no PHP anywhere else decides anything about
// media. If you are about to write an `if` that says what a file IS, it belongs in
// rules.json and the answer belongs here. Build layers know about HTTP and files.
//
// Both files sit beside the web root, not inside db/, because the Python build reads
// exactly the same two files from exactly the same place.

// Every POST must come from Rushes' own pages. A browser always says where a
// request comes from (Origin, or else Referer), so another website's page that
// tries to make this browser press a Rushes button, with or without a
// password, is refused here, before any door reads the request. Scripts and
// the helper send neither header and are not affected.
(function () {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $from = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($from === '') return;
    $plain = fn($a) => preg_replace('/:(80|443)$/', '', strtolower($a));
    $u = parse_url($from) ?: [];
    $at = ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    if ($at !== '' && $plain($at) === $plain((string)($_SERVER['HTTP_HOST'] ?? ''))) return;
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'refused: this came from another website (' . substr($from, 0, 80) . '), not from Rushes']);
    exit;
})();

function rules(): array {
    static $r = null;
    if ($r === null) $r = load_json('rules.json', __DIR__ . '/../rules.json');
    return $r;
}

function settings(bool $again = false): array {
    static $s = null;
    if ($s === null || $again) $s = load_json('settings.json', __DIR__ . '/../settings.json');
    return $s;
}

// A missing or broken settings file is not something to paper over: every path in the
// app comes from it, so guessing would write files somewhere nobody expects.
function load_json(string $what, string $path): array {
    if (!is_readable($path)) {
        config_died("$what is missing", "Expected it at $path",
            "Put it there and reload. Every path in this app comes from it, so there is "
            . "nothing sensible to guess.");
    }
    $j = json_decode((string)file_get_contents($path), true);
    if (!is_array($j)) {
        config_died("$what is not valid JSON", json_last_error_msg(),
            "Check it in a JSON validator \u2014 usually a trailing comma or a smart quote.");
    }
    return $j;
}

// A missing config file used to throw, and PHP with display_errors off turns that into
// a blank page: the single least helpful thing a program can do. Say what is wrong,
// where, and what fixes it \u2014 as JSON if something is fetching, as a page if a person is.
function config_died(string $what, string $detail, string $fix): void {
    $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json')
         || str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '.php') && !headers_sent()
            && str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'state');
    http_response_code(500);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => "$what \u2014 $detail. $fix"]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset=utf-8><title>Rushes needs setting up</title>'
           . '<div style="font:15px/1.5 ui-sans-serif,-apple-system,sans-serif;max-width:620px;'
           . 'margin:60px auto;padding:0 20px;color:#1c1a17">'
           . '<h1 style="font-size:19px;margin:0 0 10px">' . htmlspecialchars($what) . '</h1>'
           . '<p style="color:#6f6862;margin:0 0 14px">' . htmlspecialchars($detail) . '</p>'
           . '<p style="color:#6f6862;margin:0">' . htmlspecialchars($fix) . '</p></div>';
    }
    exit;
}

// ── paths ──────────────────────────────────────────────────────────────────
function s_path(string $key, string $fallback = ''): string {
    $s = settings();
    foreach (explode('.', $key) as $part) {
        if (!is_array($s) || !array_key_exists($part, $s)) return $fallback;
        $s = $s[$part];
    }
    return is_string($s) ? rtrim($s, '/') : $fallback;
}

function web_dir(): string     { return s_path('archive.web',   '/share/Web'); }
function archive_dir(): string { return s_path('archive.local', '/share/VIDEO'); }

// ── thresholds ─────────────────────────────────────────────────────────────
// settings.json may override any of rules.json's numbers; a null there means
// "use the rule". One place to ask, so no page invents its own limit.
function limit(string $name, $fallback = 0) {
    $over = settings()['limits'][$name] ?? null;
    if ($over !== null) return $over;
    return rules()['conditions'][$name] ?? $fallback;
}

// ── what a file is ─────────────────────────────────────────────────────────
function kind_for(string $ext): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (rules()['kinds'] as $kind => $exts) {
            if ($kind === '_' || !is_array($exts)) continue;
            foreach ($exts as $e) $map[strtolower($e)] = $kind;
        }
    }
    return $map[strtolower($ext)] ?? 'other';
}

// Folder names that describe the camera rather than the shoot. Built once from the
// patterns in rules.json so someone with different gear edits JSON, not a regex in code.
function card_junk_re(): string {
    static $re = null;
    if ($re === null) {
        $parts = array_filter(rules()['card_junk']['patterns'] ?? [], 'is_string');
        $re = '/^(' . implode('|', $parts) . ')$/i';
    }
    return $re;
}

function is_card_junk(string $folder): bool {
    return (bool)preg_match(card_junk_re(), trim($folder));
}

function is_year(string $folder): bool {
    $p = rules()['structure']['year_pattern'] ?? '^(19|20)\d\d$';
    return (bool)preg_match('/' . str_replace('/', '\/', $p) . '/', trim($folder));
}

// Files that are the operating system's bookkeeping, not anyone's footage.
function is_system_junk(string $path): bool {
    $j = rules()['system_junk'] ?? [];
    foreach ($j['path_contains'] ?? [] as $frag) if (str_contains($path, $frag)) return true;
    $name = basename($path);
    foreach ($j['name_is'] ?? [] as $n)        if ($name === $n)              return true;
    foreach ($j['name_starts'] ?? [] as $pre)  if (str_starts_with($name, $pre)) return true;
    return false;
}

// ── cache groups, as SQL ───────────────────────────────────────────────────
// Turns a rules.json group into a WHERE clause. Values are whitelisted and quoted
// here rather than interpolated, because this file is the only place that reads them.
function cache_sql(array $group): string {
    $or = [];
    foreach ($group['ext'] ?? [] as $e) {
        if (preg_match('/^[a-z0-9]{1,8}$/i', $e)) $or[] = "ext = '" . strtolower($e) . "'";
    }
    foreach ($group['path_contains'] ?? [] as $frag) {
        $or[] = "path LIKE '%" . SQLite3::escapeString($frag) . "%'";
    }
    return $or ? '(' . implode(' OR ', $or) . ')' : '(0)';
}

function cache_groups(string $which = 'sweep'): array {
    return array_values(array_filter(
        rules()['cache'][$which] ?? [],
        fn($g) => is_array($g) && isset($g['label'])
    ));
}

// ── the mark ───────────────────────────────────────────────────────────────
// A vector reconstruction of the supplied logo (concept 01 / Together), traced
// from the handoff artwork. One copy, used by every page, coloured by CSS via
// currentColor so it works on light, on dark, and inverse on a filled button.
function mark(string $class = 'mk'): string {
    return '<svg class="' . $class . '" viewBox="0 0 322 317" aria-hidden="true">'
         . '<path fill="currentColor" fill-rule="evenodd" d="M83.5 292.9C65.9 283.5 43.4 271.6 33.5 266.4C14.1 256.2 8.9 252.0 7.1 245.4C6.4 242.7 6.0 212.6 6.0 151.2C6.0 54.9 5.9 57.0 10.8 52.6C15.4 48.5 19.8 49.2 48.1 58.3C62.6 63.0 83.3 69.6 94.0 73.0C104.7 76.4 114.8 79.9 116.5 80.7C121.6 83.3 125.9 88.2 128.0 93.7C130.0 98.9 130.0 101.6 130.0 199.3C130.0 265.2 129.6 300.8 129.0 303.2C127.7 307.9 124.7 310.0 119.5 310.0C116.2 310.0 109.7 306.9 83.5 292.9ZM207.0 258.0C207.0 213.0 207.1 210.0 208.8 210.0C213.5 210.0 218.1 214.3 253.7 251.9C268.4 267.5 283.3 283.1 286.6 286.6C290.0 290.2 293.3 294.4 294.0 296.0C295.6 300.0 294.5 303.8 291.4 305.0C289.9 305.6 271.8 306.0 247.9 306.0L207.0 306.0L207.0 258.0ZM161.2 273.2L142.0 263.5L142.0 177.0C142.0 95.4 141.9 90.3 140.1 86.5C137.5 80.6 133.3 76.2 128.1 73.6C124.0 71.5 101.7 64.0 64.3 52.0L51.0 47.7L51.0 42.3C51.0 32.2 55.1 27.5 63.9 27.5C69.2 27.6 172.2 61.4 180.5 65.8C186.1 68.8 191.4 75.2 193.0 80.8C193.6 83.2 194.0 117.4 194.0 180.3L194.0 276.2L190.6 279.6C185.4 284.8 183.3 284.3 161.2 273.2ZM217.0 195.4C215.1 195.2 212.1 194.7 210.3 194.5L207.1 193.9L206.8 134.7L206.5 75.5L204.1 71.0C201.1 65.1 196.5 60.3 191.5 57.8C189.3 56.6 167.5 49.1 143.0 41.0L98.5 26.4L98.5 19.7C98.5 13.6 98.8 12.8 101.6 9.9C106.8 4.7 109.6 4.9 136.6 12.5C149.7 16.2 165.7 20.7 172.0 22.5C178.3 24.4 195.7 29.3 210.5 33.5C225.3 37.7 244.2 43.1 252.5 45.5C260.8 47.9 268.9 50.2 270.5 50.6C272.1 51.0 277.1 53.1 281.5 55.3C293.1 61.1 303.6 71.8 309.1 83.4C317.6 101.7 318.2 119.3 310.9 137.7C301.0 162.6 274.2 185.2 246.0 192.4C236.5 194.8 223.6 196.1 217.0 195.4Z"/></svg>';
}

// The app icon, black with the white mark, as PNG: Safari ignores an SVG
// icon and falls back to one of its own. 64 px for the tab, 180 px for a
// phone's home screen.
function favicon_href(): string {
    return 'data:image/png;base64,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';
}
function home_icon_href(): string {
    return 'data:image/png;base64,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';
}

// ── icons ──────────────────────────────────────────────────────────────────
// One line drawing per idea, in one place. A rail item without a recognisable
// picture is just indented text, and the box-drawing characters that were here
// before did not render at all on some machines.
function icon(string $name, float $w = 1.7): string {
    static $d = [
        'everything' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/>',
        'archive'    => '<rect x="3" y="3.5" width="18" height="4.5" rx="1.4"/><path d="M5 8v11.5a1.5 1.5 0 0 0 1.5 1.5h11a1.5 1.5 0 0 0 1.5-1.5V8"/><path d="M10 12h4"/>',
        'projects'   => '<path d="M3 6.5A2 2 0 0 1 5 4.5h3.6l2 2.6H19a2 2 0 0 1 2 2v8.4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'library'    => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>',
        'search'     => '<circle cx="11" cy="11" r="6.8"/><path d="m20 20-3.9-3.9"/>',
        'overview'   => '<path d="M3.5 13a8.5 8.5 0 0 1 17 0"/><path d="M12 13l4.2-3.4"/><circle cx="12" cy="13" r="1.4" fill="currentColor" stroke="none"/><path d="M3.5 13v3.5h17V13"/>',
        'transfers'  => '<path d="M4 8.5h13"/><path d="m14 5.5 3 3-3 3"/><path d="M20 15.5H7"/><path d="m10 12.5-3 3 3 3"/>',
        'cache'      => '<path d="M4 7h16"/><path d="M9.5 7V5.2A1.2 1.2 0 0 1 10.7 4h2.6a1.2 1.2 0 0 1 1.2 1.2V7"/><path d="M6.2 7 7.1 19a2 2 0 0 0 2 1.9h5.8a2 2 0 0 0 2-1.9L17.8 7"/>',
        'activity'   => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'tools'      => '<path d="M4 6.5h9"/><path d="M18.5 6.5H20"/><circle cx="15.7" cy="6.5" r="2.2"/><path d="M4 17.5h9"/><path d="M18.5 17.5H20"/><circle cx="15.7" cy="17.5" r="2.2"/><path d="M4 12h1.5"/><path d="M11 12h9"/><circle cx="8.3" cy="12" r="2.2"/>',
        'back'       => '<path d="M20 12H4.5"/><path d="m11 5.5-6.5 6.5L11 18.5"/>',
        'signout'    => '<path d="M14.5 4H18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3.5"/><path d="m9 8-4 4 4 4"/><path d="M5 12h10"/>',
        'lock'       => '<rect x="4" y="10" width="16" height="10.5" rx="2.2"/><path d="M8 10V7.2a4 4 0 0 1 8 0V10"/>',
        'video'      => '<rect x="3" y="5" width="18" height="14" rx="2.2"/><path d="M8 5v14M16 5v14M3 12h18M3 8.5h5M3 15.5h5M16 8.5h5M16 15.5h5"/>',
        'image'      => '<rect x="3" y="4.5" width="18" height="15" rx="2.2"/><circle cx="8.6" cy="9.6" r="1.8"/><path d="m4 17 5-4.6 4.4 4 3-2.6L20 17.6"/>',
        'audio'      => '<path d="M4 11v2M7.5 8.5v7M11 5.5v13M14.5 8.5v7M18 10v4M21 11.2v1.6"/>',
        'project'    => '<path d="m12 3 9 4.6-9 4.6-9-4.6Z"/><path d="m3 12.2 9 4.6 9-4.6"/><path d="m3 16.6 9 4.6 9-4.6"/>',
        'file'       => '<path d="M6 3.5h7.5L19 9v11.5H6Z"/><path d="M13.5 3.5V9H19"/>',
        'camera'     => '<path d="M3.5 8.5a2 2 0 0 1 2-2h2.3l1.5-2h5.4l1.5 2h2.3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2Z"/><circle cx="12" cy="13" r="3.6"/>',
        'server'     => '<rect x="4" y="3.5" width="16" height="5.5" rx="1.5"/><rect x="4" y="9.3" width="16" height="5.5" rx="1.5"/><rect x="4" y="15" width="16" height="5.5" rx="1.5"/><path d="M7.5 6.2h.01M7.5 12h.01M7.5 17.8h.01"/>',
        'info'       => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.6h.01"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
    ];
    $p = $d[$name] ?? $d['file'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $w
         . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

// ── what can be picked ─────────────────────────────────────────────────────
// Two lists, from the two machines that can copy. Every path a person chooses
// comes from one of these, so it is right for the machine that will use it.

// The folder inside the archive that departments live on, chosen in
// Reorganize → 00. No default: a guessed name would file footage somewhere
// nobody chose. Until it is chosen, Ingest and the tidy-up refuse.
function shelf_name(): string {
    return trim(str_replace(['/', '\\', "\0"], '', (string)(settings()['organise']['shelves'] ?? '')));
}
function shelf_dir(): string {
    return archive_dir() . '/' . (shelf_name() !== '' ? shelf_name() : '.no-shelf-chosen');
}
// The folders at the top of the archive that could be the shelf: not Rushes'
// own (_rushes, _duplicates), not ARCHIVE (copies land there) or PROXIES.
function shelf_choices(): array {
    $out = [];
    foreach (@scandir(archive_dir()) ?: [] as $f)
        if (!preg_match('/^[._@#$]/', $f) && !in_array($f, ['ARCHIVE', 'PROXIES'], true) && is_dir(archive_dir() . "/$f")) $out[] = $f;
    sort($out);
    return $out;
}

// Which copy of a duplicate is kept: the rules dedupe.sh scores copies by,
// written as plain lines it can read (weight, kind, text) when a plan is asked
// for. The kinds: contains (a piece of the path), matches (an awk pattern),
// card (a card-dump folder, loses when the project copy is kept), project (the
// shelf, loses when card dumps are kept). Written only when they change, so a
// plan you saw is still the one moved. Returns the lines, or null if it could
// not write them.
function dedupe_rules_write(): ?array {
    $r = rules()['duplicates']['never_keep'] ?? [];
    $w = $r['settings_weight'] ?? ['never_keep' => 500, 'other_side' => 450];
    $clean = fn($s) => trim(preg_replace('/[\t\r\n\/\\\\]+/', ' ', (string)$s));
    $lines = [];
    foreach ($r['rules'] ?? [] as $x)
        $lines[] = (int)$x['weight'] . "\t" . (isset($x['matches']) ? "matches\t" . $x['matches'] : "contains\t" . $x['contains']);
    $d = settings()['duplicates'] ?? [];
    foreach ($d['never_keep'] ?? [] as $f) if (($f = $clean($f)) !== '') $lines[] = (int)$w['never_keep'] . "\tcontains\t/$f/";
    foreach ($d['card_dumps'] ?? [] as $f) if (($f = $clean($f)) !== '') $lines[] = (int)$w['other_side'] . "\tcard\t/$f/";
    // The shelf loses only where card dumps are set, and card dumps were chosen to win:
    // with no card-dump folders, neither side is preferred, whichever was chosen.
    if (shelf_name() !== '' && ($d['card_dumps'] ?? []) !== [])
        $lines[] = (int)$w['other_side'] . "\tproject\t/" . shelf_name() . '/';
    // Editors' projects and the stock library (in Projects) are never moved: archived projects point at them.
    if (shelf_name() !== '') $lines[] = "0\tkeep\t/" . shelf_name() . '/Projects/';
    $file = web_dir() . '/dedupe-rules.tsv';
    $body = implode("\n", $lines) . "\n";
    if (@file_get_contents($file) === $body) return $lines;
    return (@file_put_contents("$file.new", $body) !== false && @rename("$file.new", $file)) ? $lines : null;
}

// What the helper reported. 'fresh' is false once it has been quiet for a
// minute and a half — the helper stopped, so the list may be out of date.
function helper_volumes(): array {
    $vols = []; $at = 0; $os = ''; $ver = ''; $how = ''; $host = ''; $an = []; $stuck = '';
    foreach (@file(web_dir() . '/helper-volumes.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if ($f[0] === 'at') $at = (int)$f[1];
        elseif ($f[0] === 'os') $os = $f[1];
        elseif ($f[0] === 'ver') $ver = $f[1] ?? '';
        elseif ($f[0] === 'how') $how = $f[1] ?? '';
        elseif ($f[0] === 'host') $host = $f[1] ?? '';
        elseif ($f[0] === 'stuck') $stuck = $f[1] ?? '';
        elseif ($f[0] === 'an' && ($f[1] ?? '') !== '')
            $an = ['ready' => $f[1] === 'ready', 'model' => $f[2] ?? '', 'speech' => $f[3] ?? ''];
        elseif ($f[0] === 'vol' && count($f) === 9) {
            $vols[$f[1]] = ['path' => $f[1], 'name' => $f[2], 'total' => (int)$f[3],
                            'free' => (int)$f[4], 'card' => $f[5] === '1',
                            'archive' => $f[6] === '1', 'files' => (int)$f[7],
                            'bytes' => (int)$f[8], 'top' => [], 'days' => []];
        } elseif ($f[0] === 'dir' && count($f) === 3 && isset($vols[$f[1]])) {
            $vols[$f[1]]['top'][] = $f[2];
        } elseif ($f[0] === 'day' && count($f) === 5 && isset($vols[$f[1]])) {
            $vols[$f[1]]['days'][] = ['day' => $f[2], 'files' => (int)$f[3], 'bytes' => (int)$f[4]];
        }
    }
    return ['at' => $at, 'os' => $os, 'fresh' => $at && time() - $at < 90,
            'ver' => $ver, 'how' => $how, 'host' => $host, 'analysis' => $an, 'stuck' => $stuck,
            'vols' => array_values($vols)];
}

// The helper's own separator, so a Windows helper's picks read E:\Footage.
function helper_join(string $vol, string $dir, string $os): string {
    $sep = $os === 'win32' ? '\\' : '/';
    return rtrim($vol, '/\\') . $sep . $dir;
}

// Every path the helper could be asked to read: each drive, and each folder at
// its top level.
function helper_paths(): array {
    $h = helper_volumes(); $ok = [];
    foreach ($h['vols'] as $v) {
        $ok[$v['path']] = true;
        foreach ($v['top'] as $d) $ok[helper_join($v['path'], $d, $h['os'])] = true;
    }
    return $ok;
}

// What this machine — the one serving the page — has, one level down from the
// archive's parent. On the NAS that is its shares.
function local_volumes(): array {
    $base = dirname(archive_dir()); $out = [];
    foreach (@scandir($base) ?: [] as $n) {
        if ($n[0] === '.' || $n[0] === '@' || preg_match('/^(CACHEDEV|MD\d|HD\w*_DATA)/i', $n)) continue;
        $p = "$base/$n";
        if (!is_dir($p)) continue;
        $top = [];
        foreach (@scandir($p) ?: [] as $d) {
            if ($d[0] !== '.' && $d[0] !== '@' && is_dir("$p/$d")) $top[] = $d;
            if (count($top) >= 200) break;
        }
        $out[] = ['path' => $p, 'name' => $n, 'top' => $top];
    }
    return $out;
}

// How to start the helper. Worked out from where it sees the archive, every
// time it is shown — so it is right the moment that setting is, with no Save
// in between. The helper runs from the archive itself, so an update to it is a
// deploy like any page, never a file copied onto the workstation by hand.
// The helper is the part of Rushes that copies. Built in, it runs on the same
// machine as these pages. External, it runs on another computer that can see
// something this one cannot — cards in a workstation, a server on another
// network. Same program either way.
function helper_mode(): string {
    $h = settings()['helper'] ?? [];
    if (isset($h['mode'])) return $h['mode'] === 'external' ? 'external' : 'built_in';
    return !empty($h['enabled']) ? 'external' : 'built_in';   // settings from before there were two kinds
}
function helper_name(): string {
    return helper_mode() === 'external' ? (settings()['helper']['label'] ?? 'workstation') : 'this machine';
}
// Where the helper finds the archive: this machine's own path when it is
// built in, the other computer's view of it when it is not.
function helper_archive(): string {
    return rtrim(helper_mode() === 'external' ? s_path('archive.as_seen_from_helper', '') : archive_dir(), '/\\');
}

// Z:\ or \\server\share is Windows; /Volumes/... is a Mac. The path says
// which, so this is right before the helper has ever reported.
function helper_windows(): bool {
    return (bool)preg_match('/^([A-Za-z]:|\\\\)/', helper_archive())
        || helper_volumes()['os'] === 'win32';
}

function helper_command(): string {
    $at  = helper_archive();
    $url = settings()['archive']['url'] ?? '';
    if ($at === '') return settings()['helper']['command'] ?? '';
    $tail = ' --watch' . ($url !== '' ? ' --url ' . $url : '');
    return helper_windows()
        ? 'python "' . $at . '\\_rushes\\ingest.py"' . $tail
        : 'python3 "' . $at . '/_rushes/ingest.py"' . $tail;
}

// This machine by name (http://nas.local), which keeps working on a local
// network when its number changes. Empty when the machine has no usable name.
function name_url(): string {
    $h = strtolower(explode('.', (string)gethostname())[0]);
    if (!preg_match('/^[a-z0-9-]+$/', $h) || $h === 'localhost') return '';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
    return ($https ? 'https' : 'http') . "://$h.local" . (in_array($port, [80, 443, 0], true) ? '' : ":$port");
}

// The address this page was opened at: a good first guess for Setup.
function here_url(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host)) return '';
    return ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . "://$host";
}

// A command on one line, with a Copy button beside it. Never wrapped: a
// command broken across two lines is how a stray space gets pasted into it.
function cmd_block(string $cmd): string {
    $e = htmlspecialchars($cmd);
    return '<div class="cmd"><code>' . $e . '</code>'
         . '<button type="button" class="ghost" data-copy="' . $e . '">Copy</button></div>';
}

// "4 minutes ago", never a clock time: the machine serving the page may keep a
// different time zone from the person reading it, but not a different minute.
function ago_words(int $t): string {
    if (!$t) return 'never';
    $s = time() - $t;
    if ($s < 90)     return 'just now';
    if ($s < 5400)   return round($s / 60) . ' minutes ago';
    if ($s < 172800) return round($s / 3600) . ' hours ago';
    return round($s / 86400) . ' days ago';
}

// ── the structure: departments, and the folder each one lives in ───────────
// The plan, written down. Ingest offers exactly this list and nothing else.
// A department's folder is either one already on the shelf (linked, so
// nothing is renamed and no project breaks) or, if none is linked, a folder
// with the department's own name, made the first time something is ingested.
function departments(): array {
    return array_values(array_filter(settings()['organise']['departments'] ?? [],
        fn($d) => is_array($d) && trim($d['name'] ?? '') !== ''));
}
function dept_folder(string $name): ?string {
    foreach (departments() as $d) if ($d['name'] === $name) return ($d['folder'] ?? '') !== '' ? $d['folder'] : $d['name'];
    return null;
}
function dept_of_folder(string $folder): ?string {
    foreach (departments() as $d) if ((($d['folder'] ?? '') ?: $d['name']) === $folder) return $d['name'];
    return null;
}
// The folders already on the shelf, whatever the plan says.
function shelf_folders(): array {
    $out = [];
    foreach (@scandir(shelf_dir()) ?: [] as $f) if ($f[0] !== '.' && $f[0] !== '@' && is_dir(shelf_dir() . "/$f")) $out[] = $f;
    sort($out);
    return $out;
}

// A first guess at which existing folder each department already has, so
// nobody links sixteen of them by hand. Only a suggestion: every link is shown
// and can be changed before anything is saved.
//   "City Manager's Office"  ↔  MANAGER'S OFFICE     (the words that matter)
//   "Information Technology" ↔  IT                   (initials)
//   "Police Department"      ↔  POLICE DEPARTMNET    (a typo)
//   "Public Works"           ↔  PUBLICS WORKS        (one letter off)
function dept_words(string $s): array {
    $s = strtoupper(preg_replace("/['’]S\\b/i", '', $s));
    $out = [];
    foreach (preg_split('/[^A-Z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (in_array($w, ['CITY', 'THE', 'AND', 'OF', 'FOR', 'OFFICE', 'DEPT'], true)) continue;
        if (levenshtein($w, 'DEPARTMENT') <= 2) continue;          // and its misspellings
        $out[] = $w;
    }
    return $out;
}
function dept_match(string $name, string $folder): float {
    $want = dept_words($name); $have = dept_words($folder);
    if (!$want || !$have) return 0;
    $initials = implode('', array_map(fn($w) => $w[0], $want));
    if (count($have) === 1 && strlen($have[0]) >= 2 && $have[0] === $initials) return 1.0;
    $hit = 0;
    foreach ($want as $w) foreach ($have as $h) {
        if ($w === $h || (strlen($w) > 4 && levenshtein($w, $h) <= 1)) { $hit++; break; }
    }
    return $hit / max(count($want), count($have));
}
// Best pairs first, each folder used once — so "Economic Development" takes
// ECONOMIC DEVELOPMENT and "Development Services" is not handed it as well.
function guess_links(array $names, array $folders): array {
    $pairs = [];
    foreach ($names as $n) foreach ($folders as $f) {
        $s = dept_match($n, $f);
        if ($s >= 0.5) $pairs[] = [$s, $n, $f];
    }
    usort($pairs, fn($a, $b) => $b[0] <=> $a[0]);
    $link = array_fill_keys($names, ''); $used = [];
    foreach ($pairs as [$s, $n, $f]) {
        if ($link[$n] !== '' || isset($used[$f])) continue;
        $link[$n] = $f; $used[$f] = true;
    }
    return $link;
}

// Settings are written whole, beside the real file, then swapped in: a
// half-written settings file is the one thing that takes every page down.
function save_settings(array $s): bool {
    $file = __DIR__ . '/../settings.json';
    $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents("$file.new", $json . "\n") !== false && @rename("$file.new", $file)) {
        settings(true); runner_paths();
        return true;
    }
    @unlink("$file.new");
    return false;
}

// The runner is a shell script and cannot read JSON: where the archive is, as
// one plain line it checks again (runner.sh, top). Written when settings are
// saved, and by the runner's own minute (import.php) if it is missing or out
// of date.
// Which Rushes this is: one number for the pages, the helper's code and both apps (VERSION, beside the pages).
function rushes_version(): string {
    static $v = null;
    return $v ??= (trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '?');
}

function runner_paths(): void {
    $f = web_dir() . '/archive-path.txt'; $want = archive_dir() . "\n";
    if (@file_get_contents($f) !== $want) @file_put_contents($f, $want);
    // the Projects share, where the runner moves folders aside and back (projects.php)
    $f = web_dir() . '/projects-path.txt'; $want = s_path('shares.projects') . "\n";
    if (@file_get_contents($f) !== $want) @file_put_contents($f, $want);
}

// What this installation calls its top folders — Departments here, Clients or
// Projects somewhere else — and whether they can be added from Ingest. The
// shape underneath is the same either way; only the word and the door change.
function shelf_word(bool $many = false): string {
    $k = settings()['organise']['kind'] ?? [];
    return $many ? ($k['many'] ?? 'Departments') : ($k['one'] ?? 'Department');
}
function shelf_open(): bool {                 // may a new one be added at Ingest?
    return !empty(settings()['organise']['add_at_ingest']);
}
// Why a name cannot be one of the top folders, or '' if it can. One rule for
// Structure and Ingest alike: no catch-all, and nothing that breaks a path.
function shelf_name_problem(string $n, array $existing = []): string {
    $one = strtolower(shelf_word());
    if ($n === '') return "Give the new $one a name.";
    if (strcasecmp($n, 'Projects') === 0) return "“Projects” is where Rushes keeps editors' projects, beside the {$one}s: pick another name.";
    if (preg_match('/^(others?|misc(ellaneous)?|general|various|varios|otros?|unsorted|stuff)$/i', $n))
        return "“{$n}” would be a catch-all. Every shoot belongs to a $one — add the one it belongs to instead.";
    if (preg_match('#[/\\\\:*?"<>|]|\.\.#', $n) || $n[0] === '.') return "“{$n}” becomes a folder name, so it cannot hold / \\ : * ? \" < > | or ..";
    if (mb_strlen($n) > 80) return "“{$n}” is too long for a folder name.";
    foreach ($existing as $e) if (strcasecmp($e, $n) === 0) return "“{$n}” is already in the list.";
    return '';
}

// ── the helper's remote controls, and scripts waiting to be installed ────────
// Written by the buttons in Manage, read by the helper every few seconds.
function helper_control(): array {
    $c = json_decode((string)@file_get_contents(web_dir() . '/helper-control.json'), true);
    return (is_array($c) ? $c : []) + ['paused' => false, 'nudge' => 0, 'skip' => [], 'by' => '', 'at' => 0];
}
function helper_control_save(array $c): bool {
    $f = web_dir() . '/helper-control.json';
    return @file_put_contents("$f.new", json_encode($c, JSON_UNESCAPED_SLASHES)) !== false && @rename("$f.new", $f);
}
// Scripts run as root on this machine, so they are never published from the
// share on their own: they wait in _rushes/scripts until the admin says yes,
// and the runner installs exactly the files approved (checked by fingerprint).
// What is waiting to be installed — updated scripts, and pages (HOW-IT-WORKS.md → Updates) —
// as the runner last saw it (waiting.tsv, when someone pressed Check for updates, within its time
// limit). A page request never reads the VIDEO share itself.
function waiting_read(): ?array {
    $f = web_dir() . '/waiting.tsv';
    if (!is_readable($f)) return null;
    $out = ['items' => [], 'helper' => '', 'files' => [], 'at' => filemtime($f)];
    foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $p = explode("\t", $l);
        if ($p[0] === 'helper') $out['helper'] = $p[1] ?? '';
        elseif ($p[0] === 'helperfile' && count($p) >= 3 && preg_match('/^[0-9a-f]{64}$/', $p[2])) $out['files'][$p[1]] = $p[2];
        elseif (in_array($p[0], ['script', 'page'], true) && count($p) >= 4 && preg_match('/^[0-9a-f]{64}$/', $p[2]))
            $out['items'][] = ['kind' => $p[0], 'name' => $p[1], 'hash' => $p[2], 'new' => false, 'changed' => (int)$p[3]];
    }
    return $out;
}
function scripts_waiting(): array {
    // No list yet (the runner writes it within a minute of starting): nothing is shown
    // as waiting, rather than this page reading the VIDEO share itself.
    return ($w = waiting_read()) !== null ? $w['items'] : [];
}

// ── pairing (HOW-IT-WORKS.md → Pairing) ───────────────────────────────────────────────────
// One helper is paired with Rushes, and only it is given work: two helpers
// copying the same queue would copy over each other. Setup shows a one-time
// code; Rushes Helper sends it once and gets an ID back, which it then sends
// with every request (X-Rushes-Helper). Kept in a .php file that prints
// nothing: the web folder is served, and this must not be downloadable.
// Not paired yet: every helper is given work, as before, and Setup says so.
function helper_id_file(): string { return web_dir() . '/helper-id.php'; }

function helper_paired(): array {
    $p = is_readable(helper_id_file()) ? @include helper_id_file() : [];
    return is_array($p) && !empty($p['id']) ? $p : [];
}

// Rushes Watchers, one on each editor's computer (HOW-IT-WORKS.md → Projects
// in and out). Many, each paired once, each only able to deliver files. Kept
// by the fingerprint of each ID, never the ID itself, in a .php file that
// prints nothing: a copy of the file does not let anyone act as a Watcher.
function watchers_file(): string { return web_dir() . '/watchers.php'; }
function watchers(): array {
    $w = is_readable(watchers_file()) ? @include watchers_file() : [];
    return is_array($w) ? $w : [];
}
// The Watcher asking (its ID in X-Rushes-Watcher), or null.
function watcher_me(): ?array {
    $id = (string)($_SERVER['HTTP_X_RUSHES_WATCHER'] ?? '');
    if ($id === '') return null;
    $h = hash('sha256', $id);
    foreach (watchers() as $k => $w) if (hash_equals((string)$k, $h)) return $w + ['key' => substr($k, 0, 16)];
    return null;
}
function watcher_gate(): array {
    $w = watcher_me();
    if ($w) return $w;
    http_response_code(403); header('Content-Type: application/json');
    echo json_encode(['error' => 'not a paired Watcher',
        'why' => 'Pair this computer in Rushes → Setup → Editors\' computers → Add one, and enter the six numbers in Rushes Watcher.']);
    exit;
}

function php_keep(string $f, array $a): bool {
    $ok = @file_put_contents("$f.new", "<?php return " . var_export($a, true) . ";\n") !== false && @rename("$f.new", $f);
    if ($ok) { @chmod($f, 0600); if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true); }
    return $ok;
}

// 'none' (not paired), 'this' (the paired helper asking) or 'other'
function helper_pairing(): string {
    $p = helper_paired();
    if (!$p) return 'none';
    // the helper built into the archive machine asks from the machine itself
    if (helper_mode() === 'built_in' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) return 'this';
    return hash_equals((string)$p['id'], (string)($_SERVER['HTTP_X_RUSHES_HELPER'] ?? '')) ? 'this' : 'other';
}

// At the top of every door that gives a helper work or takes its word: any
// helper but the paired one is refused, and named on the page.
function helper_gate(): void {
    if (helper_pairing() !== 'other') return;
    $f = web_dir() . '/helper-refused.tsv';
    $l = array_slice(@file($f, FILE_IGNORE_NEW_LINES) ?: [], -19);
    $l[] = time() . "\t" . ($_SERVER['REMOTE_ADDR'] ?? '') . "\t" . substr(preg_replace('/[\t\r\n]/', ' ', (string)($_POST['host'] ?? '')), 0, 80);
    @file_put_contents("$f.new", implode("\n", $l) . "\n") !== false && @rename("$f.new", $f);
    http_response_code(403); header('Content-Type: application/json');
    echo json_encode(['error' => 'not the paired helper',
        'why' => 'Rushes is paired with another helper. To use this one instead, get a code in Rushes → Setup → Pair a helper, and enter it in Rushes Helper.']);
    exit;
}

// Helpers refused lately (the last hour): where from, and the computer's name when it said
function helper_refused(): array {
    $out = [];
    foreach (@file(web_dir() . '/helper-refused.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $x) {
        [$t, $ip, $host] = array_pad(explode("\t", $x), 3, '');
        if (time() - (int)$t > 3600) continue;
        $k = $host !== '' ? $host : $ip;
        $out[$k] = ['ip' => $ip, 'host' => $host, 'at' => (int)$t];
    }
    return array_values($out);
}

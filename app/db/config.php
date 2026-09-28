<?php
// config.php — reads rules.json and settings.json, and answers questions about them.
//
// The rule this file exists to enforce: no PHP anywhere else decides anything about
// media. If you are about to write an `if` that says what a file IS, it belongs in
// rules.json and the answer belongs here. Build layers know about HTTP and files.
//
// Both files sit beside the web root, not inside db/, because the Python build reads
// exactly the same two files from exactly the same place.

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

// The tab icon: the app-icon variation of the logo — the black rounded
// square with the mark cut out — traced from the supplied artwork and inlined
// so no image file has to be deployed or fetched.
function favicon_href(): string {
    return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNjUgMTYwIj48cGF0aCBmaWxsPSIjMUQxQzFCIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0zOC4zOCAxNTMuNDdDMzYuMzEgMTUzLjM5IDMzLjc4IDE1My4xOCAzMi43NSAxNTMuMDFDMzAuMDkgMTUyLjU2IDI1LjUxIDE1MS4xNCAyMy41MSAxNTAuMTNDMjAuMjMgMTQ4LjQ4IDE1LjA3IDE0My44MiAxMi4yNyAxNDAuMDBDMTAuNTQgMTM3LjYzIDguNTEgMTMzLjY2IDcuODcgMTMxLjM4QzYuNjMgMTI2Ljk4IDYuNjggMTI4LjgwIDYuNjUgODAuMjVDNi42MyA0NC4yMCA2LjY5IDM0LjQ0IDYuOTUgMzIuNzVDNy40OCAyOS4yOCA5LjQ2IDIzLjk1IDExLjIxIDIxLjI1QzEzLjE2IDE4LjI1IDE1LjgyIDE1LjMyIDE4LjYyIDEzLjEwQzE5Ljk2IDEyLjAzIDIzLjA1IDEwLjAwIDIzLjMzIDEwLjAwQzIzLjQyIDEwLjAwIDI0LjIwIDkuNjUgMjUuMDYgOS4yM0MyNy42MSA3Ljk4IDI5LjcwIDcuMjYgMzIuMTEgNi44MUMzNC4xOCA2LjQyIDM4LjQ1IDYuMzggODIuMzMgNi4zMUMxMjkuNjYgNi4yNCAxMzAuMzMgNi4yNSAxMzIuOTkgNi43NUMxMzcuNjkgNy42MyAxNDEuNDcgOS4xOCAxNDUuNTAgMTEuODZDMTQ4LjI5IDEzLjcyIDE1Mi4wMSAxNy40NCAxNTMuODIgMjAuMjBDMTU1LjE1IDIyLjIxIDE1Ny4xOCAyNi4zNyAxNTguMjUgMjkuMjZDMTU4LjYzIDMwLjMwIDE1OS4wMiAzMS44NyAxNTkuMTEgMzIuNzZDMTU5LjM0IDM0Ljk5IDE1OS40MyA5My43MiAxNTkuMjQgMTEyLjUwQzE1OS4xMCAxMjUuOTcgMTU5LjAzIDEyOC4wNSAxNTguNjUgMTI5LjI1QzE1OC40MiAxMzAuMDEgMTU4LjExIDEzMS4wNyAxNTcuOTggMTMxLjYyQzE1Ny44NCAxMzIuMTggMTU3LjEyIDEzMy44NiAxNTYuMzkgMTM1LjM4QzE1NC4zMSAxMzkuNjUgMTUxLjA2IDE0My42OSAxNDcuMTcgMTQ2Ljg0QzE0NS45MSAxNDcuODYgMTQ0LjMyIDE0OS4wNCAxNDMuNjUgMTQ5LjQ2QzE0MS4wMyAxNTEuMTEgMTM2LjQ1IDE1Mi41OSAxMzEuODggMTUzLjI2QzEzMC40MiAxNTMuNDcgMTE4LjA2IDE1My41NiA4Ni4wMCAxNTMuNTlDNjEuODcgMTUzLjYxIDQwLjQ0IDE1My41NiAzOC4zOCAxNTMuNDdaTTY4LjI0IDEyNy42M0M2OC42MyAxMjcuNDMgNjkuMjEgMTI2Ljg0IDY5LjUzIDEyNi4zM0M3MC4xMCAxMjUuNDAgNzAuMTAgMTI1LjQwIDcwLjIxIDkzLjEyQzcwLjMzIDU2LjYxIDcwLjQ0IDU4LjkzIDY4LjQyIDU2LjkxQzY3LjMyIDU1LjgxIDY3LjU0IDU1LjkxIDU5LjAwIDUyLjc4QzU3LjE0IDUyLjEwIDU0Ljk1IDUxLjI2IDU0LjEyIDUwLjkxQzUzLjMwIDUwLjU2IDUxLjg0IDQ5Ljk4IDUwLjg4IDQ5LjYxQzQ5LjkxIDQ5LjI0IDQ4LjY3IDQ4Ljc0IDQ4LjEyIDQ4LjUwQzQ3LjU4IDQ4LjI2IDQ1LjgwIDQ3LjYwIDQ0LjE3IDQ3LjAzQzQyLjEzIDQ2LjMyIDQwLjgwIDQ2LjAwIDM5Ljg4IDQ2LjAwQzM4Ljc1IDQ2LjAwIDM4LjM2IDQ2LjEzIDM3LjQ2IDQ2LjgxQzM2LjgyIDQ3LjI5IDM2LjEyIDQ4LjE0IDM1Ljc1IDQ4Ljg4QzM1LjEyIDUwLjEyIDM1LjEyIDUwLjEyIDM1LjA2IDc4LjAyQzM0Ljk5IDEwNS45MiAzNC45OSAxMDUuOTIgMzUuNjIgMTA3LjMzQzM2LjMxIDEwOC45MSAzNy43MCAxMTAuMzggMzkuNDUgMTExLjQwQzQwLjEwIDExMS43OCA0Mi42MiAxMTMuMzkgNDUuMDYgMTE0Ljk4QzQ3LjUwIDExNi41NyA0OS45NyAxMTguMTcgNTAuNTYgMTE4LjUyQzUxLjE1IDExOC44OCA1Mi45OCAxMjAuMDEgNTQuNjIgMTIxLjA0QzU2LjI3IDEyMi4wNiA1OC42OSAxMjMuNTEgNjAuMDAgMTI0LjI0QzYxLjMxIDEyNC45OCA2My4xMSAxMjYuMDYgNjQuMDAgMTI2LjY0QzY2LjIyIDEyOC4wNyA2Ny4wMyAxMjguMjYgNjguMjQgMTI3LjYzWk0xMjIuMDYgMTI2LjI1QzEyNi41NCAxMjYuMjUgMTI2LjU0IDEyNi4yNSAxMjcuMjcgMTI1LjUyQzEyNy44MSAxMjQuOTggMTI4LjAwIDEyNC41NSAxMjguMDAgMTIzLjg3QzEyOC4wMCAxMjMuMDUgMTI3LjczIDEyMi42OCAxMjUuMTggMTIwLjA0QzEyMy42MyAxMTguNDQgMTIxLjkyIDExNi42MiAxMjEuMzkgMTE2LjAwQzEyMC4wNiAxMTQuNDYgMTA4LjMwIDEwMS44NyAxMDUuNzIgOTkuMjNDMTA0LjU3IDk4LjA1IDEwMy4xMiA5Ni43OSAxMDIuNTAgOTYuNDNDMTAxLjg4IDk2LjA3IDEwMC4yMiA5NS40OCA5OC44MCA5NS4xMUM5Ni43NSA5NC41OSA5Ni4xNiA5NC41MSA5NS44NyA5NC43NkM5NS41NSA5NS4wMiA5NS41MCA5Ny4yMCA5NS41MCAxMTAuMjJDOTUuNTEgMTE5LjMwIDk1LjYwIDEyNS41MyA5NS43NCAxMjUuNzVDOTYuMTAgMTI2LjMyIDk5LjI3IDEyNi40NyAxMDguOTggMTI2LjM1QzExMy43MSAxMjYuMzAgMTE5LjYwIDEyNi4yNSAxMjIuMDYgMTI2LjI1Wk04OC42MSAxMTcuOTlDODkuMTEgMTE3LjQzIDg5LjYyIDExNi43MyA4OS43MyAxMTYuNDJDODkuODQgMTE2LjEyIDg5Ljk4IDEwMi4yNiA5MC4wMyA4NS42Mkw5MC4xMiA1NS4zOEw4OS4yMCA1My41MEM4OC4yMyA1MS41NCA4Ny4zMCA1MC4zNSA4Ni4yNSA0OS43MkM4NS40OCA0OS4yNiA4My45NSA0OC43MCA4MS41MCA0Ny45OUM4MC40NyA0Ny42OSA3OC43OCA0Ny4xMSA3Ny43NSA0Ni43MEM3Ni43MiA0Ni4yOSA3NC42MCA0NS41NiA3My4wNSA0NS4wOUM3MS41MCA0NC42MiA2OC45NiA0My42OSA2Ny40MiA0My4wM0M2NS44OCA0Mi4zNiA2NC4wMSA0MS41OCA2My4yNSA0MS4yOUM2Mi40OSA0MS4wMCA2MC4zNiA0MC4xNSA1OC41MCAzOS40MUM1Ni42NCAzOC42NiA1NC42MCAzNy45NyA1My45NiAzNy44N0M1My4yNSAzNy43NyA1Mi40NCAzNy44MSA1MS45MSAzNy45OUM1MC4yNiAzOC41MyA0OS4wMSA0MC41NiA0OS4wMCA0Mi43MEM0OS4wMCA0NC4xNCA0OS41MyA0NC40MyA1OC4wMCA0Ny42NkM1OC44OSA0OC4wMSA2MC41OCA0OC42NiA2MS43NSA0OS4xMkM2Mi45MiA0OS41OCA2NC44OSA1MC4zMSA2Ni4xMiA1MC43NEM2Ny4zNiA1MS4xOCA2OS4wNSA1MS44NiA2OS44OCA1Mi4yNUM3MS40OCA1My4wMyA3My4wNiA1NC40MCA3My42NSA1NS41NUM3My44NiA1NS45NCA3NC4yNCA1Ny4xNiA3NC41MiA1OC4yNUM3NC45OSA2MC4xNyA3NS4wMSA2MS4xOCA3NS4wMSA4Ni45N0M3NS4wMSAxMTMuNDEgNzUuMDIgMTEzLjcxIDc1LjUyIDExNC4zNEM3NS44MCAxMTQuNjkgNzcuMjggMTE1LjYwIDc4LjgyIDExNi4zNUM4My40NyAxMTguNjIgODQuNDcgMTE4Ljk4IDg2LjE2IDExOC45OUM4Ny42NCAxMTkuMDAgODcuNzIgMTE4Ljk2IDg4LjYxIDExNy45OVpNMTA4LjUwIDg4LjUxQzExMC45OCA4Ny43OCAxMTIuOTcgODcuMDAgMTEzLjc5IDg2LjQ1QzExNC4xNSA4Ni4yMCAxMTQuNTMgODYuMDAgMTE0LjYyIDg2LjAwQzExNC43MSA4Ni4wMCAxMTUuOTAgODUuMjYgMTE3LjI2IDg0LjM1QzExOC42MiA4My40NSAxMjAuMzMgODIuMDYgMTIxLjA3IDgxLjI3QzEyNC40NyA3Ny41OSAxMjYuNjkgNzMuODQgMTI3LjUyIDcwLjM2QzEyNy45OCA2OC40MyAxMjguMDQgNjcuNjMgMTI3LjkyIDY0LjUwQzEyNy44MiA2MS43NiAxMjcuNjcgNjAuNTQgMTI3LjI5IDU5LjUwQzEyNS45OCA1NS44NSAxMjQuNjAgNTMuNTcgMTIyLjI4IDUxLjE3QzEyMC40OSA0OS4zMiAxMTkuMDggNDguMjggMTE2Ljg1IDQ3LjE2QzExNC42NCA0Ni4wNSAxMTIuNzMgNDUuMzcgMTA2LjUwIDQzLjQ5QzEwNS40NyA0My4xOCAxMDMuMzkgNDIuNDkgMTAxLjg4IDQxLjk4QzEwMC4zNiA0MS40NiA5Ni44MiA0MC4yOCA5NC4wMCAzOS4zNkM5MS4xOCAzOC40NSA4Ny4zNiAzNy4xNSA4NS41MCAzNi40OUM4My42NCAzNS44MiA4MC43OCAzNC44MSA3OS4xMiAzNC4yNUM2OS45NyAzMS4xMiA2OS41NSAzMS4wMCA2Ny45MiAzMS4wMEM2Ni41NSAzMS4wMCA2Ni4xOCAzMS4xMCA2NS4zMCAzMS43NEM2NC43NCAzMi4xNSA2NC4xNiAzMi43MSA2NC4wMSAzMi45OEM2My44NyAzMy4yNSA2My43NSAzNC4xNCA2My43NSAzNC45N0M2My43NSAzNi4yNyA2My44MyAzNi41MyA2NC4zOSAzNi45NkM2NC43NCAzNy4yNCA2NS44MyAzNy43NyA2Ni44MiAzOC4xNEM2Ny44MSAzOC41MSA2OS4zMCAzOS4wNiA3MC4xMiAzOS4zN0M3MC45NSAzOS42OCA3My4xNCA0MC41MiA3NS4wMCA0MS4yNUM3Ni44NiA0MS45NyA3OS43MiA0My4wNCA4MS4zOCA0My42MkM4My4wMyA0NC4yMCA4NS4yMiA0NS4wNCA4Ni4yNSA0NS40OEM4Ny4yOCA0NS45MiA4OC43MyA0Ni41MyA4OS40OCA0Ni44M0M5MS40NSA0Ny42MiA5My40MyA0OS42OCA5NC4zNiA1MS44OEM5NS4xMCA1My42MiA5NS4xMCA1My42MiA5NS4yNyA2Mi4zOEM5NS4zNyA2Ny4xOSA5NS40NiA3NC45NiA5NS40OCA3OS42NkM5NS41MCA4Ny4yMyA5NS41NSA4OC4yMyA5NS45MSA4OC41M0M5Ni41MyA4OS4wNSA5OC40OSA4OS4yMiAxMDIuNzUgODkuMTRDMTA1Ljg2IDg5LjA4IDEwNi45OSA4OC45NSAxMDguNTAgODguNTFaIi8+PC9zdmc+';
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

// The shelves inside the archive that departments live on.
function shelf_dir(): string {
    return archive_dir() . '/' . trim(settings()['organise']['shelves'] ?? 'Library', '/');
}

// What the helper reported. 'fresh' is false once it has been quiet for a
// minute and a half — the helper stopped, so the list may be out of date.
function helper_volumes(): array {
    $vols = []; $at = 0; $os = '';
    foreach (@file(web_dir() . '/helper-volumes.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if ($f[0] === 'at') $at = (int)$f[1];
        elseif ($f[0] === 'os') $os = $f[1];
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
    if (@file_put_contents("$file.new", $json . "\n") !== false && @rename("$file.new", $file)) return true;
    @unlink("$file.new");
    return false;
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
    if (preg_match('/^(others?|misc(ellaneous)?|general|various|varios|otros?|unsorted|stuff)$/i', $n))
        return "“{$n}” would be a catch-all. Every shoot belongs to a $one — add the one it belongs to instead.";
    if (preg_match('#[/\\\\:*?"<>|]|\.\.#', $n) || $n[0] === '.') return "“{$n}” becomes a folder name, so it cannot hold / \\ : * ? \" < > | or ..";
    if (mb_strlen($n) > 80) return "“{$n}” is too long for a folder name.";
    foreach ($existing as $e) if (strcasecmp($e, $n) === 0) return "“{$n}” is already in the list.";
    return '';
}

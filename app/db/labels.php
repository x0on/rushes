<?php
// labels.php — what each file is, from its name, its folders and its size:
// Deliverable, Stock footage, Music, Sound effects, AI-generated, Photo, Design …
// Rules only, no AI, and nothing is moved: a label sits beside the file, where
// the editor left it, so no project loses a link. Each label keeps its reason.
// Tried first on a real drive of every kind of media (HOW-IT-WORKS.md → Labels).
//
// Kept by path in a table of their own: a file new to the catalogue, or moved
// to a new path, simply has no label yet and gets one at the next refresh.
require_once __DIR__ . '/schema.php';

const LABEL_VIDEO  = ['mp4', 'mov', 'mxf', 'mts', 'm4v', 'avi', 'mkv'];
const LABEL_AUDIO  = ['wav', 'mp3', 'aif', 'aiff', 'm4a', 'aac', 'flac'];
const LABEL_RAW    = ['nef', 'cr2', 'cr3', 'arw', 'dng', 'raf', 'orf', 'rw2'];
const LABEL_IMAGE  = ['jpg', 'jpeg', 'png', 'heic', 'tif', 'tiff', 'webp', 'gif'];
const LABEL_DESIGN = ['psd', 'ai', 'indd', 'eps', 'svg', 'afdesign', 'sketch', 'fig'];
const LABEL_RULES  = '4';   // raise when the rules change: every label is made again
const LABEL_PROJ   = ['prproj', 'prin', 'aep', 'aepx', 'drp', 'drx', 'sesx', 'fcpxml'];

// What Search shows under each name (find.php's rail), and which labels each takes in
const LABEL_SHOWN = [
    'deliverables'     => ['deliverable'],
    'library'          => ['stock', 'music', 'sfx', 'template', 'stock_graphics'],
    'library/stock'    => ['stock'], 'library/music' => ['music'], 'library/sfx' => ['sfx'],
    'library/templates'=> ['template'], 'library/graphics' => ['stock_graphics'],
    'ai'               => ['ai'],
    'photos'           => ['photo'],
    'design'           => ['design', 'design_editable'],
    'voiceover'        => ['voiceover'],
    'recordings'       => ['recording'],
    'made'             => ['made_here'],
    'camera'           => ['camera'],
];

// Versions of one piece: "Promo.mp4", "Promo v2.mp4", "Promo_1.mp4", "Promo FINAL.mp4",
// "Promo ENG.mp4" and "Promo SPA.mp4" are one video, shown once with how many there are.
// Only what is made here is grouped (a camera's C0001, C0002 are different takes), and
// only beside each other: the same folder, or anywhere under the same Output folder.
// Tried on 1,984 finished videos of a real archive: 354 pieces had versions, none wrongly joined.
const LABEL_VERSIONED = ['deliverable', 'deliverable_reused', 'made_here', 'design', 'design_editable', 'voiceover', 'project'];
// A backup an app saved by itself: "Central Park_20250520T124759.149073.sesx" (Audition),
// "Promo-2024-03-12_10-11-22.prproj" (an auto-save). The same piece, behind the real one.
const VERSION_STAMP = '/[\s_-]*\d{4}-?\d{2}-?\d{2}[t_ -]?\d{2}[-:.]?\d{2}[-:.]?\d{2}(\.\d+)?$/u';
const VERSION_TAIL = [
    VERSION_STAMP,
    '/[\s_-]*(v|ver|version)\s*\.?\d+[a-z]?$/u',                                    // v2, V3, -V10, version 4
    '/[\s_-]*(final|update\d*|revised|rev\d*|fixed|fix|new|copy|alt|edit(ed)?|export|render)$/u',
    '/[\s_-]*(eng|esp|spa|english|spanish|espanol|español)$/u',                      // the same piece in another language
    // the same piece in another shape or for another place: 16:9 and 9:16, YouTube and Instagram, broadcast and web
    '/[\s_-]*(\d{3,4}p|4k|uhd|hd|vertical|horizontal|square|\d{1,2}x\d{1,2}|youtube|yt|instagram|insta|ig|reels?|tiktok|facebook|fb|web|br[o]?adcast|delivery)$/u',
    '/\.[a-z0-9]{1,4}$/u',                                                           // a second extension: "Promo.mov.mxf"
    '/_\d{1,2}$/u',                                                                  // Promo_1: exported again
    '/(?<=[a-z])\d{1,2}$/u',                                                         // February2 (but "Our Town 10" is an episode)
    '/(?<=\d)[b-e]$/u',                                                              // Testimonial 5b
    '/\(\d+\)$/u', '/[\s_.-]+$/u',
];
/** [key, rank] for a file that can have versions, or ['', 0]. The highest rank is the newest. */
function version_of(string $path, string $label): array {
    if (!in_array($label, LABEL_VERSIONED, true)) return ['', 0];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $n = trim(preg_replace('/\s+/u', ' ', mb_strtolower(pathinfo($path, PATHINFO_FILENAME))));
    $rank = preg_match(VERSION_STAMP, $n) ? -1 : (preg_match('/final/u', $n) ? 1000 : 0);   // a backup never stands for the piece
    if ($rank === 0 && preg_match('/(?:v|ver|version|_)\s*\.?(\d+)[a-z]?$|(?<=[a-z])(\d{1,2})$/u', $n, $m)) $rank = (int)($m[1] ?: $m[2]);
    elseif ($rank === 0 && preg_match('/\d([b-e])$/u', $n, $m)) $rank = ord($m[1]) - 96;
    do { $was = $n; foreach (VERSION_TAIL as $t) $n = trim(preg_replace($t, '', $n)); } while ($n !== $was);
    if ($n === '') return ['', 0];
    // beside each other: the same folder, or under the same Output, Backup or Auto-Save folder
    $dir = dirname($path);
    $o = preg_match('#/(outputs?|backups?|adobe premiere pro auto-save|auto-?saves?)(/|$)#i', $dir . '/', $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false;
    $kind = in_array($ext, LABEL_VIDEO, true) ? 'v' : (in_array($ext, LABEL_AUDIO, true) ? 'a' : (in_array($ext, LABEL_PROJ, true) ? 'p' . $ext : 'i'));
    return [strtolower($o === false ? $dir : substr($dir, 0, $o)) . "	$kind	$n", $rank];
}

/** [label, why] for one file. $outputs: "name\tbytes" of every file in an Output folder (a copy reused elsewhere). */
function label_of(string $path, int $bytes, array $outputs): array {
    $name = basename($path); $low = strtolower($path); $lname = strtolower($name);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $dirs = strtolower(dirname($path)) . '/';
    if (str_starts_with($name, '._') || in_array($ext, ['cube', '3dl', 'look'], true)) return ['', 'ignored: a macOS leftover or a LUT'];
    if (preg_match('#/(adobe premiere pro (audio|video) previews|cache|peak files|proxies)/|\.prv/#', $dirs)
        || in_array($ext, ['pek', 'pkf', 'cfa', 'lrf', 'ims'], true)) return ['cache', 'a cache, rebuilt by itself'];
    // Only an Output folder says "finished": a render beside it is a part made for the edit
    if (preg_match('#/outputs?/#', $dirs)) return ['deliverable', 'in an Output folder'];
    if (str_contains($low, 'openart') || preg_match('/^(runway|kling|sora|pika|luma|hailuo|midjourney|higgsfield|minimax|seedance)[-_ ]/', $lname))
        return ['ai', 'named by an AI tool (' . (str_contains($low, 'openart') ? 'OpenArt' : 'its name') . ')'];
    $sfx = (bool)preg_match('#sfx|sound ?(fx|effects?)|foley|efectos#', $dirs . $lname);
    // Envato Elements: "what-it-shows-2026-10-02-21-34-17-utc" on a file or its folder; the type says what it is
    $site = preg_match('/-20\d\d(-\d\d){5}-utc/', $low) ? 'an Envato name (…-utc)'
          : (preg_match('/^(adobestock|shutterstock|istock|gettyimages|pexels|pixabay|storyblocks|videoblocks|pond5|motionarray|mixkit|videvo|artgrid)[-_ ]/', $lname)
             || preg_match('/bearstockmusic|audiojungle|artlist|epidemic ?sound|musicbed|soundstripe|premiumbeat/', $lname) ? 'a stock site\'s name' : '');
    if ($site) {
        if (in_array($ext, LABEL_VIDEO, true)) return ['stock', $site];
        if (in_array($ext, LABEL_AUDIO, true)) return [$sfx ? 'sfx' : 'music', $site];
        if (in_array($ext, ['aep', 'aepx', 'mogrt', 'prproj', 'zip'], true)) return ['template', $site];
        if (in_array($ext, LABEL_IMAGE, true) || in_array($ext, LABEL_DESIGN, true)) return ['stock_graphics', $site];
        return ['stock_pack', $site . ', part of the pack'];
    }
    if (preg_match('/shared_screen_with_|gallery_view|active_speaker|^zoom_|^screen recording 20/', $lname) || str_contains($dirs, '/zoom/'))
        return ['recording', 'a Zoom or screen recording'];
    if (str_contains($low, 'auto-save') || in_array($ext, LABEL_PROJ, true)) return ['project', 'a project file'];
    if ($ext === 'mogrt') return ['template', 'a motion graphics template'];
    if (in_array($ext, ['cos', 'cop', 'cof', 'cot', 'comask'], true) || str_contains($dirs, '/captureone/')) return ['photo_edit', 'Capture One'];
    if (in_array($ext, LABEL_RAW, true)) return ['photo', 'a camera raw (.' . $ext . ')'];
    if (in_array($ext, LABEL_DESIGN, true)) return ['design_editable', 'an editable design file (.' . $ext . ')'];
    $camera = '/^([a-z]\d{3}c\d{3}|c\d{4}|dji_\d+|img_\d+|mvi_\d+|g[hxop]\w*\d{4}|gopr\d+|pxl_\d+|mah\d+|clip\d+|\d{8}_c\d+|_?dsc_?\d+|p\d{7})/';
    if (in_array($ext, LABEL_IMAGE, true)) {
        if (preg_match('/\.still\d+\.[a-z]+$/', $lname)) return ['frame_grab', 'a still saved from the edit'];
        if (str_contains($lname, 'logo')) return ['design', 'a logo'];         // the file's own name: a logo-reveal pack's frames are not logos
        if (in_array($ext, ['jpg', 'jpeg', 'heic'], true) && (preg_match($camera, $lname) || preg_match('#/(photos?|fotos?)[^/]*/#', $dirs)))
            return ['photo', 'a camera name or a photos folder'];
        return ['image', 'not sure: a photo or a graphic'];
    }
    if (in_array($ext, LABEL_AUDIO, true)) {
        if (preg_match('#voice ?over|/vo/|locuci|narrat#', $dirs)) return ['voiceover', 'in a voice over folder'];
        if ($sfx) return ['sfx', 'in a sound effects folder'];
        if (preg_match('#/(audio|music|musica|música|stock library/music)/#', $dirs)) return ['music', 'in an audio or music folder'];
        return ['audio', 'not sure: no folder says what it is'];
    }
    if (in_array($ext, LABEL_VIDEO, true)) {
        if (isset($outputs[strtolower($name) . "\t" . $bytes])) return ['deliverable_reused', 'the same file as a finished one: ' . $outputs[strtolower($name) . "\t" . $bytes]];
        if (str_contains($dirs, '/stock')) return ['stock', 'in a stock folder'];
        if (preg_match($camera, $lname) || preg_match('#/(clips\d*|contents|private|dcim|dji[_ ]?\w*|footage|\(footage\))/#', $dirs))
            return ['camera', 'a camera name or a camera folder'];
        return ['made_here', 'a video without a camera name, outside Output: a part made for the edit'];
    }
    return ['other', '.' . ($ext ?: 'no extension')];
}

/** Labels for every file that has none yet; labels of files gone are dropped. -> how many were added */
function labels_refresh(?SQLite3 $db = null): int {
    $db = $db ?? db();
    $db->exec('CREATE TABLE IF NOT EXISTS labels (path TEXT PRIMARY KEY, label TEXT, why TEXT, vkey TEXT, vrank INTEGER)');
    // Rules changed since the labels were made (or the table predates versions): all made again
    if (meta_get('label_rules', '') !== LABEL_RULES) {
        $cols = []; $c = $db->query('PRAGMA table_info(labels)');
        while ($r = $c->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];
        foreach (['vkey' => 'TEXT', 'vrank' => 'INTEGER'] as $k => $t) if (!in_array($k, $cols, true)) $db->exec("ALTER TABLE labels ADD COLUMN $k $t");
        $db->exec('DELETE FROM labels');
        meta_set('label_rules', LABEL_RULES);
    }
    $db->exec('CREATE INDEX IF NOT EXISTS i_labels_label ON labels (label)');
    $db->exec('CREATE INDEX IF NOT EXISTS i_labels_vkey ON labels (vkey)');
    $new = $db->query('SELECT f.path, f.bytes FROM files f LEFT JOIN labels l ON l.path = f.path WHERE l.path IS NULL');
    $todo = [];
    while ($r = $new->fetchArray(SQLITE3_NUM)) $todo[] = $r;
    if ($todo) {
        $outputs = [];
        $o = $db->query("SELECT path, bytes FROM files WHERE lower(path) LIKE '%/output/%' OR lower(path) LIKE '%/outputs/%'");
        while ($r = $o->fetchArray(SQLITE3_NUM)) $outputs[strtolower(basename($r[0])) . "\t" . (int)$r[1]] = $r[0];
        $ins = $db->prepare('INSERT OR REPLACE INTO labels (path, label, why, vkey, vrank) VALUES (?, ?, ?, ?, ?)');
        $db->exec('BEGIN');
        foreach ($todo as [$p, $b]) {
            [$l, $why] = label_of($p, (int)$b, $outputs);
            [$vk, $vr] = version_of($p, $l);
            $ins->bindValue(1, $p); $ins->bindValue(2, $l); $ins->bindValue(3, $why);
            $ins->bindValue(4, $vk === '' ? null : $vk); $ins->bindValue(5, $vr);
            $ins->execute(); $ins->reset();
        }
        $db->exec('COMMIT');
    }
    $db->exec('DELETE FROM labels WHERE path NOT IN (SELECT path FROM files)');
    return count($todo);
}

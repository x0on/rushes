<?php
// One request to db/helper.php (or another door: the 6th argument), as the web
// server would make it. Used by test_helper.sh, because the doors end each
// answer with exit.
[$_, $app, $method, $get, $post, $door] = $argv + [null, '', 'GET', '{}', '{}', 'db/helper.php'];
if ($method === 'SEED' || $method === 'ITEMS') {        // set up, or look at, a transfer
    require "$app/db/transfers.php"; db_init();
    if ($method === 'SEED') {
        $paths = json_decode($get, true);
        transfer_select($paths, array_fill_keys($paths, [1, 10]));
        file_put_contents(web_dir() . '/ingest-queue.tsv', implode('', array_map(fn($p) => "copy\t$p\n", $paths)));
    } else echo json_encode(array_column(transfer_latest()['items'], 'phase', 'source'), JSON_UNESCAPED_SLASHES);
    exit;
}
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['HTTP_HOST'] = 'nas.test';
if (getenv('ORIGIN') !== false) $_SERVER['HTTP_ORIGIN'] = getenv('ORIGIN');
if (getenv('SIGNED')) { @session_start(); $_SESSION['rushes_in'] = true; }      // as if signed in to Manage
$_GET = json_decode($get, true) ?: []; $_POST = json_decode($post, true) ?: [];
include "$app/$door";

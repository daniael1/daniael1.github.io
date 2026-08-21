<?php
/**
 * save.php
 *
 * Receives the RSVP form POST from rsvp.html and writes a new row
 * directly into recorded.html, in the same folder.
 *
 * Requirements on your hosting:
 *  - PHP support (most shared hosting has this by default).
 *  - This file, rsvp.html, and recorded.html all sit in the SAME folder.
 *  - recorded.html must be writable by PHP. If you get a permission error,
 *    set its file permissions to 664 (or 666 if 664 doesn't work) via your
 *    hosting file manager / FTP client.
 *
 * DIAGNOSTIC TIP: open this file's URL directly in a browser (no form needed).
 * You should see plain JSON text like {"result":"error","message":"Missing required fields."}
 *  - If instead you see the raw PHP source code as text, your host is not running PHP
 *    for this file (check the file was uploaded as .php, not renamed/converted).
 *  - If you see a blank white page or a generic server error page (500/403), that's a
 *    server-side problem (permissions, PHP error) — check your host's error log.
 *  - If you see JSON but with extra text/HTML around it (ads, banners), your free host
 *    is injecting content into every page, which breaks this form; a different host is needed.
 */

// Never let PHP warnings/errors print into the response — that corrupts the JSON output
// and is the most common cause of "Something went wrong" even when the real error is
// something specific and fixable (e.g. a permissions warning from fopen()).
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Safety net: if something fatal still happens, still return valid JSON instead of
// a broken/blank response.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['result' => 'error', 'message' => 'Server error: ' . $err['message']]);
    }
});

header('Content-Type: application/json');

// --- Spam honeypot: real visitors never fill this hidden field ---
if (!empty($_POST['website'])) {
    // Pretend success so bots don't know they were caught.
    echo json_encode(['result' => 'success']);
    exit;
}

// --- Collect + validate ---
$name      = isset($_POST['name']) ? trim($_POST['name']) : '';
$attending = isset($_POST['attending']) ? trim($_POST['attending']) : '';
$guests    = isset($_POST['guests']) ? trim($_POST['guests']) : '0';
$contact   = isset($_POST['contact']) ? trim($_POST['contact']) : '';
$message   = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($name === '' || $attending === '' || $contact === '') {
    http_response_code(400);
    echo json_encode(['result' => 'error', 'message' => 'Missing required fields.']);
    exit;
}

$recordedFile = __DIR__ . '/recorded.html';

if (!file_exists($recordedFile)) {
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => 'recorded.html was not found next to save.php.']);
    exit;
}

// --- Build the new table row (escaped so guest input can't break the page / inject scripts) ---
$timestamp = date('Y-m-d H:i:s');
$esc = function ($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
};

$row = "<tr>"
     . "<td>" . $esc($timestamp) . "</td>"
     . "<td>" . $esc($name) . "</td>"
     . "<td>" . $esc($attending) . "</td>"
     . "<td>" . $esc($guests) . "</td>"
     . "<td>" . $esc($contact) . "</td>"
     . "<td>" . $esc($message) . "</td>"
     . "</tr>\n<!-- RSVP_ROWS -->";

// --- Write into recorded.html, replacing the marker with (new row + marker) ---
// Using flock() so two guests submitting at the same instant don't corrupt the file.
if (!is_writable($recordedFile)) {
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => 'recorded.html is not writable by the server — set its file permissions to 664 (or 666) via your hosting file manager.']);
    exit;
}

$fp = @fopen($recordedFile, 'r+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => 'Could not open recorded.html — check file permissions.']);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => 'Could not lock recorded.html for writing.']);
    exit;
}

$contents = stream_get_contents($fp);

if (strpos($contents, '<!-- RSVP_ROWS -->') === false) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => 'recorded.html is missing its marker comment. Do not hand-edit that file.']);
    exit;
}

$newContents = str_replace('<!-- RSVP_ROWS -->', $row, $contents);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, $newContents);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['result' => 'success']);

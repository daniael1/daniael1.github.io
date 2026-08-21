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
 */

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
$fp = fopen($recordedFile, 'r+');
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

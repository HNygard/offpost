<?php
// organizer/src/api/np/np_threads_list.php
// Server-to-server API for norske-postlister.no. Token auth, NOT session auth.
require_once __DIR__ . '/np-api-auth.php';
require_once __DIR__ . '/../../class/NpApiService.php';

header('Content-Type: application/json');
npApiRequireToken();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

// JSON_INVALID_UTF8_SUBSTITUTE: subjects come from MIME-decoded email headers
// (see emailSubjectsByThreadId()), which is attacker-controlled input. Without
// this flag, invalid UTF-8 anywhere in the payload makes json_encode() return
// false, which echoes as an empty string - a hostile subject would silently
// turn the whole feed into an empty (still-200) response for every poller.
echo json_encode(NpApiService::listNpThreads(), JSON_INVALID_UTF8_SUBSTITUTE);

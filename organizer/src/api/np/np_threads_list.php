<?php
// organizer/src/api/np/np_threads_list.php
// Server-to-server API for norske-postlister.no. Token auth, NOT session auth.
require_once __DIR__ . '/np-api-auth.php';
require_once __DIR__ . '/np-api-query.php';
require_once __DIR__ . '/../../class/NpApiService.php';

header('Content-Type: application/json');
npApiRequireTokenOrAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

// Optional filters. `label` is repeatable (?label=postliste&label=postliste:2026-W38)
// and every given label must be present on the thread. PHP's $_GET keeps only
// the last of repeated plain keys, so read the raw query string instead.
// No parameters keeps the original behaviour: every norske_postlister_no thread.
$labels = npApiQueryValues($_SERVER['QUERY_STRING'] ?? '', 'label');
foreach ($labels as $label) {
    if (trim($label) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'label must not be empty']);
        exit;
    }
}
$npEntityId = null;
if (isset($_GET['entity_id_norske_postlister'])) {
    if (!is_string($_GET['entity_id_norske_postlister']) || trim($_GET['entity_id_norske_postlister']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'entity_id_norske_postlister must be a non-empty string']);
        exit;
    }
    $npEntityId = trim($_GET['entity_id_norske_postlister']);
}

// JSON_INVALID_UTF8_SUBSTITUTE: subjects come from MIME-decoded email headers
// (see emailSubjectsByThreadId()), which is attacker-controlled input. Without
// this flag, invalid UTF-8 anywhere in the payload makes json_encode() return
// false, which echoes as an empty string - a hostile subject would silently
// turn the whole feed into an empty (still-200) response for every poller.
echo json_encode(NpApiService::listNpThreads($labels, $npEntityId), JSON_INVALID_UTF8_SUBSTITUTE);


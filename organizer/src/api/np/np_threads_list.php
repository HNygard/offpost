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

echo json_encode(NpApiService::listNpThreads());

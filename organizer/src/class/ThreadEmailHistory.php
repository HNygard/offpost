<?php

require_once __DIR__ . '/Database.php';

class ThreadEmailHistory {
    public function logAction($threadId, $emailId, $action, $userId = null, $details = null) {
        $sql = "INSERT INTO thread_email_history (thread_id, email_id, action, user_id, details) VALUES (?, ?, ?, ?, ?)";
        $params = [$threadId, $emailId, $action, $userId, $details ? json_encode($details) : null];

        return Database::execute($sql, $params);
    }

    public function getHistoryForEmail($threadId, $emailId) {
        $sql = "SELECT * FROM thread_email_history WHERE thread_id = ? AND email_id = ? ORDER BY created_at DESC";
        return Database::query($sql, [$threadId, $emailId]);
    }

    public function getHistoryForThread($threadId) {
        $sql = "SELECT * FROM thread_email_history WHERE thread_id = ? ORDER BY created_at DESC";
        return Database::query($sql, [$threadId]);
    }

    public function formatActionForDisplay($action, $details) {
        switch ($action) {
            case 'received':
                return 'Email received';
            case 'classified':
                $details = json_decode($details, true);
                return 'Classified as ' . ($details['status_type'] ?? 'unknown') . 
                       ': ' . ($details['status_text'] ?? '');
            case 'auto_classified':
                $details = json_decode($details, true);
                return 'Auto-classified as ' . ($details['status_type'] ?? 'unknown') . 
                       ': ' . ($details['status_text'] ?? '');
            case 'sent':
                return 'Email sent';
            case 'ignored':
                $details = json_decode($details, true);
                return 'Email ' . ($details['ignored'] ? 'ignored' : 'unignored');
            default:
                return 'Unknown action';
        }
    }

    public function formatHistoryEntry($entry) {
        $action = $this->formatActionForDisplay($entry['action'] ?? '', $entry['details'] ?? null);
        $date = isset($entry['created_at']) ? new DateTime($entry['created_at']) : new DateTime();
        $formattedDate = $date->format('Y-m-d H:i:s');

        return [
            'action' => $action ?: 'Unknown action',
            'user' => $entry['user_id'] ?? null,
            'date' => $formattedDate
        ];
    }
}

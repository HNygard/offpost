<?php

require_once __DIR__ . '/../../../tests/bootstrap.php';
require_once __DIR__ . '/../../../class/Thread.php';
require_once __DIR__ . '/../../../class/ThreadEmail.php';
require_once __DIR__ . '/../../../class/ThreadDatabaseOperations.php';
require_once __DIR__ . '/../../../class/common.php';
require_once __DIR__ . '/../../../class/ThreadAuthorization.php';

/**
 * Helper class to create test data for e2e tests
 */
class E2ETestSetup {
    /**
     * Create a test thread with emails and attachments.
     * 
     * Files created here are deleted by create-dev-data.php when docker container is restarted. This so that they
     * are kept in files and database for any debugging of tests.
     * 
     * @param string $entityId The entity ID to create the thread for
     * @param string $userId The user ID to grant access to
     * @return array Returns an array with thread and email data
     */
    public static function createTestThread($entityId = '000000000-test-entity-development', $userId = 'dev-user-id') {

        // Create a unique ID for this test
        $uniqueId = uniqid();
        
        // Create thread in database
        $thread = new Thread();
        $thread->title = 'E2ETest Thread - ' . $uniqueId;
        $thread->my_name = 'Test User';
        $thread->my_email = 'test' . $uniqueId . '@example.com';
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        
        // Create thread in the system
        $threadOps = new ThreadDatabaseOperations();
        $createdThread = $threadOps->createThread($entityId, $thread, $userId);
        
        // Create test email
        $email_time = mktime(12, 0, 0, 1, 1, 2021);
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, id_old, timestamp_received, datetime_received, ignore, email_type, status_type, status_text, content) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $createdThread->id,
                date('Y-m-d_His', $email_time) . ' - IN',
                date('Y-m-d H:i:s', $email_time),
                date('Y-m-d H:i:s', $email_time),
                'f',
                'IN',
                'unknown',
                'Uklassifisert',
                'Test email content'
            ]
        );
        
        // Create test attachment
        $attachmentId = Database::queryValue(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, status_type, status_text, size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $emailId,
                'test.pdf',
                'test.pdf',
                'pdf',
                date('Y-m-d_His', $email_time) . ' - IN - att 1-754dc77d28e62763c4916970d595a10f.pdf',
                'unknown',
                'uklassifisert-dok',
                1024
            ]
        );
        
        // Create thread directory and files
        $threadDir = __DIR__ . '/../../../../../data/threads/' . $entityId . '/' . $createdThread->id;
        if (!file_exists($threadDir)) {
            mkdir($threadDir, 0777, true);
        }
        
        // Create email file
        $emailFile = $threadDir . '/' . date('Y-m-d_His', $email_time) . ' - IN.eml';
        file_put_contents($emailFile, "From: sender@example.com\r\nTo: {$thread->my_email}\r\nSubject: Test Email\r\n\r\nThis is a test email");
        
        // Create email JSON file
        $emailJsonFile = $threadDir . '/' . date('Y-m-d_His', $email_time) . ' - IN.json';
        file_put_contents($emailJsonFile, json_encode([
            'subject' => 'Hello world.',
            'date' => 'Fri, 24 Nov 2023 23:35:09 +0100',
            'mailHeaders' => [
                'from' => 'sender@example.com',
                'to' => $thread->my_email,
                'subject' => 'Test Email'
            ],
            'body' => 'This is a test email'
        ]));
        
        // Create attachment file
        $attachmentFile = $threadDir . '/' . date('Y-m-d_His', $email_time) . ' - IN - att 1-754dc77d28e62763c4916970d595a10f.pdf';
        file_put_contents($attachmentFile, "%PDF-1.4
%âãÏÓ

1 0 obj
  << /Type /Catalog
     /Pages 2 0 R
  >>
endobj

2 0 obj
  << /Type /Pages
     /Kids [3 0 R]
     /Count 1
  >>
endobj

3 0 obj
  << /Type /Page
     /Parent 2 0 R
     /MediaBox [0 0 612 792]
     /Contents 4 0 R
     /Resources << >>
  >>
endobj

4 0 obj
  << /Length 22 >>
stream
(Hello, PDF!) Tj
endstream
endobj

xref
0 5
0000000000 65535 f 
0000000010 00000 n 
0000000060 00000 n 
0000000110 00000 n 
0000000160 00000 n 

trailer
  << /Root 1 0 R
     /Size 5
  >>
startxref
210
%%EOF");
        
        // Grant access to the user
        $thread->addUser($userId, true);

        // Return the created data
        return [
            'thread' => $createdThread,
            'email_id' => $emailId,
            'attachment_id' => $attachmentId,
            'entity_id' => $entityId
        ];
    }
    
    /**
     * Clean up test data
     * 
     * @param string $threadId The thread ID to clean up
     * @param string $entityId The entity ID
     */
    public static function cleanupTestThread($threadId, $entityId = 'test-entity-development') {
        // Delete thread from database - order matters due to foreign key constraints
        Database::execute("DELETE FROM thread_authorizations WHERE thread_id = ?", [$threadId]);
        Database::execute("DELETE FROM thread_email_attachments WHERE email_id IN (SELECT id FROM thread_emails WHERE thread_id = ?)", [$threadId]);
        Database::execute("DELETE FROM thread_email_history WHERE email_id::text IN (SELECT id::text FROM thread_emails WHERE thread_id = ?)", [$threadId]);
        Database::execute("DELETE FROM thread_emails WHERE thread_id = ?", [$threadId]);
        Database::execute("DELETE FROM thread_history WHERE thread_id = ?", [$threadId]);
        Database::execute("DELETE FROM threads WHERE id = ?", [$threadId]);
        
        // Delete thread directory
        $threadDir = THREADS_DIR . '/' . $entityId . '/' . $threadId;
        if (file_exists($threadDir)) {
            self::removeDirectory($threadDir);
        }
    }
    
    /**
     * Recursively remove a directory
     * 
     * @param string $dir The directory to remove
     */
    private static function removeDirectory($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

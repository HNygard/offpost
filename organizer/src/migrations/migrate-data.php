<?php
require_once __DIR__ . '/../class/Database.php';

class DataMigrator {
    private array $stats = [
        'threads' => 0,
        'emails' => 0,
        'attachments' => 0
    ];
    private int $threadCount = 0;
    private array $migratedThreads = [];
    private string $trackingFile;
    private int $threadLimit;

    public function __construct(int $threadLimit = 50) {
        $this->threadLimit = $threadLimit;
    }

    public function migrate(): void {
        echo "\nStarting migration with thread limit: {$this->threadLimit}\n";
        echo "----------------------------------------\n\n";
        
        $threadsDir = '/organizer-data/threads/';
        $this->trackingFile = $threadsDir . 'db-migration.json';
        
        // Load previously migrated threads
        if (file_exists($this->trackingFile)) {
            $this->migratedThreads = json_decode(file_get_contents($this->trackingFile), true) ?: [];
        }
        
        $jsonFiles = glob($threadsDir . 'threads-*.json');
        Database::beginTransaction();
        try {
            foreach ($jsonFiles as $jsonFile) {
                $this->migrateThreadsFile($jsonFile);
            }
            Database::commit();
            // Update tracking file after successful commit
            file_put_contents($this->trackingFile, json_encode($this->migratedThreads, JSON_PRETTY_PRINT));
            $this->printStats();
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function migrateThreadsFile(string $jsonFile): void {
        echo "- Processing threads file: $jsonFile\n";
        $content = file_get_contents($jsonFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: $jsonFile");
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['threads'])) {
            throw new RuntimeException("Invalid JSON structure in file: $jsonFile");
        }

        // Extract entity_id from filename (e.g., "threads-0418-nord-odal-kommune.json" -> "0418-nord-odal-kommune")
        $entityId = str_replace(['threads-', '.json'], '', basename($jsonFile));

        foreach ($data['threads'] as $threadData) {
            $this->migrateThread($threadData, $entityId);
        }
    }

    private function migrateThread(array $threadData, string $entityId): void {
        // Skip if already migrated
        if (in_array($threadData['id'], $this->migratedThreads)) {
            echo "-- Skipping already migrated thread: {$threadData['id']}\n";
            return;
        }

        // Check if we've reached thread limit
        if ($this->threadCount >= $this->threadLimit) {
            echo "Reached thread limit of {$this->threadLimit} - committing and exiting\n";
            Database::commit();
            // Update tracking file after successful commit
            file_put_contents($this->trackingFile, json_encode($this->migratedThreads, JSON_PRETTY_PRINT));
            $this->printStats();
            exit(0);
        }

        echo "-- Processing thread: {$threadData['id']}\n";
        $this->threadCount++;
        echo "-- Thread data: " . json_encode($threadData, JSON_PRETTY_PRINT) . "\n";
        
        // Convert labels from metadata if exists
        $labels = null; // Default to NULL for no labels
        if (isset($threadData['labels']) && is_array($threadData['labels'])) {
            // Format labels as PostgreSQL array string with proper quoting and spacing
            $escapedLabels = array_map(function($label) {
                return '"' . str_replace('"', '""', trim($label)) . '"';
            }, $threadData['labels']);
            $labels = sprintf('{%s}', implode(', ', $escapedLabels));
        }

        // Generate a random UUID
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        // Insert thread with UUID and original ID
        $sql = "INSERT INTO threads (id, id_old, entity_id, title, my_name, my_email, labels, sent, archived, public, sent_comment) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $uuid,
            $threadData['id'],
            $entityId,
            $threadData['title'] ?? null,
            $threadData['my_name'],
            $threadData['my_email'],
            $labels,
            isset($threadData['sent']) && $threadData['sent'] ? 't' : 'f',
            isset($threadData['archived']) && $threadData['archived'] ? 't' : 'f',
            isset($threadData['public']) && $threadData['public'] ? 't' : 'f',
            $threadData['sent_comment'] ?? null
        ];

        echo "-- SQL params: " . json_encode($params, JSON_PRETTY_PRINT) . "\n";
        Database::execute($sql, $params);
        $this->stats['threads']++;
        
        // Add to migrated threads array (will be written to tracking file after commit)
        $this->migratedThreads[] = $threadData['id'];

        // Migrate emails
        $threadDir = "/organizer-data/threads/{$entityId}/{$threadData['id']}";
        if (is_dir($threadDir) && isset($threadData['emails'])) {
            $this->migrateEmails($uuid, $threadDir, $threadData['emails']);
        }
    }

    private function migrateEmails(string $threadId, string $threadDir, array $threadEmails): void {
        echo "--- Processing emails from thread data\n";
        foreach ($threadEmails as $emailData) {
            echo "---- Processing email: {$emailData['id']}\n";

            // Read email content from .eml file
            $emlFile = $threadDir . '/' . $emailData['id'] . '.eml';
            if (!file_exists($emlFile)) {
                echo "Warning: Email file not found: $emlFile\n";
                continue;
            }

            // Read and clean email content
            $emailContent = file_get_contents($emlFile);
            $encoding = mb_detect_encoding($emailContent, ['UTF-8', 'ISO-8859-1', 'ASCII']);
            if ($encoding !== 'UTF-8') {
                $emailContent = mb_convert_encoding($emailContent, 'UTF-8', $encoding);
            }
            $emailContent = iconv('UTF-8', 'UTF-8//IGNORE', $emailContent);

            echo "---- Processing email file: $emlFile\n";

            // Insert email using data from thread JSON
            $sql = "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, 
                    email_type, status_type, status_text, description, answer, content, ignore) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id";
            
            $params = [
                $threadId,
                date('Y-m-d H:i:s', $emailData['timestamp_received']),
                $emailData['datetime_received'],
                $emailData['email_type'],
                $emailData['status_type'] ?? null,
                $emailData['status_text'] ?? null,
                $emailData['description'] ?? null,
                $emailData['answer'] ?? null,
                $emailContent,
                isset($emailData['ignore']) ? ($emailData['ignore'] ? 't' : 'f') : 'f'
            ];

            $emailId = Database::queryValue($sql, $params);
            $this->stats['emails']++;

            // Migrate attachments if present
            if (isset($emailData['attachments']) && is_array($emailData['attachments'])) {
                foreach ($emailData['attachments'] as $attachment) {
                    $this->migrateAttachment($emailId, $threadDir, $attachment);
                }
            }
        }
    }

    private function migrateAttachment(int $emailId, string $threadDir, array $attachment): void {
        $attachmentPath = $threadDir . '/' . $attachment['location'];
        echo "----- Processing attachment: $attachmentPath\n";
        if (!file_exists($attachmentPath)) {
            return;
        }

        // Extract filename without path
        $filename = basename($attachmentPath);
        
        $sql = "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, 
                status_type, status_text, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $emailId,
            $attachment['name'] ?? $filename,
            $filename,
            $attachment['filetype'] ?? 'application/octet-stream',
            $attachmentPath,
            $attachment['status_type'] ?? null,
            $attachment['status_text'] ?? null,
            filesize($attachmentPath)
        ];

        Database::execute($sql, $params);
        $this->stats['attachments']++;
    }

    private function printStats(): void {
        echo "Migration completed successfully:\n";
        echo "- Threads migrated: {$this->stats['threads']}\n";
        echo "- Emails migrated: {$this->stats['emails']}\n";
        echo "- Attachments migrated: {$this->stats['attachments']}\n";
    }
}

// Get thread limit from command line argument, default to 50 if not specified
$threadLimit = isset($argv[1]) ? (int)$argv[1] : 50;
echo "Command line argument for thread limit: " . (isset($argv[1]) ? $argv[1] : "not set (using default)") . "\n";
echo "Using thread limit: $threadLimit\n\n";

// Run migration
$migrator = new DataMigrator($threadLimit);
$migrator->migrate();

<?php
require_once __DIR__ . '/../class/Database.php';

class DataMigrator {
    private array $stats = [
        'threads' => 0,
        'emails' => 0,
        'attachments' => 0
    ];

    public function migrate(): void {
        $threadsDir = '/organizer-data/threads/';
        $jsonFiles = glob($threadsDir . 'threads-*.json');

        Database::beginTransaction();
        try {
            foreach ($jsonFiles as $jsonFile) {
                $this->migrateThreadsFile($jsonFile);
            }
            Database::commit();
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
        echo "-- Processing thread: {$threadData['id']}\n";
        echo "-- Thread data: " . json_encode($threadData, JSON_PRETTY_PRINT) . "\n";
        
        // Convert labels from metadata if exists
        $labels = '{}'; // Default empty array in PostgreSQL
        if (isset($threadData['labels']) && is_array($threadData['labels'])) {
            $labels = '{' . implode(',', array_map(function($label) {
                return $label;
            }, $threadData['labels'])) . '}';
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

        // Migrate emails
        $threadDir = "/organizer-data/threads/{$entityId}/{$threadData['id']}";
        if (is_dir($threadDir)) {
            $this->migrateEmails($uuid, $threadDir);
        }
    }

    private function migrateEmails(string $threadId, string $threadDir): void {
        echo "--- Processing emails in directory: $threadDir\n";
        $files = glob($threadDir . '/*.eml');
        foreach ($files as $emlFile) {
            // Extract email data from filename
            $filename = basename($emlFile);
            preg_match('/(\d{4}-\d{2}-\d{2}_\d{6}) - (IN|OUT)/', $filename, $matches);
            
            if (count($matches) < 3) {
                echo "Warning: Could not parse filename format: $filename\n";
                continue;
            }

            $direction = $matches[2];
            $dateStr = $matches[1];
            $date = DateTime::createFromFormat('Y-m-d_His', $dateStr);
            
            if (!$date) {
                echo "Warning: Could not parse date from filename: $dateStr\n";
                continue;
            }

            // Try to get additional metadata from json if it exists
            $jsonFile = str_replace('.eml', '.json', $emlFile);
            $emailData = [];
            if (file_exists($jsonFile)) {
                $emailData = json_decode(file_get_contents($jsonFile), true) ?: [];
            }

            // Read email content
            $emailContent = file_get_contents($emlFile);
            
            // Convert to UTF-8 if needed
            $encoding = mb_detect_encoding($emailContent, ['UTF-8', 'ISO-8859-1', 'ASCII']);
            if ($encoding !== 'UTF-8') {
                $emailContent = mb_convert_encoding($emailContent, 'UTF-8', $encoding);
            }
            
            // Clean invalid UTF-8 sequences
            $emailContent = iconv('UTF-8', 'UTF-8//IGNORE', $emailContent);

            echo "---- Processing email file: $emlFile\n";

            // Insert email
            $sql = "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, 
                    email_type, status_type, status_text, description, answer, content) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id";
            
            $params = [
                $threadId,
                $date->format('Y-m-d H:i:s'),
                $date->format('Y-m-d H:i:s'),
                $direction, // Use IN/OUT as email_type
                $emailData['status_type'] ?? null,
                $emailData['status_text'] ?? null,
                $emailData['description'] ?? null,
                $emailData['answer'] ?? null,
                $emailContent
            ];

            $emailId = Database::queryValue($sql, $params);
            $this->stats['emails']++;

            // Migrate attachments
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
                status_type, status_text) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $emailId,
            $attachment['original_name'] ?? $filename,
            $filename,
            $attachment['mime_type'] ?? 'application/octet-stream',
            $attachmentPath,
            $attachment['status_type'] ?? null,
            $attachment['status_text'] ?? null
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

// Run migration
$migrator = new DataMigrator();
$migrator->migrate();

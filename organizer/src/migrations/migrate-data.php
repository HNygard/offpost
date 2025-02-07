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

    public function migrate(): void {
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

        // Check if we've reached 50 threads
        if ($this->threadCount >= 50) {
            echo "Reached 50 threads limit - committing and exiting\n";
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

// Run migration
$migrator = new DataMigrator();
$migrator->migrate();

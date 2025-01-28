<?php

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/ThreadFileOperations.php';
require_once __DIR__ . '/../class/common.php';

class MigrationVerifier {
    private $db;
    private $threadsDir;
    private $errors = [];
    private $stats = [
        'threads_checked' => 0,
        'emails_checked' => 0,
        'attachments_checked' => 0,
        'errors_found' => 0
    ];

    public function __construct() {
        $this->db = new Database();
        $this->threadsDir = THREADS_DIR;
    }

    public function verify() {
        $files = glob($this->threadsDir . '/threads-*.json');
        
        foreach ($files as $file) {
            echo "\nVerifying file: " . basename($file) . "\n";
            
            $jsonContent = file_get_contents($file);
            if (!$jsonContent) {
                $this->addError("Failed to read file: $file");
                continue;
            }

            $threadsData = json_decode($jsonContent, true);
            if (!$threadsData || !isset($threadsData['threads'])) {
                $this->addError("Invalid JSON or no threads in file: $file");
                continue;
            }

            foreach ($threadsData['threads'] as $threadData) {
                $threadData['_file_path'] = $file;
                $this->verifyThread($threadData);
            }
        }

        $this->printReport();
    }

    private function verifyThread($threadData) {
        $this->stats['threads_checked']++;
        echo "\nVerifying thread: " . $threadData['id'] . "\n";

        try {
            // Load thread from file
            $fileThread = ThreadFileOperations::loadThreadFromData($threadData);
            
            // Load thread from database
            $dbThread = Thread::loadFromDatabase($threadData['id']);
            
            if (!$dbThread) {
                $this->addError("Thread not found in database: " . $threadData['id']);
                return;
            }

            // Compare basic properties
            $this->compareThreadProperties($fileThread, $dbThread);
            
            // Compare emails
            $this->compareEmails($fileThread, $dbThread);

        } catch (Exception $e) {
            $this->addError("Error verifying thread " . $threadData['id'] . ": " . $e->getMessage() . "\n\n" . $e->getTraceAsString());
        }
    }

    private function compareThreadProperties($fileThread, $dbThread) {
        // Compare basic thread properties
        $properties = [
            'id' => 'Thread ID',
            'title' => 'Title',
            'my_name' => 'Name',
            'my_email' => 'Email',
            'sent' => 'Sent status',
            'archived' => 'Archived status',
            'public' => 'Public status',
            'sentComment' => 'Sent comment'
        ];

        foreach ($properties as $prop => $label) {
            if ($fileThread->$prop !== $dbThread->$prop) {
                $this->addError(sprintf(
                    "Mismatch in %s for thread %s:\nFile: %s\nDB: %s",
                    $label,
                    $fileThread->id,
                    var_export($fileThread->$prop, true),
                    var_export($dbThread->$prop, true)
                ));
            }
        }

        // Compare labels (order might differ)
        sort($fileThread->labels);
        sort($dbThread->labels);
        if ($fileThread->labels !== $dbThread->labels) {
            $this->addError(sprintf(
                "Labels mismatch for thread %s:\nFile: %s\nDB: %s",
                $fileThread->id,
                implode(', ', $fileThread->labels),
                implode(', ', $dbThread->labels)
            ));
        }
    }

    private function compareEmails($fileThread, $dbThread) {
        if (count($fileThread->emails) !== count($dbThread->emails)) {
            $this->addError(sprintf(
                "Email count mismatch for thread %s: File has %d, DB has %d",
                $fileThread->id,
                count($fileThread->emails),
                count($dbThread->emails)
            ));
            return;
        }

        for ($i = 0; $i < count($fileThread->emails); $i++) {
            $this->stats['emails_checked']++;
            $fileEmail = $fileThread->emails[$i];
            $dbEmail = $dbThread->emails[$i];

            // Compare email properties
            $emailProps = [
                'direction' => 'Direction',
                'subject' => 'Subject',
                'fromEmail' => 'From',
                'toEmail' => 'To',
                'content' => 'Content',
                'rawContent' => 'Raw content'
            ];

            foreach ($emailProps as $prop => $label) {
                if ($fileEmail->$prop !== $dbEmail->$prop) {
                    $this->addError(sprintf(
                        "Email %s mismatch in thread %s:\nFile: %s\nDB: %s",
                        $label,
                        $fileThread->id,
                        substr(var_export($fileEmail->$prop, true), 0, 100),
                        substr(var_export($dbEmail->$prop, true), 0, 100)
                    ));
                }
            }

            // Compare received date (allowing 1 second difference due to potential timestamp precision issues)
            $dateDiff = abs($fileEmail->receivedDate->getTimestamp() - $dbEmail->receivedDate->getTimestamp());
            if ($dateDiff > 1) {
                $this->addError(sprintf(
                    "Email date mismatch in thread %s:\nFile: %s\nDB: %s",
                    $fileThread->id,
                    $fileEmail->receivedDate->format('Y-m-d H:i:s'),
                    $dbEmail->receivedDate->format('Y-m-d H:i:s')
                ));
            }

            // Compare attachments
            $this->compareAttachments($fileEmail, $dbEmail, $fileThread->id);
        }
    }

    private function compareAttachments($fileEmail, $dbEmail, $threadId) {
        $fileAttCount = count($fileEmail->attachments ?? []);
        $dbAttCount = count($dbEmail->attachments ?? []);
        
        if ($fileAttCount !== $dbAttCount) {
            $this->addError(sprintf(
                "Attachment count mismatch in thread %s: File has %d, DB has %d",
                $threadId,
                $fileAttCount,
                $dbAttCount
            ));
            return;
        }

        for ($i = 0; $i < $fileAttCount; $i++) {
            $this->stats['attachments_checked']++;
            $fileAtt = $fileEmail->attachments[$i];
            $dbAtt = $dbEmail->attachments[$i];

            $attProps = ['filename', 'mime_type', 'size'];
            foreach ($attProps as $prop) {
                if ($fileAtt[$prop] !== $dbAtt[$prop]) {
                    $this->addError(sprintf(
                        "Attachment %s mismatch in thread %s:\nFile: %s\nDB: %s",
                        $prop,
                        $threadId,
                        $fileAtt[$prop],
                        $dbAtt[$prop]
                    ));
                }
            }
        }
    }

    private function addError($message) {
        $this->errors[] = $message;
        $this->stats['errors_found']++;
        echo "ERROR: $message\n";
    }

    private function printReport() {
        echo "\n=== Migration Verification Report ===\n";
        echo "Threads checked: " . $this->stats['threads_checked'] . "\n";
        echo "Emails checked: " . $this->stats['emails_checked'] . "\n";
        echo "Attachments checked: " . $this->stats['attachments_checked'] . "\n";
        echo "Errors found: " . $this->stats['errors_found'] . "\n";

        if ($this->stats['errors_found'] > 0) {
            echo "\nDetailed Errors:\n";
            foreach ($this->errors as $i => $error) {
                echo ($i + 1) . ") " . $error . "\n";
            }
        }

        echo "\nVerification " . ($this->stats['errors_found'] === 0 ? "PASSED" : "FAILED") . "\n";
    }
}

// Run verification
$verifier = new MigrationVerifier();
$verifier->verify();

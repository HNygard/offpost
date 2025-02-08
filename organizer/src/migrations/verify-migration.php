<?php

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/ThreadFileOperations.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/common.php';

class MigrationVerifier {
    private $db;
    private $threadsDir;
    private $errors = [];
    private $stats = [
        'threads_checked' => 0,
        'emails_checked' => 0,
        'attachments_checked' => 0,
        'errors_found' => 0,
        'threads_skipped' => 0,
        'threads_limited' => 0
    ];

    private $skip;
    private $limit;

    public function __construct($skip = 0, $limit = null) {
        $this->db = new Database();
        $this->threadsDir = THREADS_DIR;
        $this->skip = max(0, (int)$skip);
        $this->limit = $limit !== null ? max(1, (int)$limit) : null;
    }

    public function verify() {
        // Output settings
        echo "\n=== Verification Settings ===\n";
        echo "Skip: " . $this->skip . " threads\n";
        echo "Limit: " . ($this->limit !== null ? $this->limit . " threads" : "No limit") . "\n\n";

        $files = glob($this->threadsDir . '/threads-*.json');
        $totalThreadCount = 0;
        $shouldContinue = true;
        
        foreach ($files as $file) {
            if (!$shouldContinue) {
                break;
            }
            
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
                if ($totalThreadCount < $this->skip) {
                    $this->stats['threads_skipped']++;
                    $totalThreadCount++;
                    continue;
                }
                
                if ($this->limit !== null && ($totalThreadCount - $this->skip) >= $this->limit) {
                    $this->stats['threads_limited']++;
                    $shouldContinue = false;
                    break;
                }

                $this->verifyThread($threadData);
                $totalThreadCount++;
            }
        }

        $this->printReport();
    }

    private function verifyThread($threadData) {
        $this->stats['threads_checked']++;
        echo "\nVerifying thread: " . $threadData['id'] . "\n";

        try {
            // Load thread from file
            $fileThread = new Thread();
            foreach ($threadData as $key => $value) {
                $fileThread->$key = $value;
            }
            $fileThread->sentComment = isset($threadData['sentComment']) ? $threadData['sentComment'] : null;
            
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
        // Compare basic thread properties, excluding ID which has changed format
        $properties = [
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
                    "Mismatch in %s for thread %s:\n"
                    . "File ... : %s\n"
                    . "DB ..... : %s",
                    $label,
                    $fileThread->id,
                    var_export($fileThread->$prop, true),
                    var_export($dbThread->$prop, true)
                ));
            }
        }

        // Compare labels (order might differ)
        $fileLabels = $fileThread->labels ?? [];
        $dbLabels = $dbThread->labels ?? [];
        sort($fileLabels);
        sort($dbLabels);
        if ($fileLabels !== $dbLabels) {
            $this->addError(sprintf(
                "Labels mismatch for thread %s:\n"
                ."File ... : %s\n"
                ."DB ..... : %s",
                $fileThread->id,
                implode(', ', $fileLabels),
                implode(', ', $dbLabels)
            ));
        }
    }

    private function compareEmails($fileThread, $dbThread) {
        $fileEmails = $fileThread->emails ?? [];
        $dbEmails = $dbThread->emails ?? [];

        // Sort emails by timestamp_received to ensure consistent comparison
        usort($fileEmails, function($a, $b) {
            return $a['id'] <=> $b['id'];
        });
        usort($dbEmails, function($a, $b) {
            return $a->id_old <=> $b->id_old;
        });

        if (count($fileEmails) !== count($dbEmails)) {
            $this->addError(sprintf(
                "Email count mismatch for thread %s: File has %d, DB has %d",
                $fileThread->id,
                count($fileThread->emails),
                count($dbThread->emails)
            ));
            return;
        }

        for ($i = 0; $i < count($fileEmails); $i++) {
            $this->stats['emails_checked']++;
            $fileEmail = $fileEmails[$i];
            $dbEmail = $dbEmails[$i];

            // Skip ID comparison since formats are different
            // Skip timestamp_received comparison since formats are different (Unix vs formatted)
            // Skip datetime_received comparison since it's redundant with timestamp_received
            
            // Compare only relevant email properties
            $emailProps = [
                'ignore' => 'Ignore',
                'email_type' => 'Email Type'
            ];

            foreach ($emailProps as $prop => $label) {
                $fileValue = $fileEmail[$prop] ?? null;
                $dbValue = $dbEmail->$prop;

                // Normalize empty strings to null for comparison
                if ($fileValue === '') {
                    $fileValue = null;
                }

                if ($fileValue !== $dbValue) {
                    $this->addError(sprintf(
                        "Email %s mismatch in thread %s:\n"
                        . "File ... : %s\n"
                        . "DB ..... : %s",
                        $label,
                        $fileThread->id,
                        substr(var_export($fileValue, true), 0, 100),
                        substr(var_export($dbValue, true), 0, 100)
                    ));
                }
            }

            // Compare attachments
            $this->compareAttachments($fileEmail, $dbEmail, $fileThread->id);
        }
    }

    private function compareAttachments($fileEmail, $dbEmail, $threadId) {
        $fileAttachments = $fileEmail['attachments'] ?? [];
        $dbAttachments = $dbEmail->attachments ?? [];
        
        $fileAttCount = count($fileAttachments);
        $dbAttCount = count($dbAttachments);
        
        if ($fileAttCount !== $dbAttCount) {
            $this->addError(sprintf(
                "Attachment count mismatch in thread %s: File has %d, DB has %d",
                $threadId,
                $fileAttCount,
                $dbAttCount
            ));
            return;
        }

        // Sort attachments by location to ensure consistent comparison
        usort($fileAttachments, function($a, $b) {
            return strcmp($a['location'], $b['location']);
        });
        usort($dbAttachments, function($a, $b) {
            $aLocation = basename($a['location']);
            $bLocation = basename($b['location']);
            return strcmp($aLocation, $bLocation);
        });

        for ($i = 0; $i < $fileAttCount; $i++) {
            $this->stats['attachments_checked']++;
            $fileAtt = $fileAttachments[$i];
            $dbAtt = $dbAttachments[$i];

            // Compare original filename (file's 'name' with DB's 'name')
            if ($fileAtt['name'] !== $dbAtt['name']) {
                $this->addError(sprintf(
                    "Attachment original filename mismatch in thread %s:\n"
                    . "File ... : %s\n"
                    . "DB ..... : %s",
                    $threadId,
                    $fileAtt['name'],
                    $dbAtt['name']
                ));
            }

            // Compare storage filename (file's 'location' with DB's 'location', ignoring path)
            $dbLocation = basename($dbAtt['location']);
            if ($fileAtt['location'] !== $dbLocation) {
                $this->addError(sprintf(
                    "Attachment storage filename mismatch in thread %s:\n"
                    . "File ... : %s\n"
                    . "DB ..... : %s",
                    $threadId,
                    $fileAtt['location'],
                    $dbLocation
                ));
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
        echo "Threads skipped: " . $this->stats['threads_skipped'] . "\n";
        echo "Threads limited: " . $this->stats['threads_limited'] . "\n";
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

// Parse command line arguments
$skip = isset($argv[1]) ? (int)$argv[1] : 0;
$limit = isset($argv[2]) ? (int)$argv[2] : null;

// Run verification
$verifier = new MigrationVerifier($skip, $limit);
$verifier->verify();

// Print usage if no arguments provided
if (!isset($argv[1]) && !isset($argv[2])) {
    echo "\nUsage: php verify-migration.php [skip] [limit]\n";
    echo "  skip: Number of threads to skip (default: 0)\n";
    echo "  limit: Maximum number of threads to process (default: all)\n";
    echo "\nExample: php verify-migration.php 100 50  # Skip 100 threads and process next 50\n";
}

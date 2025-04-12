#!/usr/bin/env php
<?php

/**
 * Offpost - Migrate Filesystem Content to Database
 * 
 * This script migrates email and attachment content from the filesystem to the database.
 * It's designed to fix old emails and attachments in the production database that have
 * content located on the filesystem instead of in the database.
 * 
 * Background:
 * In earlier versions of the application, email content and attachments were stored on
 * the filesystem. The current version stores this content directly in the database for
 * better data integrity and simplified management. This migration helps transition old
 * data to the new storage model.
 * 
 * Usage:
 *   php migrate-filesystem-content.php [--execute]
 * 
 * Options:
 *   --execute  Actually perform the migration (default is dry-run)
 * 
 * What the Migration Does:
 * 1. Finds email records with empty content but with a file path (id_old)
 * 2. Reads the email content from the filesystem
 * 3. Updates the email record with the content
 * 4. Finds attachment records with empty content but with a location
 * 5. Reads the attachment content from the filesystem
 * 6. Updates the attachment record with the content
 * 
 * The migration provides detailed logging and statistics, including:
 * - Number of emails and attachments processed
 * - Number of items successfully updated
 * - Number of items skipped (e.g., file not found)
 * - Number of errors encountered
 * 
 * Troubleshooting:
 * If the migration fails, check the following:
 * 1. Ensure the filesystem paths are correct (default is `/organizer-data/threads`)
 * 2. Verify that the PHP process has permission to read files
 * 3. Check that the files exist on the filesystem
 * 4. Look for any error messages in the output
 */

// Set up autoloading
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../error.php';
require_once __DIR__ . '/../class/Entity.php';

// Parse command line arguments
$options = getopt('', ['execute']);
$dryRun = !isset($options['execute']);

// Display header
echo "==========================================================\n";
echo "Offpost - Migrate Filesystem Content to Database\n";
echo "==========================================================\n\n";

/**
 * Migrate filesystem content to database
 */
class FilesystemContentMigration {
    private $dryRun = false;
    private $dataDir = '/organizer-data/threads';
    private $emailsProcessed = 0;
    private $emailsUpdated = 0;
    private $emailsSkipped = 0;
    private $emailsError = 0;
    private $attachmentsProcessed = 0;
    private $attachmentsUpdated = 0;
    private $attachmentsSkipped = 0;
    private $attachmentsError = 0;
    
    public function __construct($dryRun = false) {
        $this->dryRun = $dryRun;
    }
    
    /**
     * Run the migration
     */
    public function run() {
        try {
            if ($this->dryRun) {
                echo "DRY RUN MODE: No changes will be made to the database\n\n";
            } else {
                echo "EXECUTE MODE: Changes will be committed to the database\n\n";
            }
            
            echo "Starting migration...\n";
            
            // Start a transaction
            Database::beginTransaction();
            
            // Process emails
            echo "\nProcessing emails...\n";
            $this->migrateEmails();
            
            // Process attachments
            echo "\nProcessing attachments...\n";
            $this->migrateAttachments();
            
            // Commit the transaction if not in dry run mode
            if (!$this->dryRun) {
                Database::commit();
                echo "\nChanges committed to database.\n";
            } else {
                Database::rollBack();
                echo "\nDRY RUN: Changes were rolled back, no modifications made to database.\n";
            }
            
            // Print summary
            $this->printSummary();
            
            return true;
        } catch (Exception $e) {
            // Rollback the transaction on error
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            
            echo "Migration failed: " . $e->getMessage() . "\n";
            echo jTraceEx($e) . "\n";
            
            return false;
        }
    }
    
    /**
     * Migrate emails from filesystem to database
     */
    private function migrateEmails() {
        // Find emails with id_old (which indicates a file path) but empty content
        $emails = Database::query(
            "SELECT 
                te.id, 
                te.id_old, 
                te.thread_id,
                t.entity_id
             FROM thread_emails te
             JOIN threads t ON te.thread_id = t.id
             WHERE (te.content IS NULL OR te.content = '')
             AND te.id_old IS NOT NULL
             AND te.id_old != ''"
        );
        
        foreach ($emails as $email) {
            $this->emailsProcessed++;
            
            try {
                // Try different path formats to find the email file
                $possiblePaths = $this->getEmailPossiblePaths($email);
                $filePath = null;
                
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $filePath = $path;
                        break;
                    }
                }
                
                echo "Processing email {$this->emailsProcessed}: {$email['id']}\n";
                
                if ($filePath === null) {
                    echo "  - File not found in any of these locations:\n";
                    foreach ($possiblePaths as $path) {
                        echo "    - $path\n";
                    }
                    $this->emailsSkipped++;
                    continue;
                }
                
                echo "  - Found at: $filePath\n";
                
                // Read file content
                $fileContent = file_get_contents($filePath);
                if ($fileContent === false) {
                    echo "  - Failed to read file, skipping\n";
                    $this->emailsSkipped++;
                    continue;
                }
                
                // Update the email record with the file content
                if (!$this->dryRun) {
                    Database::execute(
                        "UPDATE thread_emails SET content = ? WHERE id = ?",
                        [$fileContent, $email['id']]
                    );
                    echo "  - Updated email content\n";
                } else {
                    echo "  - Would update email content (dry run)\n";
                }
                $this->emailsUpdated++;
            } catch (Exception $e) {
                echo "  - Error: " . $e->getMessage() . "\n";
                $this->emailsError++;
            }
        }
    }
    
    /**
     * Get possible file paths for an email
     */
    private function getEmailPossiblePaths($email) {
        $paths = [];
        $entityId = $email['entity_id'];
        
        // Try with the original entity_id
        $paths[] = "{$this->dataDir}/{$entityId}/{$email['thread_id']}/{$email['id_old']}.eml";
        $paths[] = "{$this->dataDir}/{$entityId}/thread_{$email['thread_id']}/{$email['id_old']}.eml";
        
        // Try with entity_id_norske_postlister if available
        $entity = Entity::getById($entityId);
        if (!isset($entity->entity_id_norske_postlister)) {
            $alternativeEntityId = $entity->entity_id_norske_postlister;
            $paths[] = "{$this->dataDir}/{$alternativeEntityId}/{$email['thread_id']}/{$email['id_old']}.eml";
            $paths[] = "{$this->dataDir}/{$alternativeEntityId}/thread_{$email['thread_id']}/{$email['id_old']}.eml";
        }
        
        // Try with numeric part of entity_id if it's in format "number-name"
        if (preg_match('/^(\d+)-(.+)$/', $entityId, $matches)) {
            $numericId = $matches[1];
            $name = $matches[2];
            
            $paths[] = "{$this->dataDir}/{$numericId}-{$name}/thread_{$email['thread_id']}/{$email['id_old']}.eml";
            $paths[] = "{$this->dataDir}/{$numericId}/{$email['thread_id']}/{$email['id_old']}.eml";
            $paths[] = "{$this->dataDir}/{$numericId}/thread_{$email['thread_id']}/{$email['id_old']}.eml";
        }
        
        return $paths;
    }
    
    /**
     * Migrate attachments from filesystem to database
     */
    private function migrateAttachments() {
        // Find attachments with location but no content
        $attachments = Database::query(
            "SELECT 
                tea.id, 
                tea.location, 
                te.thread_id,
                t.entity_id
             FROM thread_email_attachments tea
             JOIN thread_emails te ON tea.email_id = te.id
             JOIN threads t ON te.thread_id = t.id
             WHERE tea.content IS NULL 
             AND tea.location IS NOT NULL
             AND tea.location != ''"
        );
        
        foreach ($attachments as $attachment) {
            $this->attachmentsProcessed++;
            
            try {
                // The location field should already contain the full path
                $filePath = $attachment['location'];
                
                echo "Processing attachment {$this->attachmentsProcessed}: {$attachment['id']} - {$filePath}\n";
                
                // Check if file exists
                if (!file_exists($filePath)) {
                    echo "  - File not found, skipping\n";
                    $this->attachmentsSkipped++;
                    continue;
                }
                
                // Read file content
                $fileContent = file_get_contents($filePath);
                if ($fileContent === false) {
                    echo "  - Failed to read file, skipping\n";
                    $this->attachmentsSkipped++;
                    continue;
                }
                
                // Update the attachment record with the file content
                $stmt = Database::prepare(
                    "UPDATE thread_email_attachments SET content = :content WHERE id = :id"
                );
                $stmt->bindValue(':content', $fileContent, PDO::PARAM_LOB);
                $stmt->bindValue(':id', $attachment['id']);
                $stmt->execute();
                if (!$this->dryRun) {
                    echo "  - Updated attachment content\n";
                } else {
                    echo "  - Would update attachment content (dry run)\n";
                }
                $this->attachmentsUpdated++;
            } catch (Exception $e) {
                echo "  - Error: " . $e->getMessage() . "\n";
                $this->attachmentsError++;
            }
        }
    }
    
    /**
     * Print migration summary
     */
    private function printSummary() {
        echo "\n==========================================================\n";
        echo "Migration Summary\n";
        echo "==========================================================\n\n";
        
        echo "Emails:\n";
        echo "  - Processed: {$this->emailsProcessed}\n";
        echo "  - Updated: {$this->emailsUpdated}\n";
        echo "  - Skipped: {$this->emailsSkipped}\n";
        echo "  - Errors: {$this->emailsError}\n\n";
        
        echo "Attachments:\n";
        echo "  - Processed: {$this->attachmentsProcessed}\n";
        echo "  - Updated: {$this->attachmentsUpdated}\n";
        echo "  - Skipped: {$this->attachmentsSkipped}\n";
        echo "  - Errors: {$this->attachmentsError}\n\n";
        
        echo "Total items processed: " . ($this->emailsProcessed + $this->attachmentsProcessed) . "\n";
        echo "Total items updated: " . ($this->emailsUpdated + $this->attachmentsUpdated) . "\n";
        echo "Total items skipped: " . ($this->emailsSkipped + $this->attachmentsSkipped) . "\n";
        echo "Total errors: " . ($this->emailsError + $this->attachmentsError) . "\n";
    }
}

// Run the migration
$migration = new FilesystemContentMigration($dryRun);
$success = $migration->run();

if ($success) {
    exit(0); // Success
} else {
    exit(1); // Error
}

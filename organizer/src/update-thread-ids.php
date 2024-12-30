<?php

$options = getopt('', ['execute', 'user:']);
$dryRun = !isset($options['execute']);
$userId = isset($options['user']) ? $options['user'] : null;

if ($argc < 2) {
    echo "Usage: php update-thread-ids.php <threads_directory> [--execute] [--user=USER_ID]\n";
    echo "Example: php update-thread-ids.php /path/to/data/threads\n";
    echo "Options:\n";
    echo "  --execute         Actually perform the changes (default: dry run)\n";
    echo "  --user=USER_ID    Set up user access for migrated threads\n";
    exit(1);
}

$threadsDir = $argv[1];

if (!is_dir($threadsDir)) {
    echo "Error: Directory '$threadsDir' does not exist\n";
    exit(1);
}

if ($dryRun) {
    echo "[DRY RUN] Changes will be simulated, not actually performed\n";
    echo "[DRY RUN] Use --execute flag to perform actual changes\n\n";
}

require_once __DIR__ . '/class/common.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadFileOperations.php';

// Override THREADS_DIR constant for this script
define('THREADS_DIR', $threadsDir);

function generateThreadId() {
    return uniqid('thread_', true);
}

function moveThreadData($entityId, $oldThreadId, $newThreadId, $dryRun = true) {
    $oldPath = joinPaths(THREADS_DIR, $entityId, $oldThreadId);
    $newPath = joinPaths(THREADS_DIR, $entityId, $newThreadId);

    if (!file_exists($oldPath)) {
        return;
    }

    $files = glob($oldPath . '/*');
    if (empty($files)) {
        return;
    }

    if ($dryRun) {
        echo "[DRY RUN] Would move data from $oldPath to $newPath:\n";
        foreach ($files as $file) {
            echo "[DRY RUN]   - Would move " . basename($file) . "\n";
        }
        echo "[DRY RUN] Would remove old directory: $oldPath\n";
        return;
    }

    if (!file_exists($newPath)) {
        mkdir($newPath, 0777, true);
    }

    // Move all files from old to new directory
    foreach ($files as $file) {
        $filename = basename($file);
        rename($file, joinPaths($newPath, $filename));
    }

    // Remove old directory if empty
    if (is_dir($oldPath) && count(glob($oldPath . '/*')) === 0) {
        rmdir($oldPath);
    }

    echo "Moved data from $oldPath to $newPath\n";
}

function updateThreadIds($dryRun = true, $userId = null) {
    $threads = getThreads();
    $updated = false;

    foreach ($threads as $file => $threadsData) {
        $needsSaving = false;
        
        if (!isset($threadsData->threads)) {
            continue;
        }

        $entityId = str_replace('threads-', '', basename($file, '.json'));

        foreach ($threadsData->threads as $thread) {
            $oldId = null;
            
            // Check if we need to generate a new ID
            if (!isset($thread->id)) {
                $oldId = getThreadId($thread); // Get old ID if it exists
                echo "Found thread without ID: " . $thread->title . "\n";
                $thread->id = generateThreadId();
                $needsSaving = true;
                $updated = true;
                echo "Generated new ID: " . $thread->id . "\n";
                
                // Move data from old to new location if needed
                if ($oldId) {
                    moveThreadData($entityId, $oldId, $thread->id);
                    
                    // Set up user access if user ID provided
                    if ($userId) {
                        if ($dryRun) {
                            echo "[DRY RUN] Would grant access to user $userId for thread: " . $thread->title . "\n";
                        } else {
                            $thread->addUser($userId, true);
                            echo "Granted access to user $userId for thread: " . $thread->title . "\n";
                        }
                    }
                }
            }
        }

            if ($needsSaving) {
                if ($dryRun) {
                    echo "[DRY RUN] Would save updated threads for entity: " . $entityId . "\n";
                } else {
                    saveEntityThreads($entityId, $threadsData);
                    echo "Saved updated threads for entity: " . $entityId . "\n";
                }
            }
    }

    if (!$updated) {
        echo "All threads already have IDs. No updates needed.\n";
    }
}

// Helper function to get old thread ID format
function getThreadId($thread) {
    if (!isset($thread->title)) {
        return null;
    }
    return preg_replace('/[^a-z0-9]+/i', '-', strtolower($thread->title));
}

// Run the update
echo "Starting thread ID update and data migration in: $threadsDir\n";
if ($userId) {
    echo "Will set up access for user: $userId\n";
}
updateThreadIds($dryRun, $userId);
if ($dryRun) {
    echo "\n[DRY RUN] Completed simulation of changes. No actual changes were made.\n";
    echo "[DRY RUN] Use --execute flag to perform these changes.\n";
} else {
    echo "\nThread ID update and data migration complete.\n";
}

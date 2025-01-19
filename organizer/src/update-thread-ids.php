<?php

// Debug all arguments
echo "All arguments:\n";
for ($i = 0; $i < $argc; $i++) {
    echo "[$i] " . $argv[$i] . "\n";
}
echo "\n";

// Parse command line options
$longopts = [
    "set-ids",      // no value
    "add-users",    // no value
    "move-folders", // no value
    "user:",        // requires value
];

// Need to tell getopt to look at all arguments, not just those after script name
$options = getopt("", $longopts, $optind);
$setIds = isset($options['set-ids']);
$addUsers = isset($options['add-users']);
$moveFolders = isset($options['move-folders']);
$userId = $options['user'] ?? null;

// If no operation flags are set, we're in dry run mode
$dryRun = !($setIds || $addUsers || $moveFolders);

// Debug parsed options
echo "Parsed options:\n";
var_export($options);
echo "\n\n";

// Debug final values
echo "Operations to perform:\n";
echo "Set IDs: " . ($setIds ? "yes" : "no") . "\n";
echo "Add Users: " . ($addUsers ? "yes" : "no") . "\n";
echo "Move Folders: " . ($moveFolders ? "yes" : "no") . "\n";
echo "User ID: " . ($userId ?? "not set") . "\n\n";

if ($argc < 2) {
    echo "Usage: php update-thread-ids.php [--set-ids] [--add-users] [--move-folders] [--user=USER_ID] <threads_directory>\n";
    echo "Example: php update-thread-ids.php --set-ids --add-users --user=123 /path/to/data/threads\n";
    echo "Options:\n";
    echo "  --set-ids         Generate and set new thread IDs where missing\n";
    echo "  --add-users       Set up user access for threads\n";
    echo "  --move-folders    Move thread data to new locations\n";
    echo "  --user=USER_ID    User ID to grant access (required with --add-users)\n";
    exit(1);
}

// Get the directory argument after parsing options
$threadsDir = $argv[$optind] ?? null;

if (!$threadsDir) {
    echo "Error: No threads directory specified\n";
    exit(1);
}

if (!is_dir($threadsDir)) {
    echo "Error: Directory '$threadsDir' does not exist\n";
    exit(1);
}

if ($dryRun) {
    echo "[DRY RUN] Changes will be simulated, not actually performed\n";
    echo "[DRY RUN] Use --set-ids, --add-users, and/or --move-folders flags to perform specific operations\n\n";
}

// Override THREADS_DIR constant for this script
define('THREADS_DIR', $threadsDir);

require_once __DIR__ . '/class/common.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadUtils.php';
require_once __DIR__ . '/class/ThreadFileOperations.php';


function generateThreadId() {
    return uniqid('thread_', true);
}

function moveThreadData($entityId, $oldThreadId, $newThreadId, $dryRun) {
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

function updateThreadIds($dryRun, $setIds, $addUsers, $moveFolders, $userId = null) {
    $threads = getThreads();
    $updated = false;

    if ($addUsers && !$userId) {
        echo "Error: --user parameter is required when using --add-users\n";
        exit(1);
    }

    foreach ($threads as $file => $threadsData) {
        $needsSaving = false;
        
        if (!isset($threadsData->threads)) {
            continue;
        }

        $entityId = str_replace('threads-', '', basename($file, '.json'));

        foreach ($threadsData->threads as $thread) {
            $oldId = getThreadId($thread); // Get old ID if it exists
            
            // Check if we need to generate a new ID
            if ($setIds && !isset($thread->id)) {
                echo "Found thread without ID: " . $thread->title . "\n";
                $thread->id = generateThreadId();
                $needsSaving = true;
                $updated = true;
                echo "Generated new ID: " . $thread->id . "\n";
            }
            
            // Move data from old to new location if needed
            if ($moveFolders) {
                moveThreadData($entityId, $oldId, $thread->id, $dryRun);
            }
                
            // Set up user access if requested
            if ($addUsers && $userId) {
                if ($dryRun) {
                    echo "[DRY RUN] Would grant access to user $userId for thread: " . $thread->title . "\n";
                } else {
                    $thread->addUser($userId, true);
                    echo "Granted access to user $userId for thread: " . $thread->title . "\n";
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

    if ($setIds && !$updated) {
        echo "All threads already have IDs. No updates needed.\n";
    }
}

// Run the update
echo "Starting thread operations in: $threadsDir\n";
if ($dryRun) {
    echo "[DRY RUN] No operations specified. Will simulate all operations.\n";
    $setIds = $addUsers = $moveFolders = true;
}

if ($userId) {
    echo "Will set up access for user: $userId\n";
}

updateThreadIds($dryRun, $setIds, $addUsers, $moveFolders, $userId);

if ($dryRun) {
    echo "\n[DRY RUN] Completed simulation of changes. No actual changes were made.\n";
    echo "[DRY RUN] Use --set-ids, --add-users, and/or --move-folders to perform specific operations.\n";
} else {
    echo "\nThread operations complete.\n";
}

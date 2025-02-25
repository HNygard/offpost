<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';

class ThreadFileOperations {
    public function getThreads() {
        $files = getFilesInDirectoryNoRecursive(THREADS_DIR);
        $threads = array();
        /* @var Threads[] $threads */
        foreach($files as $file) {
            if (basename($file) === '.gitignore') {
                continue;
            }

            // Ignore db-migration.json
            if (basename($file) === 'db-migration.json') {
                continue;
            }

            $threads[$file] = json_decode(file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
            // Convert threads array to Thread objects
            if (isset($threads[$file]->threads)) {
                foreach ($threads[$file]->threads as &$thread) {
                    $threadObj = new Thread();
                    foreach ($thread as $key => $value) {
                        $threadObj->$key = $value;
                    }

                    if (!isset($thread->id)) {
                        unset($threadObj->id);
                    }
                    $threadObj->sentComment = isset($thread->sentComment) ? $thread->sentComment : null; // Initialize sentComment
                    
                    // Ensure entity_id is set on the Thread object
                    if (empty($threadObj->entity_id) && !empty($threads[$file]->entity_id)) {
                        $threadObj->entity_id = $threads[$file]->entity_id;
                    }
                    
                    $thread = $threadObj;
                }
            }
        }
        return $threads;
    }
    /**
     * @param String $entityID
     * @return Threads
     */
    public function getThreadsForEntity($entityID) {
        $path = joinPaths(THREADS_DIR, 'threads-' . $entityID . '.json');
        if (!file_exists($path)) {
            return null;
        }

        $threads = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        // Convert threads array to Thread objects
        if (isset($threads->threads)) {
            foreach ($threads->threads as &$thread) {
                $threadObj = new Thread();
                foreach ($thread as $key => $value) {
                    $threadObj->$key = $value;
                }
                $threadObj->sentComment = isset($thread->sentComment) ? $thread->sentComment : null; // Initialize sentComment
                
                // Ensure entity_id is set on the Thread object
                if (empty($threadObj->entity_id) && !empty($threads->entity_id)) {
                    $threadObj->entity_id = $threads->entity_id;
                }
                
                $thread = $threadObj;
            }
        }
        return $threads;
    }
    /**
     * Get a thread file (email or attachment)
     * @param string $entityId The entity ID
     * @param Thread|string $thread The thread object or thread ID
     * @param string $attachement The attachment filename
     * @return string The file contents
     * @throws Exception If the file or directory doesn't exist
     */
    public function getThreadFile($entityId, $thread, $attachement) {
        // Handle both Thread objects and string IDs
        $threadId = is_string($thread) ? $thread : $thread->id;
        $threadIdOld = is_string($thread) ? $thread : ($thread->id_old ?? $thread->id);
        
        $path = joinPaths(THREADS_DIR, $entityId, $threadId, $attachement);
        
        if (!file_exists(dirname($path))) {
            // Try with id_old if the primary directory doesn't exist
            $path2 = joinPaths(THREADS_DIR, $entityId, $threadIdOld, $attachement);
            if (!file_exists(dirname($path2))) {
                throw new Exception("Thread directory does not exist: " . dirname($path) . "\n" .
                    "And not with id_old either: " . dirname($path2));
            }
            $path = $path2;
        }
        
        if (!file_exists($path)) {
            throw new Exception("Thread file not found: " . $path);
        }
        
        return file_get_contents($path);
    }
    /**
     * @param $entityId
     * @param Threads $entity_threads
     */
    public function saveEntityThreads($entityId, $entity_threads) {
        $path = joinPaths(THREADS_DIR, 'threads-' . $entityId . '.json');
        logDebug('Writing to [' . $path . '].');
        file_put_contents($path, json_encode($entity_threads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));
    }
    public function createThread($entityId, $entityTitlePrefix, Thread $thread, $userId = 'system') {
        $existingThreads = $this->getThreadsForEntity($entityId);
        if ($existingThreads == null) {
            $existingThreads = new Threads();
            $existingThreads->entity_id = $entityId;
            $existingThreads->title_prefix = $entityTitlePrefix;
            $existingThreads->threads = array();
        }
        
        // Ensure entity_id is set on the Thread object
        if (empty($thread->entity_id)) {
            $thread->entity_id = $entityId;
        }
        
        $existingThreads->threads[] = $thread;

        $path = joinPaths(THREADS_DIR, 'threads-' . $entityId . '.json');
        file_put_contents($path, json_encode($existingThreads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));

        return $thread;
    }

    public function updateThread(Thread $thread, $userId = 'system') {
        throw new Exception('Not supported.');
    }
}

<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';

function getThreads() {
    $files = getFilesInDirectoryNoRecursive(THREADS_DIR);
    $threads = array();
    /* @var Threads[] $threads */
    foreach($files as $file) {
        if (basename($file) === '.gitignore') {
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
                $thread = $threadObj;
            }
        }
    }
    return $threads;
}

if (!function_exists('getThreadsForEntity')) {
    /**
     * @param String $entityID
     * @return Threads
     */
    function getThreadsForEntity($entityID) {
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
                $thread = $threadObj;
            }
        }
        return $threads;
    }
}

function getThreadFile($entityId, $thread, $attachement) {
    $threadId = is_string($thread) ? $thread : $thread->id;
    return file_get_contents(joinPaths(THREADS_DIR, $entityId, $threadId, $attachement));
}

if (!function_exists('saveEntityThreads')) {
    /**
     * @param $entityId
     * @param Threads $entity_threads
     */
    function saveEntityThreads($entityId, $entity_threads) {
        $path = joinPaths(THREADS_DIR, 'threads-' . $entityId . '.json');
        logDebug('Writing to [' . $path . '].');
        file_put_contents($path, json_encode($entity_threads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('createThread')) {
    function createThread($entityId, $entityTitlePrefix, Thread $thread) {
        $existingThreads = getThreadsForEntity($entityId);
        if ($existingThreads == null) {
            $existingThreads = new Threads();
            $existingThreads->entity_id = $entityId;
            $existingThreads->title_prefix = $entityTitlePrefix;
            $existingThreads->threads = array();
        }
        $existingThreads->threads[] = $thread;

        $path = joinPaths(THREADS_DIR, 'threads-' . $entityId . '.json');
        file_put_contents($path, json_encode($existingThreads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));

        return $thread;
    }
}

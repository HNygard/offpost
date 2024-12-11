<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Threads.php';

function getThreads() {
    $files = getFilesInDirectoryNoRecursive('/organizer-data/threads');
    $threads = array();
    /* @var Threads[] $threads */
    foreach($files as $file) {
        if (basename($file) === '.gitignore') {
            continue;
        }

        $threads[$file] = json_decode(file_get_contents($file));
    }
    return $threads;
}

/**
 * @param String $entityID
 * @return Threads
 */
function getThreadsForEntity($entityID) {
    $path = '/organizer-data/threads/threads-' . $entityID . '.json';
    if (!file_exists($path)) {
        return null;
    }

    return json_decode(file_get_contents($path));
}

function getThreadFile($entityId, $thread, $attachement) {
    return file_get_contents('/organizer-data/threads/' . $entityId . '/' . getThreadId($thread) .'/'.$attachement);
}

/**
 * @param $entityId
 * @param Threads $entity_threads
 */
function saveEntityThreads($entityId, $entity_threads) {
    $path = '/organizer-data/threads/threads-' . $entityId . '.json';
    logDebug('Writing to [' . $path . '].');
    file_put_contents($path, json_encode($entity_threads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));
}

function createThread($entityId, $entityTitlePrefix, Thread $thread) {
    $existingThreads = getThreadsForEntity($entityId);
    if ($existingThreads == null) {
        $existingThreads = new Threads();
        $existingThreads->entity_id = $entityId;
        $existingThreads->title_prefix = $entityTitlePrefix;
        $existingThreads->threads = array();
    }
    $existingThreads->threads[] = $thread;

    file_put_contents('/organizer-data/threads/threads-' . $entityId . '.json',
        json_encode($existingThreads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));

    return $thread;
}

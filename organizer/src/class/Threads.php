<?php

require_once __DIR__ . '/common.php';

function getThreads() {
    $files = getFilesInDirectoryNoRecursive('/organizer-data/threads');
    $threads = array();
    /* @var Threads[] $threads */
    foreach($files as $file) {
        $threads[$file] = json_decode(file_get_contents($file));
    }
    return $threads;
}

function getThreadsForEntity($entityID) {
    $path = '/organizer-data/threads/threads-' . $entityID . '.json';
    if (!file_exists($path)) {
        return null;
    }

    /* @var Threads $threads */
    return json_decode(file_get_contents($path));
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
}

class Threads {
    var $title_prefix;
    var $entity_id;

    /* @var $threads Thread[] */
    var $threads;
}
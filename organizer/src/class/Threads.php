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

function getThreadId($thread) {
    $email_folder = str_replace(' ', '_', mb_strtolower($thread->title, 'UTF-8'));
    $email_folder = str_replace('/', '-', $email_folder);
    return $email_folder;
}

function getLabelType($type, $status_type) {
    if ($status_type == 'info') {
        $label_type = 'label';
    }
    elseif ($status_type == 'disabled') {
        $label_type = 'label label_disabled';
    }
    elseif ($status_type == 'danger') {
        $label_type = 'label label_warn';
    }
    elseif ($status_type == 'success') {
        $label_type = 'label label_ok';
    }
    elseif ($status_type == 'unknown') {
        $label_type = 'label';
    }
    else {
        throw new Exception('Unknown status_type[' . $type . ']: ' . $status_type);
    }
    return $label_type;
}

class Threads {
    var $title_prefix;
    var $entity_id;

    /* @var $threads Thread[] */
    var $threads;
}
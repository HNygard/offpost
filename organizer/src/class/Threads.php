<?php

function getThreads($path) {
    /* @var Threads[] $threads */
    $threads = array(json_decode(file_get_contents($path)));

    return $threads;
}

class Threads {
    var $title_prefix;
    var $entity_id;

    /* @var $threads Thread[] */
    var $threads;
}
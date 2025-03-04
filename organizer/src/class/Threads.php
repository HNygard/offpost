<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ThreadFileOperations.php';
require_once __DIR__ . '/ThreadUtils.php';
require_once __DIR__ . '/ThreadEmailService.php';

class Threads {
    var $entity_id;

    /* @var $threads Thread[] */
    var $threads;
}

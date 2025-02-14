<?php

require_once __DIR__ . '/ThreadFileOperations.php';
require_once __DIR__ . '/ThreadDatabaseOperations.php';

class ThreadStorageManager {
    private static $instance = null;
    private $fileOps;
    private $dbOps;
    private $useDatabase;
    
    private function __construct() {
        $this->fileOps = new ThreadFileOperations();
        $this->dbOps = new ThreadDatabaseOperations();
        // Default to database storage for optimized queries
        $this->useDatabase = true;
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getThreads($userId = null) {
        return $this->useDatabase ? $this->dbOps->getThreads($userId) : $this->fileOps->getThreads($userId);
    }
    
    public function getThreadsForEntity($entityId) {
        return $this->useDatabase ? $this->dbOps->getThreadsForEntity($entityId) : $this->fileOps->getThreadsForEntity($entityId);
    }
    
    public function createThread($entityId, $entityTitlePrefix, Thread $thread) {
        return $this->useDatabase ? 
            $this->dbOps->createThread($entityId, $entityTitlePrefix, $thread) : 
            $this->fileOps->createThread($entityId, $entityTitlePrefix, $thread);
    }
}

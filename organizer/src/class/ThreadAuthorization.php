<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/Database.php';

class ThreadAuthorization implements JsonSerializable {
    private $thread_id;
    private $user_id;
    private $is_owner;
    private $created_at;

    public function __construct($thread_id, $user_id, $is_owner = false) {
        $this->thread_id = $thread_id;
        $this->user_id = $user_id;
        $this->is_owner = $is_owner;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public function getThreadId() {
        return $this->thread_id;
    }

    public function getUserId() {
        return $this->user_id;
    }

    public function isOwner() {
        return $this->is_owner;
    }

    public function getCreatedAt() {
        return $this->created_at;
    }

    public function jsonSerialize(): mixed {
        return [
            'thread_id' => $this->thread_id,
            'user_id' => $this->user_id,
            'is_owner' => $this->is_owner,
            'created_at' => $this->created_at
        ];
    }
}

class ThreadAuthorizationManager {
    private static $db = null;

    private static function getDb() {
        if (self::$db === null) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function addUserToThread($thread_id, $user_id, $is_owner = false) {
        $db = self::getDb();
        
        // Use upsert to handle existing authorizations
        $db->execute(
            "INSERT INTO thread_authorizations (thread_id, user_id, is_owner) 
             VALUES (?, ?, ?) 
             ON CONFLICT (thread_id, user_id) 
             DO UPDATE SET is_owner = EXCLUDED.is_owner",
            [$thread_id, $user_id, $is_owner ? 't' : 'f']
        );
        
        return new ThreadAuthorization($thread_id, $user_id, $is_owner);
    }

    public static function removeUserFromThread($thread_id, $user_id) {
        $db = self::getDb();
        $db->execute(
            "DELETE FROM thread_authorizations WHERE thread_id = ? AND user_id = ?",
            [$thread_id, $user_id]
        );
    }

    public static function getThreadUsers($thread_id) {
        $db = self::getDb();
        $rows = $db->query(
            "SELECT thread_id, user_id, is_owner, created_at 
             FROM thread_authorizations 
             WHERE thread_id = ?",
            [$thread_id]
        );
        
        return array_map(function($row) {
            return new ThreadAuthorization(
                $row['thread_id'],
                $row['user_id'],
                (bool)$row['is_owner']
            );
        }, $rows);
    }

    public static function getUserThreads($user_id) {
        $db = self::getDb();
        $rows = $db->query(
            "SELECT thread_id, user_id, is_owner, created_at 
             FROM thread_authorizations 
             WHERE user_id = ?",
            [$user_id]
        );
        
        return array_map(function($row) {
            return new ThreadAuthorization(
                $row['thread_id'],
                $row['user_id'],
                (bool)$row['is_owner']
            );
        }, $rows);
    }

    public static function canUserAccessThread($thread_id, $user_id) {
        $db = self::getDb();
        
        // Check if thread is public or user has authorization
        $result = $db->queryOne(
            "SELECT EXISTS (
                SELECT 1 FROM threads t
                LEFT JOIN thread_authorizations ta 
                    ON t.id = ta.thread_id AND ta.user_id = ?
                WHERE t.id = ? AND (t.public = true OR ta.thread_id IS NOT NULL)
            ) as has_access",
            [$user_id, $thread_id]
        );
        
        return (bool)$result['has_access'];
    }

    public static function isThreadOwner($thread_id, $user_id) {
        $db = self::getDb();
        
        $result = $db->queryOne(
            "SELECT EXISTS (
                SELECT 1 FROM thread_authorizations
                WHERE thread_id = ? AND user_id = ? AND is_owner = true
            ) as is_owner",
            [$thread_id, $user_id]
        );
        
        return (bool)$result['is_owner'];
    }
}

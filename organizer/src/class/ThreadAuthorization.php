<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';

if (!defined('THREAD_AUTH_DIR')) {
    define('THREAD_AUTH_DIR', joinPaths(THREADS_DIR, 'authorizations'));
}

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
    public static function addUserToThread($thread_id, $user_id, $is_owner = false) {
        $auth = new ThreadAuthorization($thread_id, $user_id, $is_owner);
        self::saveAuthorization($auth);
        return $auth;
    }

    public static function removeUserFromThread($thread_id, $user_id) {
        self::deleteAuthorization($thread_id, $user_id);
    }

    public static function getThreadUsers($thread_id) {
        return self::loadAuthorizationsForThread($thread_id);
    }

    public static function getUserThreads($user_id) {
        $allAuths = self::loadAllAuthorizations();
        return array_filter($allAuths, function($auth) use ($user_id) {
            return $auth->getUserId() === $user_id;
        });
    }

    public static function canUserAccessThread($thread_id, $user_id) {
        $thread = self::getThread($thread_id);
        if ($thread && $thread->public) {
            return true;
        }
        
        $auths = self::loadAuthorizationsForThread($thread_id);
        foreach ($auths as $auth) {
            if ($auth->getUserId() === $user_id) {
                return true;
            }
        }
        return false;
    }

    public static function isThreadOwner($thread_id, $user_id) {
        $auths = self::loadAuthorizationsForThread($thread_id);
        foreach ($auths as $auth) {
            if ($auth->getUserId() === $user_id && $auth->isOwner()) {
                return true;
            }
        }
        return false;
    }

    private static function getAuthPath($thread_id) {
        if (!file_exists(THREAD_AUTH_DIR)) {
            mkdir(THREAD_AUTH_DIR, 0777, true);
        }
        return joinPaths(THREAD_AUTH_DIR, $thread_id . '_auth.json');
    }

    private static function saveAuthorization(ThreadAuthorization $auth) {
        $path = self::getAuthPath($auth->getThreadId());
        $auths = self::loadAuthorizationsForThread($auth->getThreadId());
        
        // Remove existing auth for this user if exists
        $auths = array_filter($auths, function($existing) use ($auth) {
            return $existing->getUserId() !== $auth->getUserId();
        });
        
        // Add new auth
        $auths[] = $auth;
        
        // Save to file
        file_put_contents($path, json_encode($auths, JSON_PRETTY_PRINT));
    }

    private static function deleteAuthorization($thread_id, $user_id) {
        $path = self::getAuthPath($thread_id);
        if (!file_exists($path)) {
            return;
        }

        $auths = self::loadAuthorizationsForThread($thread_id);
        $auths = array_filter($auths, function($auth) use ($user_id) {
            return $auth->getUserId() !== $user_id;
        });

        file_put_contents($path, json_encode($auths, JSON_PRETTY_PRINT));
    }

    private static function loadAuthorizationsForThread($thread_id) {
        $path = self::getAuthPath($thread_id);
        if (!file_exists($path)) {
            return array();
        }

        $data = json_decode(file_get_contents($path), true);
        return array_map(function($item) {
            return new ThreadAuthorization(
                $item['thread_id'],
                $item['user_id'],
                $item['is_owner']
            );
        }, $data);
    }

    private static function loadAllAuthorizations() {
        if (!file_exists(THREAD_AUTH_DIR)) {
            return array();
        }

        $files = glob(joinPaths(THREAD_AUTH_DIR, '*_auth.json'));
        $allAuths = array();
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            foreach ($data as $item) {
                $allAuths[] = new ThreadAuthorization(
                    $item['thread_id'],
                    $item['user_id'],
                    $item['is_owner']
                );
            }
        }
        return $allAuths;
    }

    private static function getThread($thread_id) {
        // Find thread in all entity thread files
        $allThreads = getThreads();
        foreach ($allThreads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                if ($thread->id === $thread_id) {
                    return $thread;
                }
            }
        }
        return null;
    }
}

<?php

require_once __DIR__ . '/ThreadAuthorization.php';

class Thread {
    var $id;
    var $title;
    var $my_name;
    var $my_email;
    var $labels;
    var $sent;
    var $archived;
    var $public = false;

    /* @var ThreadEmail[] $emails */
    var $emails;

    public function __construct() {
        $this->id = uniqid('thread_', true);
    }

    /**
     * Add a user to this thread
     */
    public function addUser($user_id, $is_owner = false) {
        return ThreadAuthorizationManager::addUserToThread($this->id, $user_id, $is_owner);
    }

    /**
     * Remove a user from this thread
     */
    public function removeUser($user_id) {
        ThreadAuthorizationManager::removeUserFromThread($this->id, $user_id);
    }

    /**
     * Check if a user can access this thread
     */
    public function canUserAccess($user_id) {
        if ($this->public) {
            return true;
        }
        return ThreadAuthorizationManager::canUserAccessThread($this->id, $user_id);
    }

    /**
     * Check if a user is the owner of this thread
     */
    public function isUserOwner($user_id) {
        return ThreadAuthorizationManager::isThreadOwner($this->id, $user_id);
    }
}

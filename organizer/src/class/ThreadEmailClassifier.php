<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
use App\Enums\ThreadEmailStatusType;

class ThreadEmailClassifier {
    /**
     * Automatically classifies emails in a thread based on rules
     * Currently implements:
     * - First email (emails[0]) gets classified as info/Initiell henvendelse if outbound
     * 
     * @param object $thread The thread object containing emails to classify
     * @return object The modified thread object with classified emails
     */
    public function classifyEmails($thread) {
        if (!isset($thread->emails) || empty($thread->emails)) {
            return $thread;
        }

        // Only check first email if it's outbound and status is unknown
        if ($thread->emails[0]->email_type === 'OUT' &&
            (
                $thread->emails[0]->status_type === ThreadEmailStatusType::UNKNOWN->value ||
                $thread->emails[0]->status_type === 'unknown' // Keep for existing string data
            )
        ) {
            $thread->emails[0]->status_type = ThreadEmailStatusType::OUR_REQUEST;
            $thread->emails[0]->status_text = 'Initiell henvendelse';
            $thread->emails[0]->auto_classification = 'algo';
        }

        return $thread;
    }

    /**
     * Removes automatic classification when an email is manually classified
     * 
     * @param object $email The email object being manually classified
     * @return object The modified email object
     */
    public function removeAutoClassification($email) {
        if (isset($email->auto_classification)) {
            unset($email->auto_classification);
        }
        return $email;
    }

    /**
     * Gets a human-readable label for the classification type
     * 
     * @param object $email The email object containing auto_classification
     * @return string The classification label
     */
    public static function getClassificationLabel($email) {
        if (
            $email->status_type === 'unknown' || // Keep for existing string data
            $email->status_type === ThreadEmailStatusType::UNKNOWN->value ||
            $email->status_type === ThreadEmailStatusType::UNKNOWN
        ) {
            return null;
        }

        if (!isset($email->auto_classification)) {
            return 'Human';
        }

        if ($email->auto_classification === 'algo') {
            return '<a href="https://github.com/HNygard/offpost/blob/main/organizer/src/class/ThreadEmailClassifier.php">Code</a>';
        }

        if ($email->auto_classification === 'prompt') {
            return 'AI';
        }

        return 'Unknown';
    }
}

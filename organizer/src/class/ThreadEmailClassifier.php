<?php

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
            $thread->emails[0]->status_type === 'unknown') {
            $thread->emails[0]->status_type = 'info';
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
}

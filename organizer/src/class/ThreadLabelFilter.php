<?php

class ThreadLabelFilter {
    /**
     * Check if a thread matches the given label filter
     * 
     * @param Thread $thread The thread to check
     * @param string $label_filter The label filter to apply
     * @return bool True if the thread matches the filter, false otherwise
     */
    public static function matches($thread, string $label_filter): bool {
        switch ($label_filter) {
            case 'sent':
                return $thread->sent;
            case 'not_sent':
                return !$thread->sent;
            case 'archived':
                return $thread->archived;
            case 'not_archived':
                return !$thread->archived;
            default:
                return in_array($label_filter, $thread->labels);
        }
    }
}

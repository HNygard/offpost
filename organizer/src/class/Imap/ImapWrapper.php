<?php

namespace Imap;

class ImapWrapper {
    private const MAX_RETRIES = 5;
    private const RETRY_DELAY_MS = 100; // Base delay in milliseconds
    
    private function checkError(string $operation, ?array $params = null) {
        $error = \imap_last_error();
        if ($error !== false) {
            $context = '';
            if ($params) {
                $context = ' [' . implode(', ', $params) . ']';
            }
            throw new \Exception("IMAP error during $operation$context: $error");
        }
    }
    
    /**
     * Check if an error indicates a connection issue that might be retryable
     */
    private function isRetryableError(string $error): bool {
        // Only retry very specific connection-broken errors from the original issue
        $retryablePatterns = [
            '[CLOSED] IMAP connection broken',
            '[CLOSED] IMAP connection lost',
            'IMAP connection broken (server response)',
            'No body information available'
        ];
        
        $lowerError = strtolower($error);
        foreach ($retryablePatterns as $pattern) {
            if (strpos($lowerError, strtolower($pattern)) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Execute a read operation with retry logic
     */
    private function executeWithRetry(callable $operation, string $operationName, ?array $params = null) {
        $lastError = null;
        
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                // Clear any previous IMAP errors
                \imap_errors();
                
                $result = $operation();
                
                // Check for errors after the operation
                $error = \imap_last_error();
                if ($error !== false) {
                    if ($this->isRetryableError($error) && $attempt < self::MAX_RETRIES) {
                        $lastError = $error;
                        error_log("IMAP retry attempt $attempt/" . self::MAX_RETRIES . " for $operationName: $error");
                        $this->waitBeforeRetry($attempt);
                        continue;
                    } else {
                        // Non-retryable error or max retries reached
                        $context = '';
                        if ($params) {
                            $context = ' [' . implode(', ', $params) . ']';
                        }
                        throw new \Exception("IMAP error during $operationName$context: $error");
                    }
                }
                
                // Log successful retry if this wasn't the first attempt
                if ($attempt > 1) {
                    error_log("IMAP operation $operationName succeeded on attempt $attempt");
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                
                // Check if this is a retryable error
                if ($this->isRetryableError($errorMessage) && $attempt < self::MAX_RETRIES) {
                    $lastError = $errorMessage;
                    error_log("IMAP retry attempt $attempt/" . self::MAX_RETRIES . " for $operationName: $errorMessage");
                    $this->waitBeforeRetry($attempt);
                    continue;
                } else {
                    // Re-throw if not retryable or max retries reached
                    throw $e;
                }
            }
        }
        
        // If we get here, we've exhausted all retries
        $context = '';
        if ($params) {
            $context = ' [' . implode(', ', $params) . ']';
        }
        throw new \Exception("IMAP operation $operationName$context failed after " . self::MAX_RETRIES . " retries. Last error: $lastError");
    }
    
    /**
     * Wait before retrying with exponential backoff
     */
    private function waitBeforeRetry(int $attempt): void {
        $delayMs = self::RETRY_DELAY_MS * pow(2, $attempt - 1); // Exponential backoff
        $delayMs = min($delayMs, 5000); // Cap at 5 seconds
        usleep($delayMs * 1000); // Convert to microseconds
    }

    public function list(mixed $imap_stream, string $ref, string $pattern): array|false {
        $result = \imap_list($imap_stream, $ref, $pattern);
        $this->checkError('list');
        return $result;
    }

    public function lsub(mixed $imap_stream, string $ref, string $pattern): array|false {
        $result = \imap_lsub($imap_stream, $ref, $pattern);
        $this->checkError('lsub');
        return $result;
    }

    public function createMailbox(mixed $imap_stream, string $mailbox): bool {
        $result = \imap_createmailbox($imap_stream, $mailbox);
        $this->checkError('createMailbox(' . $mailbox . ')');
        return $result;
    }

    public function subscribe(mixed $imap_stream, string $mailbox): bool {
        $result = \imap_subscribe($imap_stream, $mailbox);
        $this->checkError('subscribe(' . $mailbox . ')');
        return $result;
    }

    public function utf7Encode(string $string): string {
        return \imap_utf7_encode($string);
    }

    public function open(string $mailbox, string $username, string $password, int $options = 0, int $retries = 0, array $flags = []): mixed {
        $result = \imap_open($mailbox, $username, $password, $options, $retries, $flags);
        $this->checkError('open', ['mailbox: ' . $mailbox, 'username: ' . $username]);
        return $result;
    }

    public function close(mixed $imap_stream, int $flags = 0): bool {
        $result = \imap_close($imap_stream, $flags);
        try {
            $this->checkError('close');
        }
        catch (\Exception $e) {
            // Log the error but allow close to return true
            error_log($e->getMessage());
        }
        return $result;
    }

    public function lastError(): ?string {
        return \imap_last_error();
    }

    public function mailMove(mixed $imap_stream, string $msglist, string $mailbox, int $options = 0): bool {
        $result = \imap_mail_move($imap_stream, $msglist, $mailbox, $options);
        
        // Check for errors
        $error = \imap_last_error();
        if ($error !== false) {
            // Check if this is an EXPUNGEISSUED error - message was already deleted
            if (strpos($error, '[EXPUNGEISSUED]') !== false) {
                // This is not a critical error - the message is already gone
                // Log it and return false to indicate the move didn't happen
                error_log("IMAP mailMove: Message already deleted/expunged (UID: $msglist): $error");
                return false;
            }
            
            // For other errors, throw an exception
            throw new \Exception("IMAP error during mailMove: $error");
        }
        
        return $result;
    }

    public function renameMailbox(mixed $imap_stream, string $old_name, string $new_name): bool {
        $result = \imap_renamemailbox($imap_stream, $old_name, $new_name);
        $this->checkError('renameMailbox');
        return $result;
    }

    public function search(mixed $imap_stream, string $criteria, int $options = SE_FREE, string $charset = ""): array|false {
        return $this->executeWithRetry(
            function() use ($imap_stream, $criteria, $options, $charset) {
                return \imap_search($imap_stream, $criteria, $options, $charset);
            },
            'search',
            ["criteria: $criteria", "options: $options"]
        );
    }

    public function msgno(mixed $imap_stream, int $uid): int {
        $result = \imap_msgno($imap_stream, $uid);
        $this->checkError('msgno');
        return $result;
    }

    public function headerinfo(mixed $imap_stream, int $msg_number): object|false {
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number) {
                return \imap_headerinfo($imap_stream, $msg_number);
            },
            'headerinfo',
            ["msg_number: $msg_number"]
        );
    }

    public function body(mixed $imap_stream, int $msg_number, int $options = 0): string|false {
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number, $options) {
                return \imap_body($imap_stream, $msg_number, $options);
            },
            'body',
            ["msg_number: $msg_number", "options: $options"]
        );
    }

    public function utf8(string $text): string {
        return \imap_utf8($text);
    }

    public function fetchstructure(mixed $imap_stream, int $msg_number, int $options = 0): object {
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number, $options) {
                return \imap_fetchstructure($imap_stream, $msg_number, $options);
            },
            'fetchstructure',
            ["msg_number: $msg_number", "options: $options"]
        );
    }

    public function fetchbody(mixed $imap_stream, int $msg_number, string $section, int $options = 0): string {
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number, $section, $options) {
                return \imap_fetchbody($imap_stream, $msg_number, $section, $options);
            },
            'fetchbody',
            ["msg_number: $msg_number", "section: $section", "options: $options"]
        );
    }
}

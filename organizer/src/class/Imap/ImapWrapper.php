<?php

namespace Imap;

class ImapWrapper {
    private const MAX_RETRIES = 5;
    private const RETRY_DELAY_MS = 100; // Base delay in milliseconds
    
    private bool $debug;
    private array $operationHistory = [];
    private const HISTORY_LIMIT = 100;

    public function getOperationHistory(): array {
        return $this->operationHistory;
    }

    private function recordOperation(string $operation, ?array $params, array $details = []): void {
        // Never retain decoded text, credentials, or arbitrary search expressions.
        if (!in_array($operation, ['open', 'close', 'search', 'msgno', 'headerinfo', 'body',
            'fetchbody', 'fetchstructure', 'mailMove', 'createMailbox', 'subscribe', 'renameMailbox'])) {
            return;
        }
        $safeParams = array_values(array_filter($params ?? [], static function(string $param): bool {
            return preg_match('/^(uid|msg_number|msglist|options|flags|mailbox|old_name|new_name): /', $param) === 1
                || preg_match('/^criteria: (ALL|UID [0-9]+)$/', $param) === 1;
        }));
        if ($operation === 'open' || $operation === 'search') {
            unset($details['error']);
        }
        $this->operationHistory[] = [
            'time' => microtime(true),
            'operation' => $operation,
            'params' => $safeParams,
            ...$details,
        ];
        if (count($this->operationHistory) > self::HISTORY_LIMIT) {
            array_shift($this->operationHistory);
        }
    }

    public function check(mixed $stream): object|false {
        return \imap_check($stream);
    }

    public function status(mixed $stream, string $mailbox): object|false {
        return \imap_status($stream, $mailbox, SA_ALL);
    }

    public function ping(mixed $stream): bool {
        return \imap_ping($stream);
    }

    public function errors(): array {
        return \imap_errors() ?: [];
    }

    public function alerts(): array {
        return \imap_alerts() ?: [];
    }

    public function fetchOverview(mixed $stream, int $uid): array|false {
        return \imap_fetch_overview($stream, (string)$uid, FT_UID);
    }
    
    /**
     * @param bool $debug Whether to enable debug logging
     */
    public function __construct(bool $debug = false) {
        $this->debug = $debug;
    }
    
    /**
     * Log debug message if debug is enabled
     */
    private function logDebug(string $operation, ?array $params = null): void {
        $this->recordOperation($operation, $params, ['outcome' => 'started']);
        if ($this->debug) {
            $context = '';
            if ($params && !empty($params)) {
                $context = ' [' . implode(', ', $params) . ']';
            }
            echo "IMAP DEBUG: $operation$context\n";
        }
    }
    
    private function checkError(string $operation, ?array $params = null, bool $ignoreExpungeIssued = false) {
        $error = \imap_last_error();
        if ($error !== false) {
            // Check if this is an EXPUNGEISSUED error and we should ignore it
            if ($ignoreExpungeIssued && strpos($error, '[EXPUNGEISSUED]') !== false) {
                // This is not a critical error - the message is already gone
                // Log it but don't throw an exception
                $context = '';
                if ($params) {
                    $context = ' [' . implode(', ', $params) . ']';
                }
                error_log("IMAP $operation$context: Message already deleted/expunged: $error");
                return;
            }
            
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
            'No body information available',
            "Couldn't open stream",  // For imap_open connection failures
            'Failed to open IMAP connection' // Our custom error message wrapper
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
                        $this->recordOperation($operationName, $params, ['attempt' => $attempt, 'outcome' => 'retry', 'error' => $error]);
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
                $this->recordOperation($operationName, $params, [
                    'attempt' => $attempt,
                    'outcome' => $result === false ? 'returned_false' : 'succeeded',
                ]);
                
                return $result;
                
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->recordOperation($operationName, $params, [
                    'attempt' => $attempt,
                    'outcome' => $this->isRetryableError($errorMessage) && $attempt < self::MAX_RETRIES ? 'retry' : 'failed',
                    'error' => $errorMessage,
                ]);
                
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
        $this->logDebug('list', ["ref: $ref", "pattern: $pattern"]);
        $result = \imap_list($imap_stream, $ref, $pattern);
        $this->checkError('list');
        return $result;
    }

    public function lsub(mixed $imap_stream, string $ref, string $pattern): array|false {
        $this->logDebug('lsub', ["ref: $ref", "pattern: $pattern"]);
        $result = \imap_lsub($imap_stream, $ref, $pattern);
        $this->checkError('lsub');
        return $result;
    }

    public function createMailbox(mixed $imap_stream, string $mailbox): bool {
        $this->logDebug('createMailbox', ["mailbox: $mailbox"]);
        $result = \imap_createmailbox($imap_stream, $mailbox);
        $this->checkError('createMailbox(' . $mailbox . ')');
        return $result;
    }

    public function subscribe(mixed $imap_stream, string $mailbox): bool {
        $this->logDebug('subscribe', ["mailbox: $mailbox"]);
        $result = \imap_subscribe($imap_stream, $mailbox);
        $this->checkError('subscribe(' . $mailbox . ')');
        return $result;
    }

    public function utf7Encode(string $string): string {
        $stringPreview = strlen($string) > 50 ? substr($string, 0, 50) . '...' : $string;
        $this->logDebug('utf7Encode', ["string: $stringPreview"]);
        // imap_utf7_encode() treats its input as ISO-8859-1 (one byte = one char), so any
        // multi-byte UTF-8 character (e.g. an en dash "–") gets mangled into an invalid
        // mUTF-7 sequence. Our strings are UTF-8, so encode with that explicitly.
        return \mb_convert_encoding($string, 'UTF7-IMAP', 'UTF-8');
    }

    public function utf7Decode(string $string): string {
        $stringPreview = strlen($string) > 50 ? substr($string, 0, 50) . '...' : $string;
        $this->logDebug('utf7Decode', ["string: $stringPreview"]);
        return \mb_convert_encoding($string, 'UTF-8', 'UTF7-IMAP');
    }

    public function open(string $mailbox, string $username, string $password, int $options = 0, int $retries = 0, array $flags = []): mixed {
        $this->logDebug('open', ['mailbox: ' . $mailbox, 'username: ' . $username]);
        
        return $this->executeWithRetry(
            function() use ($mailbox, $username, $password, $options, $retries, $flags) {
                $result = \imap_open($mailbox, $username, $password, $options, $retries, $flags);
                if ($result === false) {
                    $error = \imap_last_error();
                    throw new \Exception("Failed to open IMAP connection: " . ($error ?: "Unknown error"));
                }
                return $result;
            },
            'open',
            ['mailbox: ' . $mailbox, 'username: ' . $username]
        );
    }

    public function close(mixed $imap_stream, int $flags = 0): bool {
        $this->logDebug('close', ["flags: $flags"]);
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
        $this->logDebug('mailMove', ["msglist: $msglist", "mailbox: $mailbox", "options: $options"]);
        try {
            $result = \imap_mail_move($imap_stream, $msglist, $mailbox, $options);
            $this->recordOperation('mailMove', ["msglist: $msglist", "mailbox: $mailbox", "options: $options"], [
                'outcome' => $result ? 'succeeded' : 'returned_false',
                'last_error' => \imap_last_error(),
            ]);
            $this->checkError('mailMove', null, true);
        } catch (\Exception $e) {
            $this->recordOperation('mailMove', ["msglist: $msglist", "mailbox: $mailbox"], ['outcome' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
        return $result;
    }

    public function renameMailbox(mixed $imap_stream, string $old_name, string $new_name): bool {
        $this->logDebug('renameMailbox', ["old_name: $old_name", "new_name: $new_name"]);
        $result = \imap_renamemailbox($imap_stream, $old_name, $new_name);
        $this->checkError('renameMailbox');
        return $result;
    }

    public function search(mixed $imap_stream, string $criteria, int $options = SE_FREE, string $charset = ""): array|false {
        $this->logDebug('search', ["criteria: $criteria", "options: $options"]);
        return $this->executeWithRetry(
            function() use ($imap_stream, $criteria, $options, $charset) {
                return \imap_search($imap_stream, $criteria, $options, $charset);
            },
            'search',
            ["criteria: $criteria", "options: $options"]
        );
    }

    public function msgno(mixed $imap_stream, int $uid): int {
        $this->logDebug('msgno', ["uid: $uid"]);
        $result = \imap_msgno($imap_stream, $uid);
        $this->recordOperation('msgno', ["uid: $uid"], ['message_number' => $result]);
        $this->checkError('msgno');
        return $result;
    }

    public function headerinfo(mixed $imap_stream, int $msg_number): object|false {
        $this->logDebug('headerinfo', ["msg_number: $msg_number"]);
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number) {
                return \imap_headerinfo($imap_stream, $msg_number);
            },
            'headerinfo',
            ["msg_number: $msg_number"]
        );
    }

    public function body(mixed $imap_stream, int $msg_number, int $options = 0): string|false {
        $this->logDebug('body', ["msg_number: $msg_number", "options: $options"]);
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number, $options) {
                return \imap_body($imap_stream, $msg_number, $options);
            },
            'body',
            ["msg_number: $msg_number", "options: $options"]
        );
    }

    public function utf8(string $text): string {
        $textPreview = strlen($text) > 50 ? substr($text, 0, 50) . '...' : $text;
        $this->logDebug('utf8', ["text: $textPreview"]);
        return \imap_utf8($text);
    }

    public function fetchstructure(mixed $imap_stream, int $msg_number, int $options = 0): object {
        $this->logDebug('fetchstructure', ["msg_number: $msg_number", "options: $options"]);
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number, $options) {
                return \imap_fetchstructure($imap_stream, $msg_number, $options);
            },
            'fetchstructure',
            ["msg_number: $msg_number", "options: $options"]
        );
    }

    public function fetchbody(mixed $imap_stream, int $msg_number, string $section, int $options = 0): string {
        $this->logDebug('fetchbody', ["msg_number: $msg_number", "section: $section", "options: $options"]);
        return $this->executeWithRetry(
            function() use ($imap_stream, $msg_number, $section, $options) {
                return \imap_fetchbody($imap_stream, $msg_number, $section, $options);
            },
            'fetchbody',
            ["msg_number: $msg_number", "section: $section", "options: $options"]
        );
    }
}

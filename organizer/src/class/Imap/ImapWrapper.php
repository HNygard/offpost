<?php

namespace Imap;

class ImapWrapper {
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
        $this->checkError('close');
        return $result;
    }

    public function lastError(): ?string {
        return \imap_last_error();
    }

    public function mailMove(mixed $imap_stream, string $msglist, string $mailbox, int $options = 0): bool {
        $result = \imap_mail_move($imap_stream, $msglist, $mailbox, $options);
        $this->checkError('mailMove');
        return $result;
    }

    public function renameMailbox(mixed $imap_stream, string $old_name, string $new_name): bool {
        $result = \imap_renamemailbox($imap_stream, $old_name, $new_name);
        $this->checkError('renameMailbox');
        return $result;
    }

    public function search(mixed $imap_stream, string $criteria, int $options = SE_FREE, string $charset = ""): array|false {
        $result = \imap_search($imap_stream, $criteria, $options, $charset);
        $this->checkError('search');
        return $result;
    }

    public function msgno(mixed $imap_stream, int $uid): int {
        $result = \imap_msgno($imap_stream, $uid);
        $this->checkError('msgno');
        return $result;
    }

    public function headerinfo(mixed $imap_stream, int $msg_number): object|false {
        $result = \imap_headerinfo($imap_stream, $msg_number);
        $this->checkError('headerinfo');
        return $result;
    }

    public function body(mixed $imap_stream, int $msg_number, int $options = 0): string|false {
        $result = \imap_body($imap_stream, $msg_number, $options);
        $this->checkError('body');
        return $result;
    }

    public function utf8(string $text): string {
        return \imap_utf8($text);
    }

    public function fetchstructure(mixed $imap_stream, int $msg_number, int $options = 0): object {
        $result = \imap_fetchstructure($imap_stream, $msg_number, $options);
        $this->checkError('fetchstructure');
        return $result;
    }

    public function fetchbody(mixed $imap_stream, int $msg_number, string $section, int $options = 0): string {
        $result = \imap_fetchbody($imap_stream, $msg_number, $section, $options);
        $this->checkError('fetchbody');
        return $result;
    }
}

<?php

namespace Imap;

class ImapWrapper {
    public function open(string $mailbox, string $username, string $password, int $options = 0, int $retries = 0, array $flags = []): mixed {
        return \imap_open($mailbox, $username, $password, $options, $retries, $flags);
    }

    public function close(mixed $imap_stream, int $flags = 0): bool {
        return \imap_close($imap_stream, $flags);
    }

    public function lastError(): ?string {
        return \imap_last_error();
    }

    public function list(mixed $imap_stream, string $ref, string $pattern): array|false {
        return \imap_list($imap_stream, $ref, $pattern);
    }

    public function lsub(mixed $imap_stream, string $ref, string $pattern): array|false {
        return \imap_lsub($imap_stream, $ref, $pattern);
    }

    public function createMailbox(mixed $imap_stream, string $mailbox): bool {
        return \imap_createmailbox($imap_stream, $mailbox);
    }

    public function subscribe(mixed $imap_stream, string $mailbox): bool {
        return \imap_subscribe($imap_stream, $mailbox);
    }

    public function utf7Encode(string $string): string {
        return \imap_utf7_encode($string);
    }

    public function mailMove(mixed $imap_stream, string $msglist, string $mailbox, int $options = 0): bool {
        return \imap_mail_move($imap_stream, $msglist, $mailbox, $options);
    }

    public function renameMailbox(mixed $imap_stream, string $old_name, string $new_name): bool {
        return \imap_renamemailbox($imap_stream, $old_name, $new_name);
    }

    public function search(mixed $imap_stream, string $criteria, int $options = SE_FREE, string $charset = ""): array|false {
        return \imap_search($imap_stream, $criteria, $options, $charset);
    }

    public function msgno(mixed $imap_stream, int $uid): int {
        return \imap_msgno($imap_stream, $uid);
    }

    public function headerinfo(mixed $imap_stream, int $msg_number): object|false {
        return \imap_headerinfo($imap_stream, $msg_number);
    }

    public function body(mixed $imap_stream, int $msg_number, int $options = 0): string|false {
        return \imap_body($imap_stream, $msg_number, $options);
    }

    public function utf8(string $text): string {
        return \imap_utf8($text);
    }

    public function fetchstructure(mixed $imap_stream, int $msg_number, int $options = 0): object {
        return \imap_fetchstructure($imap_stream, $msg_number, $options);
    }

    public function fetchbody(mixed $imap_stream, int $msg_number, string $section, int $options = 0): string {
        return \imap_fetchbody($imap_stream, $msg_number, $section, $options);
    }
}

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
}

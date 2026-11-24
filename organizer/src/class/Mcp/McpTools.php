<?php

require_once __DIR__ . '/McpToolProvider.php';
require_once __DIR__ . '/McpAttachmentLink.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Entity.php';
require_once __DIR__ . '/../Thread.php';
require_once __DIR__ . '/../NpApiService.php';
require_once __DIR__ . '/../ThreadExportService.php';
require_once __DIR__ . '/../ThreadStorageManager.php';
require_once __DIR__ . '/../ThreadAuthorization.php';
require_once __DIR__ . '/../ThreadCreationService.php';
require_once __DIR__ . '/../ThreadReplyService.php';
require_once __DIR__ . '/../ThreadUtils.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';

/**
 * The tools served by the /mcp endpoint. Every tool returns one JSON text item.
 *
 * Access rules: reads of a single thread use Thread::canUserAccess() (public
 * threads are readable); list_my_threads and the write tools (send_reply) use
 * thread_authorizations rows only (owner or granted).
 */
class McpTools implements McpToolProvider {
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    public const MAX_RAW_ATTACHMENT_BYTES = 5242880;
    /** Test-only: replaces MAX_RAW_ATTACHMENT_BYTES when set. */
    public static ?int $maxRawBytesOverride = null;
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var callable(): int */
    private $clock;

    /**
     * @param ?McpAttachmentLink $links signs the download links of get_attachment_link
     * @param ?string $resource the MCP resource URL; download links are <resource>/attachment/<token>
     * @param ?callable $clock returns the current unix time; injected by tests
     */
    public function __construct(
        private ?McpAttachmentLink $links = null,
        private ?string $resource = null,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? 'time';
    }

    public function definitions(): array {
        $limit = [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => self::MAX_LIMIT,
            'description' => 'Maximum number of results (default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . ').',
        ];
        return [
            [
                'name' => 'list_entities',
                'description' => 'Search the public entities (agencies, municipalities) that requests can be sent to. '
                    . 'Matches a case-insensitive part of the name or organization number. '
                    . 'Only entities that can receive a request are listed (entities without an email address and entities that no longer exist are left out). '
                    . 'Returns entity_id, which is needed when starting a thread.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Part of the entity name or organization number.'],
                        'limit' => $limit,
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'list_my_threads',
                'description' => 'List the threads (requests and their replies) you own or have been given access to, newest activity first. '
                    . 'Use this to find thread ids for get_thread and the other thread tools. '
                    . 'total is the number of threads matching the filters. To page, pass next_cursor from the previous '
                    . 'response as cursor; next_cursor is null on the last page. Paging continues after the last thread '
                    . 'returned, so archiving threads between pages does not skip any. '
                    . 'has_delivery_problem is true when an outgoing email of the thread failed to send (see delivery_problems in get_thread).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Part of the thread title or entity name.'],
                        'include_archived' => ['type' => 'boolean', 'description' => 'Also list archived threads (default false).'],
                        'limit' => $limit,
                        'cursor' => ['type' => 'string', 'description' => 'next_cursor from the previous page; omit for the first page.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_thread',
                'description' => 'Get one thread with its entity, emails (plain text body, classification, attachments with extraction summaries) '
                    . 'and queued or sent outgoing emails, plus delivery_problems (outgoing emails that failed to send and are still not sent; an empty list means none), plus can_reply and, when true, the reply_recipients for send_reply. Thread ids come from list_my_threads. Public threads of other users are readable too.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id (UUID) from list_my_threads.'],
                    ],
                    'required' => ['thread_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_attachment_link',
                'description' => 'Get a download link for the original bytes of one attachment. Prefer download_url: fetch it with curl into a local file, '
                    . 'for example `curl -o file.pdf "<download_url>"`, so the file stays out of your context. '
                    . 'The link works without authentication for 5 minutes (expires_in seconds) and no size limit applies. '
                    . 'Set inline to true only for small files you must see yourself (images as image content, other types as an embedded resource; '
                    . 'attachments over 5 MB are refused, use get_attachment_extraction for their text). '
                    . 'Attachment ids come from get_thread. Public threads of other users are readable too.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id (UUID) from list_my_threads.'],
                        'attachment_id' => ['type' => 'string', 'description' => 'Attachment id (UUID) from get_thread.'],
                        'inline' => ['type' => 'boolean', 'description' => 'Also return the content inline (default false). Only for small files.'],
                    ],
                    'required' => ['thread_id', 'attachment_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_attachment_extraction',
                'description' => 'Get the extracted text of one attachment (e.g. OCR or AI text extraction), optionally only for one prompt. '
                    . 'An empty list means no extraction exists yet. Attachment ids come from get_thread.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id (UUID) from list_my_threads.'],
                        'attachment_id' => ['type' => 'string', 'description' => 'Attachment id (UUID) from get_thread.'],
                        'prompt_id' => ['type' => 'string', 'description' => 'Only return extractions made with this prompt id.'],
                    ],
                    'required' => ['thread_id', 'attachment_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'start_thread',
                'description' => 'Start a new thread: this sends a real request email to the public body (entity) within minutes. '
                    . 'It cannot be recalled. One thread is created per call. entity_id comes from list_entities; '
                    . 'entities without an email address or that no longer exist are refused. '
                    . 'The thread gets a new generated sender identity (returned as my_name and my_email), not the user\'s real name, '
                    . 'and the signature is added automatically: do not sign the body. '
                    . 'A new thread is a separate request, not connected to any earlier thread, neither in Offpost nor for the entity. '
                    . 'To follow up an existing request, use send_reply in that thread instead. '
                    . 'If you still start a new thread on a related subject, write it as a standalone request and never use the name of another thread\'s identity.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_id' => ['type' => 'string', 'description' => 'Entity id from list_entities.'],
                        'title' => ['type' => 'string', 'description' => 'Thread title, also used as the email subject.'],
                        'body' => ['type' => 'string', 'description' => 'The request text.'],
                        'law_basis' => [
                            'type' => 'string',
                            'enum' => ['offentleglova', 'other'],
                            'description' => 'Legal basis of the request (default offentleglova).',
                        ],
                        'follow_up_plan' => [
                            'type' => 'string',
                            'enum' => ['speedy', 'slow', 'postliste', 'none'],
                            'description' => 'How reminders are scheduled (default speedy); none disables follow-up.',
                        ],
                    ],
                    'required' => ['entity_id', 'title', 'body'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'send_reply',
                'description' => 'Reply in a thread you own or have been given access to. The email is queued and sent within minutes '
                    . 'from the thread\'s own sender identity (thread.my_name and thread.my_email in get_thread), and cannot be recalled. '
                    . 'The signature (that identity\'s name) is added automatically: do not sign the body. '
                    . 'recipient must be one of the reply_recipients returned by get_thread '
                    . '(the entity email and addresses seen in the thread); get_thread reports can_reply for this.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id (UUID) from list_my_threads.'],
                        'subject' => ['type' => 'string', 'description' => 'Email subject.'],
                        'body' => ['type' => 'string', 'description' => 'Email text.'],
                        'recipient' => ['type' => 'string', 'description' => 'One of get_thread reply_recipients.'],
                    ],
                    'required' => ['thread_id', 'subject', 'body', 'recipient'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'archive_thread',
                'description' => 'Archive (or with archived=false, unarchive) a thread you own or have been given access to. '
                    . 'Archiving only hides the thread from default lists (list_my_threads without include_archived); '
                    . 'nothing is sent and incoming emails are still received. Returns changed=false when the '
                    . 'thread already had that state.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id (UUID) from list_my_threads.'],
                        'archived' => ['type' => 'boolean', 'description' => 'true (default) archives, false unarchives.'],
                    ],
                    'required' => ['thread_id'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function call(string $name, array $args, string $userId): array {
        switch ($name) {
            case 'list_entities':
                return $this->textResult($this->listEntities($args));
            case 'list_my_threads':
                return $this->textResult($this->listMyThreads($args, $userId));
            case 'get_thread':
                return $this->textResult($this->getThread($args, $userId));
            case 'get_attachment_link':
                return $this->getAttachmentLink($args, $userId);
            case 'get_attachment_extraction':
                return $this->textResult($this->getAttachmentExtraction($args, $userId));
            case 'start_thread':
                return $this->textResult($this->startThread($args, $userId));
            case 'send_reply':
                return $this->textResult($this->sendReply($args, $userId));
            case 'archive_thread':
                return $this->textResult($this->archiveThread($args, $userId));
            default:
                throw new McpToolException("Unknown tool: $name");
        }
    }

    private function textResult(array $data): array {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        return [['type' => 'text', 'text' => json_encode($data, $flags)]];
    }

    private function optionalString(array $args, string $key): ?string {
        if (!isset($args[$key])) {
            return null;
        }
        if (!is_string($args[$key])) {
            throw new McpToolException("Argument '$key' must be a string");
        }
        return $args[$key];
    }

    private function requiredString(array $args, string $key): string {
        $value = $this->optionalString($args, $key);
        if ($value === null) {
            throw new McpToolException("Argument '$key' is required");
        }
        return $value;
    }

    private function optionalBool(array $args, string $key, bool $default): bool {
        if (!isset($args[$key])) {
            return $default;
        }
        if (!is_bool($args[$key])) {
            throw new McpToolException("Argument '$key' must be a boolean");
        }
        return $args[$key];
    }

    private function limitArg(array $args): int {
        if (!isset($args['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_int($args['limit'])) {
            throw new McpToolException("Argument 'limit' must be an integer");
        }
        return max(1, min(self::MAX_LIMIT, $args['limit']));
    }

    private function requireUuid(mixed $v, string $field): string {
        if (!is_string($v) || !preg_match(self::UUID_REGEX, $v)) {
            throw new McpToolException("Invalid $field (expected UUID)");
        }
        return $v;
    }

    private function isoTimestamp(string $raw): string {
        return (new DateTime($raw))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    private function listEntities(array $args): array {
        $search = $this->optionalString($args, 'search');
        $limit = $this->limitArg($args);

        $entities = [];
        foreach (Entity::getAll() as $entity) {
            // Same rule start_thread enforces: no recipient or a defunct entity cannot get a request.
            if (trim((string)($entity->email ?? '')) === '' || Entity::isDefunct($entity)) {
                continue;
            }
            if ($search !== null && $search !== '') {
                $matchesName = mb_stripos($entity->name ?? '', $search) !== false;
                $matchesOrgNum = mb_stripos((string)($entity->org_num ?? ''), $search) !== false;
                if (!$matchesName && !$matchesOrgNum) {
                    continue;
                }
            }
            $entities[] = [
                'entity_id' => $entity->entity_id,
                'name' => $entity->name,
                'type' => $entity->type,
                'org_num' => $entity->org_num ?? null,
                'email' => $entity->email ?? null,
            ];
        }
        usort($entities, fn($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
            ?: strcmp($a['entity_id'], $b['entity_id']));

        return [
            'entities' => array_slice($entities, 0, $limit),
            'truncated' => count($entities) > $limit,
        ];
    }

    private function listMyThreads(array $args, string $userId): array {
        $search = $this->optionalString($args, 'search');
        $includeArchived = $this->optionalBool($args, 'include_archived', false);
        $limit = $this->limitArg($args);
        $cursorRaw = $this->optionalString($args, 'cursor');
        $after = ($cursorRaw !== null && $cursorRaw !== '') ? $this->decodeThreadCursor($cursorRaw) : null;

        // Ignored emails are left out of the counts and last activity, as in NpApiService listings.
        $rows = Database::query(
            "SELECT t.id, t.title, t.entity_id, t.sending_status, t.archived, ta.is_owner,
                    COUNT(te.id) FILTER (WHERE te.email_type = 'IN') AS email_count_in,
                    COUNT(te.id) FILTER (WHERE te.email_type = 'OUT') AS email_count_out,
                    COALESCE(MAX(te.datetime_received), t.created_at) AS last_activity
             FROM thread_authorizations ta
             JOIN threads t ON t.id = ta.thread_id
             LEFT JOIN thread_emails te ON te.thread_id = t.id AND te.ignore IS NOT TRUE
             WHERE ta.user_id = ?
             GROUP BY t.id, ta.is_owner
             ORDER BY last_activity DESC, t.id",
            [$userId]
        );

        $entities = Entity::getAll();
        $matching = [];
        foreach ($rows as $row) {
            $archived = $row['archived'] === true || $row['archived'] === 't';
            if ($archived && !$includeArchived) {
                continue;
            }
            $entityName = isset($entities->{$row['entity_id']}) ? $entities->{$row['entity_id']}->name : null;
            if ($search !== null && $search !== ''
                && mb_stripos($row['title'] ?? '', $search) === false
                && mb_stripos($entityName ?? '', $search) === false) {
                continue;
            }
            $row['archived'] = $archived;
            $row['entity_name'] = $entityName;
            $row['sort_key'] = $this->threadSortKey($row['last_activity']);
            $matching[] = $row;
        }
        // Order: last activity newest first, then thread id. Sorted here (not only in SQL) so the
        // order is exactly the one the cursor compares against.
        usort($matching, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']) ?: strcmp($a['id'], $b['id']));
        $total = count($matching);

        // Keyset paging: continue strictly after the cursor's (sort_key, id), so threads that stop
        // matching between pages (e.g. archived) don't shift the remaining ones.
        if ($after !== null) {
            $matching = array_values(array_filter($matching, fn($row) =>
                $row['sort_key'] < $after['k'] || ($row['sort_key'] === $after['k'] && strcmp($row['id'], $after['i']) > 0)
            ));
        }
        $page = array_slice($matching, 0, $limit);
        $hasMore = count($matching) > $limit;
        $last = end($page);
        $nextCursor = ($hasMore && $last !== false) ? $this->encodeThreadCursor($last['sort_key'], $last['id']) : null;

        $stateTypes = NpApiService::threadStateTypesByThreadId(array_column($page, 'id'));
        $withProblems = $this->threadIdsWithDeliveryProblems(array_column($page, 'id'));
        $threads = [];
        foreach ($page as $row) {
            $threads[] = [
                'thread_id' => $row['id'],
                'title' => $row['title'],
                'entity_id' => $row['entity_id'],
                'entity_name' => $row['entity_name'],
                'sending_status' => $row['sending_status'],
                'thread_state_type' => $stateTypes[$row['id']] ?? null,
                'email_count_in' => (int)$row['email_count_in'],
                'email_count_out' => (int)$row['email_count_out'],
                'last_activity' => $this->isoTimestamp($row['last_activity']),
                'archived' => $row['archived'],
                'is_owner' => $row['is_owner'] === true || $row['is_owner'] === 't',
                'has_delivery_problem' => isset($withProblems[$row['id']]),
            ];
        }
        return ['threads' => $threads, 'total' => $total, 'next_cursor' => $nextCursor, 'truncated' => $hasMore];
    }

    /** Fixed-width UTC timestamp with microseconds, so plain string comparison orders it. */
    private function threadSortKey(string $lastActivity): string {
        return (new DateTime($lastActivity))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function encodeThreadCursor(string $sortKey, string $threadId): string {
        return rtrim(strtr(base64_encode(json_encode(['k' => $sortKey, 'i' => $threadId])), '+/', '-_'), '=');
    }

    /** @return array{k: string, i: string} */
    private function decodeThreadCursor(string $cursor): array {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)
            || !is_string($data['k'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $data['k'])
            || !is_string($data['i'] ?? null) || !preg_match(self::UUID_REGEX, $data['i'])) {
            throw new McpToolException('Invalid cursor');
        }
        return ['k' => $data['k'], 'i' => $data['i']];
    }

    private function getThread(array $args, string $userId): array {
        $thread = $this->loadAccessibleThread($args, $userId);
        $threadId = $thread->id;
        $export = ThreadExportService::exportThread($threadId, false);
        if ($export === null) {
            throw new McpNotFoundException();
        }

        $emails = [];
        foreach ($export['emails'] as $email) {
            unset($email['body_html'], $email['imap_headers'], $email['history']);
            $email['extractions'] = $this->reduceExtractions($email['extractions']);
            foreach ($email['attachments'] as &$attachment) {
                unset($attachment['location']);
                $attachment['extractions'] = $this->reduceExtractions($attachment['extractions']);
            }
            unset($attachment);
            $emails[] = $email;
        }

        // Raw SMTP transcripts are not useful to the model and may leak server details.
        $sendings = array_map(function ($sending) {
            unset($sending['smtp_debug'], $sending['smtp_response']);
            return $sending;
        }, $export['sendings']);

        $canReply = $this->hasWriteAccess($threadId, $userId);

        return [
            'thread' => $export['thread'],
            'entity' => $export['entity'],
            'emails' => $emails,
            'sendings' => $sendings,
            'delivery_problems' => $this->deliveryProblems($sendings),
            'can_reply' => $canReply,
            'reply_recipients' => $canReply ? array_values(getThreadReplyRecipients($thread)) : [],
            'thread_url' => NpApiService::threadUrl($threadId),
        ];
    }

    /**
     * A delivery problem is a sending that has an error_message and is not SENT. A failed send is
     * recorded by ThreadScheduledEmailSender as status READY_FOR_SENDING plus error_message (it is
     * retried). The error_message is not cleared when a retry succeeds, so SENT rows never count.
     */
    private function deliveryProblems(array $sendings): array {
        $problems = [];
        foreach ($sendings as $sending) {
            if ($sending['error_message'] === null || $sending['status'] === ThreadEmailSending::STATUS_SENT) {
                continue;
            }
            $problems[] = [
                'sending_id' => $sending['id'],
                'recipient' => $sending['email_to'],
                'subject' => $sending['email_subject'],
                'status' => $sending['status'],
                'error' => $sending['error_message'],
                'updated_at' => $sending['updated_at'],
            ];
        }
        return $problems;
    }

    /**
     * Same definition as deliveryProblems(), for many threads in one query.
     * @param string[] $threadIds
     * @return array<string, true> thread ids that have a delivery problem
     */
    private function threadIdsWithDeliveryProblems(array $threadIds): array {
        if ($threadIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $rows = Database::query(
            "SELECT DISTINCT thread_id FROM thread_email_sendings
             WHERE thread_id IN ($placeholders) AND error_message IS NOT NULL AND status <> ?",
            [...array_values($threadIds), ThreadEmailSending::STATUS_SENT]
        );
        return array_fill_keys(array_column($rows, 'thread_id'), true);
    }

    private function startThread(array $args, string $userId): array {
        $entityId = $this->requiredString($args, 'entity_id');
        $title = $this->requiredString($args, 'title');
        $body = $this->requiredString($args, 'body');
        $lawBasis = $this->optionalString($args, 'law_basis') ?? Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
        $plan = $this->optionalString($args, 'follow_up_plan') ?? Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;

        if (!in_array($lawBasis, [Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, Thread::REQUEST_LAW_BASIS_OTHER], true)) {
            throw new McpToolException('Invalid law_basis');
        }
        if ($plan !== 'none' && !in_array($plan, Thread::REQUEST_FOLLOW_UP_PLANS, true)) {
            throw new McpToolException('Invalid follow_up_plan');
        }
        if (trim($title) === '' || trim($body) === '') {
            throw new McpToolException('title and body are required');
        }
        if (!Entity::exists($entityId)) {
            throw new McpToolException("Unknown entity_id: $entityId");
        }
        $entity = Entity::getById($entityId);
        if (trim((string)($entity->email ?? '')) === '') {
            throw new McpToolException("Entity has no email address: $entityId");
        }
        if (Entity::isDefunct($entity)) {
            throw new McpToolException("Entity no longer exists: $entityId");
        }

        // Tests run inside one transaction; PDO has no nested transactions.
        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }
        try {
            $thread = (new ThreadCreationService())->createThread(
                $entityId, $title, $body, $userId, $lawBasis,
                $plan === 'none' ? null : $plan,
                true, false, [], ['via' => 'mcp']
            );
            if ($ownsTransaction) {
                Database::commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'thread_id' => $thread->id,
            'thread_url' => NpApiService::threadUrl($thread->id),
            'sending_status' => ThreadEmailSending::STATUS_READY_FOR_SENDING,
            'my_name' => $thread->my_name,
            'my_email' => $thread->my_email,
        ];
    }

    private function sendReply(array $args, string $userId): array {
        $threadId = $this->requireUuid($args['thread_id'] ?? null, 'thread_id');
        $subject = $this->requiredString($args, 'subject');
        $body = $this->requiredString($args, 'body');
        $recipient = $this->requiredString($args, 'recipient');

        $thread = $this->hasWriteAccess($threadId, $userId) ? Thread::loadFromDatabaseOrNone($threadId) : null;
        if ($thread === null) {
            throw new McpNotFoundException();
        }

        try {
            $sending = (new ThreadReplyService())->queueReply(
                $thread, trim($subject), trim($body), $recipient, $userId, true, ['via' => 'mcp']
            );
        } catch (ThreadReplyValidationException $e) {
            throw new McpToolException($e->getMessage());
        }

        return [
            'email_sending_id' => $sending->id,
            'thread_id' => $threadId,
            'recipient' => $sending->email_to,
            'status' => $sending->status,
        ];
    }

    private function archiveThread(array $args, string $userId): array {
        $threadId = $this->requireUuid($args['thread_id'] ?? null, 'thread_id');
        $archived = $args['archived'] ?? true;
        if (!is_bool($archived)) {
            throw new McpToolException('archived must be a boolean');
        }

        $thread = $this->hasWriteAccess($threadId, $userId) ? Thread::loadFromDatabaseOrNone($threadId) : null;
        if ($thread === null) {
            throw new McpNotFoundException();
        }

        $changed = $thread->archived !== $archived;
        if ($changed) {
            $thread->archived = $archived;
            // Same path as the web UI's archive action; it logs archived/unarchived to thread history.
            ThreadStorageManager::getInstance()->updateThread($thread, $userId, ['via' => 'mcp']);
        }

        return ['thread_id' => $threadId, 'archived' => $archived, 'changed' => $changed];
    }

    /**
     * Writes need an owner or granted row. ThreadAuthorizationManager::canUserAccessThread()
     * is not usable here: it also returns true for public threads.
     */
    private function hasWriteAccess(string $threadId, string $userId): bool {
        return Database::queryOneOrNone(
            "SELECT 1 AS ok FROM thread_authorizations WHERE thread_id = ? AND user_id = ?",
            [$threadId, $userId]
        ) !== null;
    }

    /** Loads the thread named by $args['thread_id'], or throws McpNotFoundException if missing or not readable by the user. */
    private function loadAccessibleThread(array $args, string $userId): Thread {
        $threadId = $this->requireUuid($args['thread_id'] ?? null, 'thread_id');
        $thread = Thread::loadFromDatabaseOrNone($threadId);
        if ($thread === null || !$thread->canUserAccess($userId)) {
            throw new McpNotFoundException();
        }
        return $thread;
    }

    /**
     * Metadata of an attachment that belongs to one of the thread's emails, without loading its content.
     * Throws McpNotFoundException when the attachment id is invalid or belongs to another thread.
     */
    private function loadThreadAttachmentInfo(Thread $thread, array $args): array {
        $attachmentId = $this->requireUuid($args['attachment_id'] ?? null, 'attachment_id');
        $row = Database::queryOneOrNone(
            "SELECT tea.id, tea.name, tea.filename, tea.filetype, tea.size,
                    tea.content IS NULL AS content_missing, octet_length(tea.content) AS content_length
             FROM thread_email_attachments tea
             JOIN thread_emails te ON te.id = tea.email_id
             WHERE te.thread_id = ? AND tea.id = ?",
            [$thread->id, $attachmentId]
        );
        if ($row === null) {
            throw new McpNotFoundException();
        }
        return $row;
    }

    private function getAttachmentLink(array $args, string $userId): array {
        $thread = $this->loadAccessibleThread($args, $userId);
        $info = $this->loadThreadAttachmentInfo($thread, $args);
        $inline = $this->optionalBool($args, 'inline', false);

        if ($info['content_missing'] === true || $info['content_missing'] === 't') {
            throw new McpToolException('Attachment content was never stored; try get_attachment_extraction');
        }
        if ($this->links === null || $this->resource === null) {
            throw new McpToolException('Attachment links are not configured');
        }
        $size = $info['size'] !== null ? (int)$info['size'] : (int)$info['content_length'];
        $mimeType = NpApiService::attachmentContentType($info['filetype']);
        $token = $this->links->create($userId, $thread->id, $info['id'], ($this->clock)());
        $summary = [
            'name' => $info['name'],
            'filename' => $info['filename'],
            'mimeType' => $mimeType,
            'size' => $size,
            'download_url' => rtrim($this->resource, '/') . '/attachment/' . $token,
            'expires_in' => McpAttachmentLink::TTL_SECONDS,
        ];
        if (!$inline) {
            return $this->textResult($summary);
        }

        $cap = self::$maxRawBytesOverride ?? self::MAX_RAW_ATTACHMENT_BYTES;
        // Checked before the content is loaded or base64-encoded.
        if ($size > $cap) {
            throw new McpToolException('Attachment is larger than 5 MB; use get_attachment_extraction for its text');
        }

        $attachment = ThreadStorageManager::getInstance()->getThreadEmailAttachment($thread, $info['id']);
        $data = base64_encode($attachment->content);

        if (str_starts_with($mimeType, 'image/')) {
            return [['type' => 'image', 'data' => $data, 'mimeType' => $mimeType]];
        }
        return [
            $this->textResult($summary)[0],
            ['type' => 'resource', 'resource' => [
                'uri' => "offpost://thread/{$thread->id}/attachment/{$info['id']}",
                'mimeType' => $mimeType,
                'blob' => $data,
            ]],
        ];
    }

    private function getAttachmentExtraction(array $args, string $userId): array {
        $thread = $this->loadAccessibleThread($args, $userId);
        $info = $this->loadThreadAttachmentInfo($thread, $args);
        $promptId = $this->optionalString($args, 'prompt_id');

        $extractions = [];
        foreach ((new ThreadEmailExtractionService())->getExtractionsForAttachment($info['id']) as $e) {
            if ($promptId !== null && $e->prompt_id !== $promptId) {
                continue;
            }
            $extractions[] = [
                'extraction_id' => $e->extraction_id,
                'prompt_id' => $e->prompt_id,
                'prompt_service' => $e->prompt_service,
                'extracted_text' => $e->extracted_text,
                'error_message' => $e->error_message,
                'created_at' => $this->isoTimestamp($e->created_at),
                'updated_at' => $this->isoTimestamp($e->updated_at),
            ];
        }
        return ['attachment_id' => $info['id'], 'extractions' => $extractions];
    }

    private function reduceExtractions(array $extractions): array {
        return array_map(fn($e) => [
            'extraction_id' => $e['extraction_id'],
            'prompt_id' => $e['prompt_id'],
            'prompt_service' => $e['prompt_service'],
            'has_text' => $e['extracted_text'] !== null && $e['extracted_text'] !== '',
            'error_message' => $e['error_message'],
        ], $extractions);
    }
}

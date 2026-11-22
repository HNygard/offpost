<?php

require_once __DIR__ . '/McpToolProvider.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Entity.php';
require_once __DIR__ . '/../Thread.php';
require_once __DIR__ . '/../NpApiService.php';
require_once __DIR__ . '/../ThreadExportService.php';

/**
 * The tools served by the /mcp endpoint. Every tool returns one JSON text item.
 *
 * Access rules: reads of a single thread use Thread::canUserAccess() (public
 * threads are readable); list_my_threads uses thread_authorizations rows only.
 */
class McpTools implements McpToolProvider {
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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
                    . 'Matches a case-insensitive part of the name, or an exact organization number. '
                    . 'Returns entity_id, which is needed when starting a thread.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Part of the entity name, or an exact organization number.'],
                        'limit' => $limit,
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'list_my_threads',
                'description' => 'List the threads (requests and their replies) you own or have been given access to, newest activity first. '
                    . 'Use this to find thread ids for get_thread and the other thread tools.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Part of the thread title or entity name.'],
                        'include_archived' => ['type' => 'boolean', 'description' => 'Also list archived threads (default false).'],
                        'limit' => $limit,
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_thread',
                'description' => 'Get one thread with its entity, emails (plain text body, classification, attachments with extraction summaries) '
                    . 'and queued or sent outgoing emails. Thread ids come from list_my_threads. Public threads of other users are readable too.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'string', 'description' => 'Thread id (UUID) from list_my_threads.'],
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

    private function requireUuid(mixed $v): string {
        if (!is_string($v) || !preg_match(self::UUID_REGEX, $v)) {
            throw new McpNotFoundException();
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
            if ($search !== null && $search !== '') {
                $matchesName = mb_stripos($entity->name ?? '', $search) !== false;
                $matchesOrgNum = ($entity->org_num ?? null) === $search;
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
            $matching[] = $row;
        }
        $page = array_slice($matching, 0, $limit);

        $stateTypes = NpApiService::threadStateTypesByThreadId(array_column($page, 'id'));
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
            ];
        }
        return ['threads' => $threads, 'truncated' => count($matching) > $limit];
    }

    private function getThread(array $args, string $userId): array {
        $threadId = $this->requireUuid($args['thread_id'] ?? null);
        $thread = Thread::loadFromDatabaseOrNone($threadId);
        if ($thread === null || !$thread->canUserAccess($userId)) {
            throw new McpNotFoundException();
        }
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

        return [
            'thread' => $export['thread'],
            'entity' => $export['entity'],
            'emails' => $emails,
            'sendings' => $export['sendings'],
            'thread_url' => NpApiService::threadUrl($threadId),
        ];
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

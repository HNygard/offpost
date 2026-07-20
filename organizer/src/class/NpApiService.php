<?php
// organizer/src/class/NpApiService.php
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadStorageManager.php';
require_once __DIR__ . '/ThreadEmailSending.php';
require_once __DIR__ . '/Entity.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/random-profile.php';

class NpApiEntityNotFoundException extends Exception {}
class NpApiValidationException extends Exception {}
class NpApiCapExceededException extends Exception {}

class NpApiService {
    const THREAD_OWNER_USER_ID = 'norske-postlister-api';
    const DAILY_CAP = 100;
    const NP_LABEL = 'norske_postlister_no';

    /** @var ?int test hook */
    public static $dailyCapOverride = null;

    public static function createThread(string $npEntityId, string $title, string $body, array $labels): array {
        if (trim($title) === '' || trim($body) === '') {
            throw new NpApiValidationException('title and body are required');
        }
        $mappingLabel = self::mappingLabel($labels);
        if ($mappingLabel === null) {
            throw new NpApiValidationException('labels must contain a document_id: or case_num: label');
        }
        $entity = Entity::getByNorskePostlisterId($npEntityId);
        if ($entity === null) {
            throw new NpApiEntityNotFoundException('No entity for norske-postlister id: ' . $npEntityId);
        }
        if ($entity->email === null || $entity->email === '') {
            throw new NpApiValidationException('Entity has no email address: ' . $npEntityId);
        }

        $existing = self::findExistingThread($entity->entity_id, $labels);
        if ($existing !== null) {
            return [
                'created' => false,
                'existing' => true,
                'thread_id' => $existing->id,
                'thread_url' => self::threadUrl($existing->id),
                'status' => ThreadStatusRepository::getThreadStatus($existing->id),
            ];
        }

        $cap = self::$dailyCapOverride ?? self::DAILY_CAP;
        $createdToday = Database::queryValue(
            "SELECT count(*) FROM thread_history
             WHERE user_id = ? AND action = 'created' AND created_at >= date_trunc('day', now())",
            [self::THREAD_OWNER_USER_ID]
        );
        if ($createdToday >= $cap) {
            throw new NpApiCapExceededException('Daily thread creation cap reached: ' . $cap);
        }

        $profile = getRandomNameAndEmail();
        // Matches the name assembly used in start-thread.php: middleName already
        // carries its own leading space (or is '') from random-profile.php.
        $myName = $profile->firstName . $profile->middleName . ' ' . $profile->lastName;

        // Only wrap in our own transaction when we're not already inside one.
        // Real callers (HTTP endpoint) invoke this without an ambient
        // transaction, so we get atomicity across the thread + email-sending
        // inserts. Tests wrap the whole test in a transaction for rollback-based
        // isolation; PDO/Postgres has no true nested transactions, so a second
        // beginTransaction() there would throw "already an active transaction".
        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }
        try {
            $thread = new Thread();
            $thread->title = $title;
            $thread->my_name = $myName;
            $thread->my_email = $profile->email;
            $thread->labels = array_values(array_filter(array_map('trim', $labels)));
            $thread->initial_request = $body;
            $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
            $thread->sent = false;
            $thread->archived = false;
            $thread->public = true;
            $thread->request_law_basis = Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
            $thread->request_follow_up_plan = Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;
            $thread->emails = [];

            $newThread = ThreadStorageManager::getInstance()
                ->createThread($entity->entity_id, $thread, self::THREAD_OWNER_USER_ID);
            $newThread->addUser(self::THREAD_OWNER_USER_ID, true);

            ThreadEmailSending::create(
                $newThread->id,
                $body . "\n\n--\n" . $myName,
                $title,
                $entity->email,
                $profile->email,
                $myName,
                ThreadEmailSending::STATUS_READY_FOR_SENDING
            );
            if ($ownsTransaction) {
                Database::commit();
            }
        } catch (Exception $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'created' => true,
            'existing' => false,
            'thread_id' => $newThread->id,
            'thread_url' => self::threadUrl($newThread->id),
            'status' => ThreadStatusRepository::getThreadStatus($newThread->id),
        ];
    }

    /** The label that identifies the doc/case this thread requests. */
    public static function mappingLabel(array $labels): ?string {
        foreach ($labels as $label) {
            if (str_starts_with($label, 'document_id:') || str_starts_with($label, 'case_num:')) {
                return $label;
            }
        }
        return null;
    }

    public static function findExistingThread(string $entityId, array $labels): ?Thread {
        $mappingLabel = self::mappingLabel($labels);
        $row = Database::queryOneOrNone(
            "SELECT id FROM threads
             WHERE entity_id = ? AND archived = false AND ? = ANY(labels)",
            [$entityId, $mappingLabel]
        );
        return $row === null ? null : Thread::loadFromDatabase($row['id']);
    }

    public static function threadUrl(string $threadId): string {
        global $environment;
        $base = ($environment ?? 'production') === 'development'
            ? 'http://localhost:25081' : 'https://offpost.no';
        return $base . '/thread-view?threadId=' . urlencode($threadId);
    }
}

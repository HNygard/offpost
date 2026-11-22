<?php

require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadStorageManager.php';
require_once __DIR__ . '/Entity.php';
require_once __DIR__ . '/ThreadEmailSending.php';
require_once __DIR__ . '/random-profile.php';

/**
 * Creates one thread for one entity, queueing the initial request email.
 *
 * Shared by the start-thread page and the MCP start_thread tool so both follow
 * the same rules. The caller owns the database transaction and authorization.
 */
class ThreadCreationService {
    /**
     * @param string[] $labels
     * @param array $historyDetails Extra details stored on the 'created' history row
     * @throws InvalidArgumentException If the entity does not exist
     */
    public function createThread(
        string $entityId,
        string $title,
        string $body,
        string $userId,
        string $lawBasis,
        ?string $followUpPlan,
        bool $sendNow,
        bool $public,
        array $labels,
        array $historyDetails = []
    ): Thread {
        $entity = Entity::getById($entityId);

        $signDelim = mt_rand(0, 10) > 5 ? '---' : '--';
        $obj = getRandomNameAndEmail();
        $my_name = $obj->firstName . $obj->middleName . ' ' . $obj->lastName;
        $my_email = $obj->email;

        $thread = new Thread();
        $thread->title = $title;
        $thread->my_name = $my_name;
        $thread->my_email = $my_email;
        $thread->labels = $labels;
        $thread->initial_request = $body; // Store the initial request
        $thread->sending_status = $sendNow
            ? Thread::SENDING_STATUS_READY_FOR_SENDING
            : Thread::SENDING_STATUS_STAGING;
        $thread->sent = false; // Will be updated if email is sent
        $thread->archived = false;
        $thread->public = $public;
        $thread->request_law_basis = $lawBasis;
        $thread->request_follow_up_plan = $followUpPlan;
        $thread->emails = array();

        $newThread = ThreadStorageManager::getInstance()->createThread(
            $entityId,
            $thread,
            $userId,
            $historyDetails
        );

        // Set creator as owner using OpenID Connect subject identifier
        $newThread->addUser($userId, true);

        // Create a ThreadEmailSending record for this thread
        ThreadEmailSending::create(
            $newThread->id,
            $body . "\n\n$signDelim\n" . $my_name,
            $title,
            $entity->email,
            $my_email,
            $my_name,
            $sendNow
                ? ThreadEmailSending::STATUS_READY_FOR_SENDING
                : ThreadEmailSending::STATUS_STAGING
        );

        return $newThread;
    }
}

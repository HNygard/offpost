<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';
require_once __DIR__ . '/../class/ThreadDatabaseOperations.php';
require_once __DIR__ . '/../class/ThreadEmailClassifier.php';

/**
 * Regression: no loader mapped the auto_classification column onto ThreadEmail,
 * so getClassificationLabel() saw an unset property and reported every
 * classified email as "Classified by Human", whatever had classified it.
 */
class ThreadAutoClassificationLoadingTest extends TestCase {
    private $threadId;
    private $emailId;

    protected function setUp(): void {
        Database::beginTransaction();

        $created = NpApiService::createThread('9999-test-entity-development', 'T', 'B',
            ['norske_postlister_no', 'document', 'document_id:2030-1-2']);
        $this->threadId = $created['thread_id'];

        $this->emailId = Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type,
                 status_type, status_text, auto_classification, content, imap_headers)
             VALUES (?, now(), now(), 'IN', 'INFORMATION_RELEASE', 'Svar', 'prompt', ?::bytea, NULL)
             RETURNING id",
            [$this->threadId, 'content']
        );
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function findEmail($thread) {
        foreach ($thread->emails as $email) {
            if ($email->id === $this->emailId) {
                return $email;
            }
        }
        $this->fail('Email ' . $this->emailId . ' not found in loaded thread');
    }

    public function testLoadFromDatabaseMapsAutoClassification(): void {
        // :: Setup
        $threadId = $this->threadId;

        // :: Act
        $thread = Thread::loadFromDatabase($threadId);
        $email = $this->findEmail($thread);

        // :: Assert
        $this->assertEquals('prompt', $email->auto_classification);
    }

    public function testGetThreadsForEntityMapsAutoClassification(): void {
        // :: Setup
        $operations = new ThreadDatabaseOperations();

        // :: Act
        $threads = $operations->getThreadsForEntity('000000000-test-entity-development');
        $email = null;
        foreach ($threads->threads as $thread) {
            if ($thread->id === $this->threadId) {
                $email = $this->findEmail($thread);
            }
        }

        // :: Assert
        $this->assertNotNull($email, 'Thread ' . $this->threadId . ' not found for entity');
        $this->assertEquals('prompt', $email->auto_classification);
    }

    public function testGetThreadsMapsAutoClassification(): void {
        // :: Setup
        $operations = new ThreadDatabaseOperations();

        // :: Act
        $threadsByFile = $operations->getThreads(null);
        $email = null;
        foreach ($threadsByFile as $threads) {
            foreach ($threads->threads as $thread) {
                if ($thread->id === $this->threadId) {
                    $email = $this->findEmail($thread);
                }
            }
        }

        // :: Assert
        $this->assertNotNull($email, 'Thread ' . $this->threadId . ' not found in getThreads()');
        $this->assertEquals('prompt', $email->auto_classification);
    }

    public function testClassificationLabelReportsAiNotHuman(): void {
        // :: Setup
        $thread = Thread::loadFromDatabase($this->threadId);
        $email = $this->findEmail($thread);

        // :: Act
        $label = ThreadEmailClassifier::getClassificationLabel($email);

        // :: Assert
        $this->assertEquals('AI', $label);
    }

    public function testHumanClassifiedEmailStillReportsHuman(): void {
        // :: Setup
        Database::execute(
            "UPDATE thread_emails SET auto_classification = NULL WHERE id = ?", [$this->emailId]);
        $thread = Thread::loadFromDatabase($this->threadId);
        $email = $this->findEmail($thread);

        // :: Act
        $label = ThreadEmailClassifier::getClassificationLabel($email);

        // :: Assert
        $this->assertEquals('Human', $label);
    }
}

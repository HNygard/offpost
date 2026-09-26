<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpThreadAutoArchiver.php';
require_once __DIR__ . '/../class/ImapFolderStatus.php';

class NpThreadAutoArchiverDatabaseTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Database::beginTransaction();
        // Only the threads made here are candidates.
        Database::execute("UPDATE threads SET archived = true WHERE ? = ANY(labels)", [NpApiService::NP_LABEL]);
        Database::execute("UPDATE imap_folder_status SET last_checked_at = NULL");
        ImapFolderStatus::createOrUpdate('INBOX', updateLastChecked: true);
        ImapFolderStatus::createOrUpdate('INBOX.Sent', updateLastChecked: true);
    }

    protected function tearDown(): void {
        Database::rollBack();
        parent::tearDown();
    }

    private function createNpThread(string $name, array $inStatusTypes): string {
        $thread = new Thread();
        $thread->title = "Auto archive test $name";
        $thread->my_name = "Test User";
        $thread->my_email = "np-auto-archive-$name@example.com";
        $thread->labels = [NpApiService::NP_LABEL, 'case', "case_num:2026-$name"];
        $thread->sending_status = Thread::SENDING_STATUS_SENT;
        $thread->sent = true;
        $thread->archived = false;
        $thread->public = true;
        $thread = createThread('000000000-test-entity-development', $thread);
        ImapFolderStatus::createOrUpdate("INBOX.auto-archive-$name", $thread->id, updateLastChecked: true);

        Database::execute(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, content)
             VALUES (?, '2026-09-01 10:00:00+00', '2026-09-01 10:00:00+00', 'OUT', 'OUR_REQUEST', '', 'content')",
            [$thread->id]
        );
        foreach ($inStatusTypes as $i => $statusType) {
            $received = sprintf('2026-09-%02d 10:00:00+00', 2 + $i);
            Database::execute(
                "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, content)
                 VALUES (?, ?, ?, 'IN', ?, '', 'content')",
                [$thread->id, $received, $received, $statusType]
            );
        }
        return $thread->id;
    }

    public function testArchivesOnlyFinishedThreads() {
        // :: Setup
        $finished = $this->createNpThread('finished', ['REQUEST_RECEIPT', 'INFORMATION_RELEASE']);
        $unclassified = $this->createNpThread('unclassified', ['REQUEST_RECEIPT', 'unknown']);

        // :: Act
        $result = (new NpThreadAutoArchiver())->archiveFinishedThreads(false);

        // :: Assert
        $this->assertEquals([$finished], $result['archived'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertEquals('incoming email classified unknown needs a human', $result['skipped'][$unclassified] ?? null);
        $this->assertEquals("true", Database::queryValue("SELECT archived::text FROM threads WHERE id = ?", [$finished]));
        $this->assertEquals("false", Database::queryValue("SELECT archived::text FROM threads WHERE id = ?", [$unclassified]));
        $this->assertEquals(
            [['action' => 'archived', 'user_id' => NpThreadAutoArchiver::HISTORY_USER_ID]],
            Database::query("SELECT action, user_id FROM thread_history WHERE thread_id = ? AND action = 'archived'", [$finished]));
    }

    public function testDryRunDoesNotArchive() {
        // :: Setup
        $finished = $this->createNpThread('dryrun', ['INFORMATION_RELEASE']);

        // :: Act
        $result = (new NpThreadAutoArchiver())->archiveFinishedThreads(true);

        // :: Assert
        $this->assertEquals([$finished], $result['archived'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertEquals("false", Database::queryValue("SELECT archived::text FROM threads WHERE id = ?", [$finished]));
    }
}

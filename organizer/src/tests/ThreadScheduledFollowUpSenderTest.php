<?php

require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../class/Entity.php';

use PHPUnit\Framework\TestCase;

class ThreadScheduledFollowUpSenderTest extends TestCase {
    private $sender;
    private $mockThread;
    private $mockEntity;
    private $tearDownCallbacks = [];
    
    protected function setUp(): void {
        // Create a partial mock of ThreadScheduledFollowUpSender
        $this->sender = $this->getMockBuilder(ThreadScheduledFollowUpSender::class)
            ->onlyMethods(['findNextThreadForProcessing', 'createFollowUpEmailContent', 'findNextPostlisteFollowUp'])
            ->getMock();
        $this->sender->method('findNextPostlisteFollowUp')
            ->willReturn(null);
        
        // Create a mock Thread object
        $this->mockThread = $this->createMock(Thread::class);
        
        // Create a mock Entity object
        $this->mockEntity = new stdClass();
        $this->mockEntity->email = 'entity@example.com';
    }
    
    protected function tearDown(): void {
        // Execute any teardown callbacks
        foreach ($this->tearDownCallbacks as $callback) {
            $callback();
        }
    }
    
    /**
     * Test sendNextFollowUpEmail when no threads are available
     */
    public function testSendNextFollowUpEmailNoThreads() {
        // :: Setup
        $this->sender->method('findNextThreadForProcessing')
            ->willReturn(null);
        
        // :: Act
        $result = $this->sender->sendNextFollowUpEmail();
        
        // :: Assert
        $this->assertFalse($result['success'], 'Should return success=false when no threads are available');
        $this->assertEquals('No threads ready for follow-up', $result['message'], 'Should return appropriate message when no threads are available');
    }
    
    /**
     * Test sendNextFollowUpEmail when entity is not found
     */
    public function testSendNextFollowUpEmailNoEntity() {
        // :: Setup
        $this->mockThread->id = 'test-thread-id';
        $this->mockThread->title = 'Test Thread';
        $this->mockThread->method('getEntity')
            ->willReturn(null);
        
        $this->sender->method('findNextThreadForProcessing')
            ->willReturn($this->mockThread);
        
        // :: Act
        $result = $this->sender->sendNextFollowUpEmail();
        
        // :: Assert
        $this->assertFalse($result['success'], 'Should return success=false when entity is not found');
        $this->assertEquals('Entity not found for thread', $result['message'], 'Should return appropriate message when entity is not found');
    }
    
    /**
     * Test createFollowUpEmailContent with valid thread
     */
    public function testCreateFollowUpEmailContent() {
        // :: Setup
        // Use the real ThreadScheduledFollowUpSender for this test
        $sender = new ThreadScheduledFollowUpSender();
        
        // Create a thread with one email
        $thread = new Thread();
        $thread->id = 'test-thread-id';
        $thread->title = 'Test Thread Title';
        $thread->my_name = 'Sender Name';
        
        // Create an email
        $email = new ThreadEmail();
        $email->email_type = 'OUT';
        $email->timestamp_received = time();
        
        // Add the email to the thread
        $thread->emails = [$email];
        
        // :: Act
        $reflection = new ReflectionClass(ThreadScheduledFollowUpSender::class);
        $method = $reflection->getMethod('createFollowUpEmailContent');
        $method->setAccessible(true);
        
        $content = $method->invoke($sender, $thread);
        
        // :: Assert
        $this->assertStringContainsString('Hei,', $content, 'Follow-up email should start with greeting');
        $this->assertStringContainsString('Test Thread Title', $content, 'Follow-up email should contain thread title');
        $this->assertStringContainsString('Vennligst gi meg en oppdatering', $content, 'Follow-up email should ask for update');
        $this->assertStringContainsString('Med vennlig hilsen,', $content, 'Follow-up email should end with closing');
        $this->assertStringContainsString('Sender Name', $content, 'Follow-up email should include sender name');
    }
    
    /**
     * Test createFollowUpEmailContent with invalid thread (no emails)
     */
    public function testCreateFollowUpEmailContentNoEmails() {
        // :: Setup
        // Use the real ThreadScheduledFollowUpSender for this test
        $sender = new ThreadScheduledFollowUpSender();
        
        // Create a thread with no emails
        $thread = new Thread();
        $thread->id = 'test-thread-id';
        $thread->title = 'Test Thread Title';
        $thread->my_name = 'Sender Name';
        $thread->emails = [];
        
        // :: Act & Assert
        $reflection = new ReflectionClass(ThreadScheduledFollowUpSender::class);
        $method = $reflection->getMethod('createFollowUpEmailContent');
        $method->setAccessible(true);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Thread should have exactly one email for this 'follow up implementation' to work");
        
        $method->invoke($sender, $thread);
    }
    
    /**
     * Test createFollowUpEmailContent with invalid thread (wrong email type)
     */
    public function testCreateFollowUpEmailContentWrongEmailType() {
        // :: Setup
        // Use the real ThreadScheduledFollowUpSender for this test
        $sender = new ThreadScheduledFollowUpSender();
        
        // Create a thread with one email of wrong type
        $thread = new Thread();
        $thread->id = 'test-thread-id';
        $thread->title = 'Test Thread Title';
        $thread->my_name = 'Sender Name';
        
        // Create an email with wrong type
        $email = new ThreadEmail();
        $email->email_type = 'IN';  // Should be OUT
        $email->timestamp_received = time();
        
        // Add the email to the thread
        $thread->emails = [$email];
        
        // :: Act & Assert
        $reflection = new ReflectionClass(ThreadScheduledFollowUpSender::class);
        $method = $reflection->getMethod('createFollowUpEmailContent');
        $method->setAccessible(true);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Thread should have exactly one email of type OUT for this 'follow up implementation' to work");
        
        $method->invoke($sender, $thread);
    }
    
    /**
     * Test sendNextFollowUpEmail with a successful email creation
     * 
     * @group database-independent
     */
    public function testSendNextFollowUpEmailSuccess() {
        // :: Setup
        // Skip this test if we can't mock static methods properly
        $this->markTestSkipped('This test requires proper static method mocking which is not available in this environment.');
    }

    // --- plan `postliste`: reminder templates ---

    private function postlisteThread(array $labels): Thread {
        $thread = new Thread();
        $thread->id = 'test-thread-id';
        $thread->title = 'Innsyn i offentlig journal uke 38 2026';
        $thread->my_name = 'Kari Nordmann';
        $thread->labels = $labels;
        $thread->emails = [];
        return $thread;
    }

    private function postlisteDecision(int $reminder): PostlisteFollowUpDecision {
        $decision = new PostlisteFollowUpDecision();
        $decision->reminder = $reminder;
        $decision->firstOutSent = strtotime('2026-09-20T08:00:00+00:00'); // 10:00 Oslo
        $decision->anchor = $decision->firstOutSent;
        return $decision;
    }

    private function invokePostlisteContent(Thread $thread, $entity, PostlisteFollowUpDecision $decision): string {
        $method = (new ReflectionClass(ThreadScheduledFollowUpSender::class))->getMethod('createPostlisteFollowUpEmailContent');
        $method->setAccessible(true);
        return $method->invoke(new ThreadScheduledFollowUpSender(), $thread, $entity, $decision);
    }

    public function testPostlisteReminder1Content(): void {
        // :: Setup
        $thread = $this->postlisteThread(['postliste', 'postliste:2026-W38']);
        $entity = new stdClass();
        $entity->type = 'health';

        // :: Act
        $content = $this->invokePostlisteContent($thread, $entity, $this->postlisteDecision(1));

        // :: Assert
        $this->assertEquals(
            "Hei,\n\n"
            . "Jeg viser til innsynskrav «Innsyn i offentlig journal uke 38 2026» sendt 20.09.2026"
            . " om offentlig journal for perioden 14.09.2026 – 20.09.2026."
            . " Kravet skal etter offentleglova § 29 avgjøres uten ugrunnet opphold."
            . " Jeg ber om at journalen sendes snarest."
            . "\n\nMed vennlig hilsen,\nKari Nordmann",
            $content
        );
    }

    public function testPostlisteReminder2AddsRefusalWordingWithKlageinstans(): void {
        // :: Setup
        $thread = $this->postlisteThread(['postliste', 'postliste:2025-01--2025-06']);
        $entity = new stdClass();
        $entity->type = 'municipality';

        // :: Act
        $content = $this->invokePostlisteContent($thread, $entity, $this->postlisteDecision(2));

        // :: Assert
        $this->assertEquals(
            "Hei,\n\n"
            . "Jeg viser til innsynskrav «Innsyn i offentlig journal uke 38 2026» sendt 20.09.2026"
            . " om offentlig journal for perioden 01.01.2025 – 30.06.2025."
            . " Kravet skal etter offentleglova § 29 avgjøres uten ugrunnet opphold."
            . " Jeg ber om at journalen sendes snarest."
            . "\n\n"
            . "Dersom kravet ikke blir behandlet, regnes det som avslag etter § 32 tredje ledd,"
            . " og jeg vil vurdere å klage til Statsforvalteren."
            . "\n\nMed vennlig hilsen,\nKari Nordmann",
            $content
        );
    }

    public function testPostlisteContentWithoutPeriodLabel(): void {
        // Old hand-made threads carry `postliste:2011-2021`, which does not parse.
        $thread = $this->postlisteThread(['postliste', 'postliste:2011-2021']);
        $entity = new stdClass();
        $entity->type = 'ministry';

        $content = $this->invokePostlisteContent($thread, $entity, $this->postlisteDecision(2));

        $this->assertEquals(
            "Hei,\n\n"
            . "Jeg viser til innsynskrav «Innsyn i offentlig journal uke 38 2026» sendt 20.09.2026"
            . " om offentlig journal."
            . " Kravet skal etter offentleglova § 29 avgjøres uten ugrunnet opphold."
            . " Jeg ber om at journalen sendes snarest."
            . "\n\n"
            . "Dersom kravet ikke blir behandlet, regnes det som avslag etter § 32 tredje ledd,"
            . " og jeg vil vurdere å klage til klageinstansen."
            . "\n\nMed vennlig hilsen,\nKari Nordmann",
            $content
        );
    }

    /**
     * @dataProvider entityTypes
     */
    public function testKlageinstansForEntityType(?string $type, string $expected): void {
        $this->assertEquals($expected, ThreadScheduledFollowUpSender::klageinstansForEntityType($type));
    }

    public static function entityTypes(): array {
        return [
            ['municipality', 'Statsforvalteren'],
            ['municipality-unit', 'Statsforvalteren'],
            ['county', 'Statsforvalteren'],
            ['county-unit', 'Statsforvalteren'],
            ['agency', 'overordnet departement'],
            ['directorate', 'overordnet departement'],
            ['health', 'overordnet departement'],
            ['technical', 'overordnet departement'],
            ['minitry', 'klageinstansen'],
            ['test', 'klageinstansen'],
            [null, 'klageinstansen'],
        ];
    }
}

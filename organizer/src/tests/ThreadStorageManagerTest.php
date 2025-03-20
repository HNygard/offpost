<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';

class ThreadStorageManagerTest extends PHPUnit\Framework\TestCase {

    public function testGetThreadEmailAttachment() {
        // :: Setup
        
        // Create a test thread in the database with a unique email
        $uniqueEmail = 'test' . mt_rand(1000, 9999) . time() . '@example.com';
        $testThreadId = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread', 'Test User', ?) 
             RETURNING id",
            [$uniqueEmail]
        );

        // Create a test email
        $testEmailId = Database::queryValue(
            "INSERT INTO thread_emails (id, thread_id, timestamp_received, content) 
             VALUES (gen_random_uuid(), ?, NOW(), 'test content') 
             RETURNING id",
            [$testThreadId]
        );

        $testLocation = 'test-location-' . uniqid();
        $testContent = 'test-content-data';
        
        // Create test attachment in database
        Database::execute(
            "INSERT INTO thread_email_attachments (
                email_id, name, filename, filetype, location, status_type, status_text, content
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $testEmailId,
                'test.pdf',
                '/path/to/test.pdf',
                'application/pdf',
                $testLocation,
                'processed',
                'Successfully processed',
                $testContent
            ]
        );

        // Create thread object for test
        $thread = new Thread();
        $thread->id = $testThreadId;

        // :: Act
        $attachment = ThreadStorageManager::getInstance()->getThreadEmailAttachment($thread, $testLocation);

        // :: Assert
        $this->assertInstanceOf(ThreadEmailAttachment::class, $attachment, 'Should return ThreadEmailAttachment instance');
        $this->assertEquals('test.pdf', $attachment->name, 'Should have correct name');
        $this->assertEquals('/path/to/test.pdf', $attachment->filename, 'Should have correct filename');
        $this->assertEquals('application/pdf', $attachment->filetype, 'Should have correct filetype');
        $this->assertEquals($testLocation, $attachment->location, 'Should have correct location');
        $this->assertEquals('processed', $attachment->status_type, 'Should have correct status_type');
        $this->assertEquals('Successfully processed', $attachment->status_text, 'Should have correct status_text');
        $this->assertEquals($testContent, $attachment->content, 'Should have correct content');
    }

    public function testGetThreadEmailAttachmentNotFound() {
        // :: Setup
        $thread = new Thread();
        $thread->id = '4beec30b-981a-401f-8652-de5e8fb54358';

        // :: Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Expected 1 row, got 0");

        // :: Act
        ThreadStorageManager::getInstance()->getThreadEmailAttachment($thread, 'non-existent');
    }
}

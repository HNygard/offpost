<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadFileOperations.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/Threads.php');

class ThreadFileOperationsTest extends TestCase {
    private $testDataDir;
    private $threadsDir;
    private $fileOps;
    
    protected function setUp(): void {
        parent::setUp();
        $this->testDataDir = DATA_DIR;
        $this->threadsDir = THREADS_DIR;
        
        // Clean up and recreate test directory for each test
        if (file_exists($this->threadsDir)) {
            $this->removeDirectory($this->threadsDir);
        }
        mkdir($this->threadsDir, 0777, true);
        $this->fileOps = new ThreadFileOperations();
    }
    
    protected function tearDown(): void {
        // Clean up test directories
        $this->removeDirectory($this->threadsDir);
        parent::tearDown();
    }
    
    private function removeDirectory($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = joinPaths($dir, $file);
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testGetThreadsEmptyDirectory() {
        // Test empty directory case
        $threads = $this->fileOps->getThreads();
        $this->assertIsArray($threads);
        $this->assertEmpty($threads);
    }

    public function testGetThreadsWithGitignore() {
        // Create .gitignore file
        file_put_contents(joinPaths($this->threadsDir, '.gitignore'), '*');
        
        // Create a thread file
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $threads = new Threads();
        $threads->threads = [$thread];
        file_put_contents(
            joinPaths($this->threadsDir, 'threads-test.json'),
            json_encode($threads)
        );
        
        $result = $this->fileOps->getThreads();
        $this->assertCount(1, $result);
        $this->assertArrayHasKey(realpath(joinPaths($this->threadsDir, 'threads-test.json')), $result);
        $this->assertEquals('Test Thread', $result[realpath(joinPaths($this->threadsDir, 'threads-test.json'))]->threads[0]->title);
    }

    public function testGetThreadsForEntity() {
        // Test non-existent entity
        $result = $this->fileOps->getThreadsForEntity('non-existent');
        $this->assertNull($result);
        
        // Test existing entity
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $threads = new Threads();
        $threads->entity_id = 'test-entity';
        $threads->threads = [$thread];
        
        file_put_contents(
            joinPaths($this->threadsDir, 'threads-test-entity.json'),
            json_encode($threads)
        );
        
        $result = $this->fileOps->getThreadsForEntity('test-entity');
        $this->assertNotNull($result);
        $this->assertEquals('test-entity', $result->entity_id);
        $this->assertEquals('Test Thread', $result->threads[0]->title);
    }

    public function testGetThreadFile() {
        // Create test thread directory and file
        $entityId = 'test-entity';
        $threadId = 'test-thread';
        $attachmentName = 'test.txt';
        $content = 'Test content';
        
        $threadDir = joinPaths($this->threadsDir, $entityId, $threadId);
        if (!file_exists($threadDir)) {
            mkdir($threadDir, 0777, true);
        }
        
        file_put_contents(joinPaths($threadDir, $attachmentName), $content);
        
        $result = $this->fileOps->getThreadFile($entityId, $threadId, $attachmentName);
        $this->assertEquals($content, $result);
    }

    public function testGetThreadFileNonExistent() {
        $this->expectException(Exception::class);
        
        // Try to get a file that doesn't exist
        $entityId = 'non-existent-entity';
        $threadId = 'non-existent-thread';
        $attachmentName = 'non-existent.txt';
        
        $this->fileOps->getThreadFile($entityId, $threadId, $attachmentName);
    }

    public function testSentCommentInitializationInGetThreads() {
        // Create a thread file with sentComment
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->sentComment = 'Test Comment';
        $threads = new Threads();
        $threads->threads = [$thread];
        file_put_contents(
            joinPaths($this->threadsDir, 'threads-test.json'),
            json_encode($threads)
        );

        $result = $this->fileOps->getThreads();
        $this->assertCount(1, $result);
        $this->assertArrayHasKey(realpath(joinPaths($this->threadsDir, 'threads-test.json')), $result);
        $this->assertEquals('Test Comment', $result[realpath(joinPaths($this->threadsDir, 'threads-test.json'))]->threads[0]->sentComment);
    }

    public function testSentCommentInitializationInGetThreadsForEntity() {
        // Create a thread file with sentComment
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->sentComment = 'Test Comment';
        $threads = new Threads();
        $threads->entity_id = 'test-entity';
        $threads->threads = [$thread];
        file_put_contents(
            joinPaths($this->threadsDir, 'threads-test-entity.json'),
            json_encode($threads)
        );

        $result = $this->fileOps->getThreadsForEntity('test-entity');
        $this->assertNotNull($result);
        $this->assertEquals('Test Comment', $result->threads[0]->sentComment);
    }

    public function testCreateThread() {
        $entityId = 'test-entity';
        $entityTitlePrefix = 'Test Prefix';
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->sentComment = 'Test Comment';

        $createdThread = $this->fileOps->createThread($entityId, $entityTitlePrefix, $thread);

        $this->assertEquals('Test Thread', $createdThread->title);
        $this->assertEquals('Test Comment', $createdThread->sentComment);

        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals('Test Prefix', $savedThreads->title_prefix);
        $this->assertCount(1, $savedThreads->threads);
        $this->assertEquals('Test Thread', $savedThreads->threads[0]->title);
        $this->assertEquals('Test Comment', $savedThreads->threads[0]->sentComment);
    }

    public function testSaveEntityThreads() {
        $entityId = 'test-entity';
        $threads = new Threads();
        $threads->entity_id = $entityId;
        $threads->title_prefix = 'Test Prefix';
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->sentComment = 'Test Comment';
        $threads->threads = [$thread];

        $this->fileOps->saveEntityThreads($entityId, $threads);

        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals('Test Prefix', $savedThreads->title_prefix);
        $this->assertCount(1, $savedThreads->threads);
        $this->assertEquals('Test Thread', $savedThreads->threads[0]->title);
        $this->assertEquals('Test Comment', $savedThreads->threads[0]->sentComment);
    }
}

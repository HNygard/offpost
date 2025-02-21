<?php

require_once(__DIR__ . '/bootstrap.php');
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';

use PHPUnit\Framework\TestCase;

/**
 * @preserveGlobalState disabled
 */
class PageRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Start output buffering to capture rendered content
        ob_start();

        // Set up session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        // Clean up output buffer
        ob_end_clean();
        
        // Clean up session
        session_destroy();

        // Rollback transaction to clean up test data
        if (Database::getInstance()) {
            Database::rollBack();
        }
        
        parent::tearDown();
    }

    /**
     * Helper method to simulate a request to a PHP page
     * @param string $page The page to request (e.g. 'index.php')
     * @param array $get GET parameters
     * @param array $post POST parameters
     * @return string The rendered content
     */
    protected function renderPage($page, $get = [], $post = [])
    {
        // Save current globals
        $oldGet = $_GET;
        $oldPost = $_POST;
        $oldServer = $_SERVER;

        // Set up request environment
        $_GET = $get;
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = !empty($post) ? 'POST' : 'GET';
        $_SERVER['SCRIPT_NAME'] = "/$page";
        $_SERVER['REQUEST_URI'] = "/$page";
        $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/..');

        try {
            if ($page === 'index.php') {
                // For root path, include the router
                $_SERVER['REQUEST_URI'] = '/';
                include __DIR__ . '/../webroot/index.php';
            } else {
                include __DIR__ . "/../$page";
            }
            $content = ob_get_contents();
            ob_clean();
            return $content;
        } catch (Exception $e) {
            return "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
        } finally {
            // Restore globals
            $_GET = $oldGet;
            $_POST = $oldPost;
            $_SERVER = $oldServer;
        }
    }

    /**
     * Helper method to set up test database state
     */
    private function setupTestData()
    {
        // Initialize database connection
        $db = Database::getInstance();
        
        // Start transaction for test isolation
        Database::beginTransaction();
        
        // Clean up any existing test data
        Database::execute("DELETE FROM thread_history WHERE thread_id IN (SELECT id FROM threads WHERE entity_id = 'test_entity')");
        Database::execute("DELETE FROM thread_email_attachments WHERE email_id IN (SELECT id FROM thread_emails WHERE thread_id IN (SELECT id FROM threads WHERE entity_id = 'test_entity'))");
        Database::execute("DELETE FROM thread_emails WHERE thread_id IN (SELECT id FROM threads WHERE entity_id = 'test_entity')");
        Database::execute("DELETE FROM threads WHERE entity_id = 'test_entity'");
    }

    /**
     * @preserveGlobalState disabled
     */
    public function testIndexPageRendersWithoutError()
    {
        // :: Setup
        $this->setupTestData();

        // :: Act
        $content = $this->renderPage('index.php');

        // :: Assert
        $this->assertNotEmpty($content, 'Page should render some content');
        $this->assertStringContainsString('<!DOCTYPE html>', $content, 'Page should contain HTML doctype');
        $this->assertStringContainsString('<html', $content, 'Page should contain HTML tag');
        $this->assertStringContainsString('<title>Offpost</title>', $content, 'Page should have correct title');
        $this->assertStringContainsString('Offpost - Email Engine Organizer', $content, 'Page should contain main heading');
        
        // Verify no PHP errors or warnings in output
        $this->assertStringNotContainsString('Warning:', $content);
        $this->assertStringNotContainsString('Notice:', $content);
        $this->assertStringNotContainsString('Error:', $content);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testIndexPageShowsNoThreadsMessage()
    {
        // :: Setup
        $this->setupTestData();

        // :: Act
        $content = $this->renderPage('index.php');

        // :: Assert
        $this->assertStringContainsString('Threads (0)', $content, 'Page should show zero threads count');
        $this->assertStringContainsString('Start new thread', $content, 'Page should show new thread link');
    }
}

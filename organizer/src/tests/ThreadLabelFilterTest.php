<?php

require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadLabelFilter.php';

use PHPUnit\Framework\TestCase;

class ThreadLabelFilterTest extends TestCase {
    private Thread $thread;

    protected function setUp(): void {
        $this->thread = new Thread();
        $this->thread->labels = ['test_label', 'another_label'];
        $this->thread->sent = false;
        $this->thread->archived = false;
    }

    public function testMatchesSentFilter() {
        $this->assertFalse(ThreadLabelFilter::matches($this->thread, 'sent'));
        $this->thread->sent = true;
        $this->assertTrue(ThreadLabelFilter::matches($this->thread, 'sent'));
    }

    public function testMatchesNotSentFilter() {
        $this->assertTrue(ThreadLabelFilter::matches($this->thread, 'not_sent'));
        $this->thread->sent = true;
        $this->assertFalse(ThreadLabelFilter::matches($this->thread, 'not_sent'));
    }

    public function testMatchesArchivedFilter() {
        $this->assertFalse(ThreadLabelFilter::matches($this->thread, 'archived'));
        $this->thread->archived = true;
        $this->assertTrue(ThreadLabelFilter::matches($this->thread, 'archived'));
    }

    public function testMatchesNotArchivedFilter() {
        $this->assertTrue(ThreadLabelFilter::matches($this->thread, 'not_archived'));
        $this->thread->archived = true;
        $this->assertFalse(ThreadLabelFilter::matches($this->thread, 'not_archived'));
    }

    public function testMatchesCustomLabel() {
        $this->assertTrue(ThreadLabelFilter::matches($this->thread, 'test_label'));
        $this->assertTrue(ThreadLabelFilter::matches($this->thread, 'another_label'));
        $this->assertFalse(ThreadLabelFilter::matches($this->thread, 'non_existent_label'));
    }
}

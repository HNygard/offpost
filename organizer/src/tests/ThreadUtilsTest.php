<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadUtils.php';

class ThreadUtilsTest extends TestCase {
    public function testGetThreadIdWithUTF8() {
        $thread = new stdClass();
        $thread->title = "Café Møller";
        // Test actual behavior - UTF-8 characters are preserved in lowercase
        $this->assertEquals('café_møller', getThreadId($thread));
    }

    public function testGetThreadIdWithSpaces() {
        $thread = new stdClass();
        $thread->title = "Hello World Test";
        $this->assertEquals('hello_world_test', getThreadId($thread));
    }

    public function testGetThreadIdWithForwardSlashes() {
        $thread = new stdClass();
        $thread->title = "path/to/something";
        $this->assertEquals('path-to-something', getThreadId($thread));
    }

    public function testGetLabelTypeInfo() {
        $this->assertEquals('label', getLabelType('any', 'info'));
    }

    public function testGetLabelTypeDisabled() {
        $this->assertEquals('label label_disabled', getLabelType('any', 'disabled'));
    }

    public function testGetLabelTypeDanger() {
        $this->assertEquals('label label_warn', getLabelType('any', 'danger'));
    }

    public function testGetLabelTypeSuccess() {
        $this->assertEquals('label label_ok', getLabelType('any', 'success'));
    }

    public function testGetLabelTypeUnknown() {
        $this->assertEquals('label', getLabelType('any', 'unknown'));
    }

    public function testGetLabelTypeInvalid() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown status_type[any]: invalid');
        getLabelType('any', 'invalid');
    }
}

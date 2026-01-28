<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadUtils.php';

class ThreadUtilsUtf8Test extends PHPUnit\Framework\TestCase {
    
    public function testSanitizeUtf8String_ValidUtf8() {
        // Valid UTF-8 string should remain unchanged
        $validUtf8 = "Hello World! Ñoño café";
        $result = sanitizeUtf8String($validUtf8);
        $this->assertEquals($validUtf8, $result);
    }
    
    public function testSanitizeUtf8String_InvalidSequence() {
        // Invalid UTF-8 sequence: 0xC3 followed by space (0x20) instead of continuation byte
        // This is the exact error from the issue: "invalid byte sequence for encoding "UTF8": 0xc3 0x20"
        $invalidUtf8 = "Test" . chr(0xC3) . chr(0x20) . "string";
        $result = sanitizeUtf8String($invalidUtf8);
        
        // The result should be a valid UTF-8 string
        $this->assertNotFalse(mb_check_encoding($result, 'UTF-8'));
        // The invalid sequence should have been replaced or removed
        $this->assertNotEquals($invalidUtf8, $result);
    }
    
    public function testSanitizeUtf8String_MultipleInvalidSequences() {
        // Multiple invalid UTF-8 sequences
        $invalidUtf8 = chr(0xC3) . chr(0x20) . "test" . chr(0xFF) . chr(0xFE);
        $result = sanitizeUtf8String($invalidUtf8);
        
        // The result should be a valid UTF-8 string
        $this->assertNotFalse(mb_check_encoding($result, 'UTF-8'));
    }
    
    public function testSanitizeUtf8String_EmptyString() {
        $result = sanitizeUtf8String("");
        $this->assertEquals("", $result);
    }
    
    public function testSanitizeUtf8Recursive_Array() {
        $data = [
            'valid' => 'Hello',
            'invalid' => "Test" . chr(0xC3) . chr(0x20) . "string"
        ];
        
        $result = sanitizeUtf8Recursive($data);
        
        // Valid string should remain unchanged
        $this->assertEquals('Hello', $result['valid']);
        // Invalid string should be sanitized
        $this->assertNotFalse(mb_check_encoding($result['invalid'], 'UTF-8'));
        $this->assertNotEquals($data['invalid'], $result['invalid']);
    }
    
    public function testSanitizeUtf8Recursive_Object() {
        $data = new stdClass();
        $data->valid = 'Hello';
        $data->invalid = "Test" . chr(0xC3) . chr(0x20) . "string";
        $data->nested = new stdClass();
        $data->nested->value = "Nested" . chr(0xFF);
        
        $result = sanitizeUtf8Recursive($data);
        
        // Valid string should remain unchanged
        $this->assertEquals('Hello', $result->valid);
        // Invalid strings should be sanitized
        $this->assertNotFalse(mb_check_encoding($result->invalid, 'UTF-8'));
        $this->assertNotFalse(mb_check_encoding($result->nested->value, 'UTF-8'));
    }
    
    public function testSanitizeUtf8Recursive_MixedTypes() {
        $data = [
            'string' => "Test" . chr(0xC3) . chr(0x20),
            'number' => 42,
            'boolean' => true,
            'null' => null,
            'array' => ['nested' => "Invalid" . chr(0xFF)]
        ];
        
        $result = sanitizeUtf8Recursive($data);
        
        // Non-string types should remain unchanged
        $this->assertEquals(42, $result['number']);
        $this->assertEquals(true, $result['boolean']);
        $this->assertEquals(null, $result['null']);
        // Strings should be sanitized
        $this->assertNotFalse(mb_check_encoding($result['string'], 'UTF-8'));
        $this->assertNotFalse(mb_check_encoding($result['array']['nested'], 'UTF-8'));
    }
    
    public function testSanitizeUtf8String_NorwegianCharacters() {
        // Test with Norwegian characters (should remain valid)
        $norwegianText = "Snåsa kommune - Innsyn i håndskrevet opptellingsdata";
        $result = sanitizeUtf8String($norwegianText);
        $this->assertEquals($norwegianText, $result);
        $this->assertNotFalse(mb_check_encoding($result, 'UTF-8'));
    }
}

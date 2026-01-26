<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';

class ImapWrapperUtf8Test extends TestCase
{
    public function testUtf8WithNormalText()
    {
        $wrapper = new ImapWrapper();
        $text = 'Normal text';
        $result = $wrapper->utf8($text);
        
        $this->assertEquals($text, $result);
    }
    
    public function testUtf8WithValidMimeEncodedString()
    {
        $wrapper = new ImapWrapper();
        // Valid MIME-encoded string: "Test Title" in UTF-8 base64
        $text = '=?UTF-8?B?VGVzdCBUaXRsZQ==?=';
        $result = $wrapper->utf8($text);
        
        // imap_utf8 should decode this properly
        $this->assertEquals('Test Title', $result);
    }
    
    public function testUtf8WithMalformedMimeEncodedString()
    {
        $wrapper = new ImapWrapper();
        // Malformed MIME-encoded string from the error report
        $text = '=?iso-8859-1?Q?axz5ZAFym5luZoxqfgeds8xO/E+PtRicCu3CXJTfFFl7/aub8+5SDA59PR? =?iso-';
        
        // This should not throw an exception
        $result = $wrapper->utf8($text);
        
        // Should return a string (may be the same as input if imap_utf8 can't decode it)
        $this->assertIsString($result);
    }
    
    public function testUtf8WithEmptyString()
    {
        $wrapper = new ImapWrapper();
        $text = '';
        $result = $wrapper->utf8($text);
        
        $this->assertEquals('', $result);
    }
    
    public function testUtf8WithUtf8Text()
    {
        $wrapper = new ImapWrapper();
        // UTF-8 text with special characters
        $text = 'Tëst wîth spëcîal çhâracters';
        $result = $wrapper->utf8($text);
        
        // Should return the text as-is or decoded
        $this->assertIsString($result);
    }
    
    public function testUtf8WithNordicCharacters()
    {
        $wrapper = new ImapWrapper();
        // Nordic characters
        $text = 'Æble Øre Åre';
        $result = $wrapper->utf8($text);
        
        // Should handle Nordic characters properly
        $this->assertIsString($result);
        $this->assertStringContainsString('ble', $result);
        $this->assertStringContainsString('re', $result);
    }
}

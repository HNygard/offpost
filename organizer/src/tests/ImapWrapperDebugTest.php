<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';

class ImapWrapperDebugTest extends TestCase
{
    public function testConstructorWithDebugEnabled()
    {
        $wrapper = new ImapWrapper(true);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $debugProperty = $reflection->getProperty('debug');
        $debugProperty->setAccessible(true);
        
        $this->assertTrue($debugProperty->getValue($wrapper));
    }
    
    public function testConstructorWithDebugDisabled()
    {
        $wrapper = new ImapWrapper(false);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $debugProperty = $reflection->getProperty('debug');
        $debugProperty->setAccessible(true);
        
        $this->assertFalse($debugProperty->getValue($wrapper));
    }
    
    public function testConstructorDefaultDebugValue()
    {
        $wrapper = new ImapWrapper();
        $reflection = new ReflectionClass(ImapWrapper::class);
        $debugProperty = $reflection->getProperty('debug');
        $debugProperty->setAccessible(true);
        
        $this->assertFalse($debugProperty->getValue($wrapper));
    }
    
    public function testLogDebugMethodExists()
    {
        $reflection = new ReflectionClass(ImapWrapper::class);
        $this->assertTrue($reflection->hasMethod('logDebug'));
        
        $method = $reflection->getMethod('logDebug');
        $this->assertTrue($method->isPrivate());
    }
    
    public function testLogDebugWithDebugDisabled()
    {
        $wrapper = new ImapWrapper(false);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('logDebug');
        $method->setAccessible(true);
        
        // Capture echo output
        ob_start();
        
        // Call logDebug
        $method->invoke($wrapper, 'test_operation', ['param1: value1']);
        
        // Get the output
        $output = ob_get_clean();
        
        // Assert that nothing was output when debug is disabled
        $this->assertEmpty($output);
    }
    
    public function testLogDebugWithDebugEnabled()
    {
        $wrapper = new ImapWrapper(true);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('logDebug');
        $method->setAccessible(true);
        
        // Capture echo output
        ob_start();
        
        // Call logDebug
        $method->invoke($wrapper, 'test_operation', ['param1: value1', 'param2: value2']);
        
        // Get the output
        $output = ob_get_clean();
        
        // Assert that the output contains the expected message
        $this->assertStringContainsString('IMAP DEBUG: test_operation', $output);
        $this->assertStringContainsString('param1: value1', $output);
        $this->assertStringContainsString('param2: value2', $output);
    }
    
    public function testLogDebugWithNoParams()
    {
        $wrapper = new ImapWrapper(true);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('logDebug');
        $method->setAccessible(true);
        
        // Capture echo output
        ob_start();
        
        // Call logDebug with no params
        $method->invoke($wrapper, 'test_operation', null);
        
        // Get the output
        $output = ob_get_clean();
        
        // Assert that the output contains the operation name without parameter brackets
        $this->assertStringContainsString('IMAP DEBUG: test_operation', $output);
        // The output should end right after the operation name (followed by newline), no parameter context
        $this->assertMatchesRegularExpression('/IMAP DEBUG: test_operation\s*$/', trim($output));
    }
}

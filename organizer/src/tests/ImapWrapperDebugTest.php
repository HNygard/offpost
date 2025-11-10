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
        
        // Capture error_log output
        $originalErrorLog = ini_get('error_log');
        $tempLogFile = tempnam(sys_get_temp_dir(), 'imap_test_');
        ini_set('error_log', $tempLogFile);
        
        // Call logDebug
        $method->invoke($wrapper, 'test_operation', ['param1: value1']);
        
        // Read the log file
        $logContent = file_get_contents($tempLogFile);
        
        // Restore error_log setting
        ini_set('error_log', $originalErrorLog);
        unlink($tempLogFile);
        
        // Assert that nothing was logged when debug is disabled
        $this->assertEmpty($logContent);
    }
    
    public function testLogDebugWithDebugEnabled()
    {
        $wrapper = new ImapWrapper(true);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('logDebug');
        $method->setAccessible(true);
        
        // Capture error_log output
        $originalErrorLog = ini_get('error_log');
        $tempLogFile = tempnam(sys_get_temp_dir(), 'imap_test_');
        ini_set('error_log', $tempLogFile);
        
        // Call logDebug
        $method->invoke($wrapper, 'test_operation', ['param1: value1', 'param2: value2']);
        
        // Read the log file
        $logContent = file_get_contents($tempLogFile);
        
        // Restore error_log setting
        ini_set('error_log', $originalErrorLog);
        unlink($tempLogFile);
        
        // Assert that the log contains the expected message
        $this->assertStringContainsString('IMAP DEBUG: test_operation', $logContent);
        $this->assertStringContainsString('param1: value1', $logContent);
        $this->assertStringContainsString('param2: value2', $logContent);
    }
    
    public function testLogDebugWithNoParams()
    {
        $wrapper = new ImapWrapper(true);
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('logDebug');
        $method->setAccessible(true);
        
        // Capture error_log output
        $originalErrorLog = ini_get('error_log');
        $tempLogFile = tempnam(sys_get_temp_dir(), 'imap_test_');
        ini_set('error_log', $tempLogFile);
        
        // Call logDebug with no params
        $method->invoke($wrapper, 'test_operation', null);
        
        // Read the log file
        $logContent = file_get_contents($tempLogFile);
        
        // Restore error_log setting
        ini_set('error_log', $originalErrorLog);
        unlink($tempLogFile);
        
        // Assert that the log contains the operation name without brackets
        $this->assertStringContainsString('IMAP DEBUG: test_operation', $logContent);
        $this->assertStringNotContainsString('[', $logContent);
    }
}

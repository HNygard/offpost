<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Database.php';

class DatabaseTest extends PHPUnit\Framework\TestCase {
    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction for test isolation
        Database::beginTransaction();
        
        // Create a test table for our queries
        Database::execute("
            CREATE TEMPORARY TABLE test_query_table (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                value INTEGER
            )
        ");
        
        // Insert test data
        Database::execute("
            INSERT INTO test_query_table (name, value) VALUES 
            ('test1', 100),
            ('test2', 200),
            ('test3', 300)
        ");
    }
    
    protected function tearDown(): void {
        // Roll back transaction to clean up test data
        Database::rollBack();
        
        parent::tearDown();
    }
    
    public function testQueryOneOrNoneWithOneRow(): void {
        // :: Setup
        
        // :: Act
        $result = Database::queryOneOrNone(
            "SELECT * FROM test_query_table WHERE name = ?",
            ['test1']
        );
        
        // :: Assert
        $this->assertNotNull($result, "Should return a result when one row matches");
        $this->assertIsArray($result, "Result should be an array");
        $this->assertEquals('test1', $result['name'], "Should return the correct row");
        $this->assertEquals(100, $result['value'], "Should return the correct value");
    }
    
    public function testQueryOneOrNoneWithNoRows(): void {
        // :: Setup
        
        // :: Act
        $result = Database::queryOneOrNone(
            "SELECT * FROM test_query_table WHERE name = ?",
            ['non_existent']
        );
        
        // :: Assert
        $this->assertNull($result, "Should return null when no rows match");
    }
    
    public function testQueryOneOrNoneWithMultipleRowsThrowsException(): void {
        // :: Setup
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Expected 1 row, got 3");
        
        Database::queryOneOrNone(
            "SELECT * FROM test_query_table",
            []
        );
    }
    
    public function testQueryOneWithOneRow(): void {
        // :: Setup
        
        // :: Act
        $result = Database::queryOne(
            "SELECT * FROM test_query_table WHERE name = ?",
            ['test1']
        );
        
        // :: Assert
        $this->assertNotNull($result, "Should return a result when one row matches");
        $this->assertIsArray($result, "Result should be an array");
        $this->assertEquals('test1', $result['name'], "Should return the correct row");
        $this->assertEquals(100, $result['value'], "Should return the correct value");
    }
    
    public function testQueryOneWithNoRowsThrowsException(): void {
        // :: Setup
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Expected 1 row, got 0");
        
        Database::queryOne(
            "SELECT * FROM test_query_table WHERE name = ?",
            ['non_existent']
        );
    }
    
    public function testQueryOneWithMultipleRowsThrowsException(): void {
        // :: Setup
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Expected 1 row, got 3");
        
        Database::queryOne(
            "SELECT * FROM test_query_table",
            []
        );
    }
    
    public function testQuery(): void {
        // :: Setup
        
        // :: Act
        $results = Database::query(
            "SELECT * FROM test_query_table ORDER BY id",
            []
        );
        
        // :: Assert
        $this->assertIsArray($results, "Should return an array of results");
        $this->assertCount(3, $results, "Should return all matching rows. Results: " . json_encode($results, JSON_PRETTY_PRINT));
        $this->assertEquals('test1', $results[0]['name'], "First row should have correct name");
        $this->assertEquals(100, $results[0]['value'], "First row should have correct value");
        $this->assertEquals('test2', $results[1]['name'], "Second row should have correct name");
        $this->assertEquals(200, $results[1]['value'], "Second row should have correct value");
    }
    
    public function testQueryValue(): void {
        // :: Setup
        
        // :: Act
        $value = Database::queryValue(
            "SELECT value FROM test_query_table WHERE name = ?",
            ['test2']
        );
        
        // :: Assert
        $this->assertEquals(200, $value, "Should return the correct scalar value");
    }
    
    public function testExecute(): void {
        // :: Setup
        
        // :: Act
        $rowCount = Database::execute(
            "UPDATE test_query_table SET value = ? WHERE name = ?",
            [150, 'test1']
        );
        
        // :: Assert
        $this->assertEquals(1, $rowCount, "Should return the number of affected rows");
        
        // Verify the update
        $updatedValue = Database::queryValue(
            "SELECT value FROM test_query_table WHERE name = ?",
            ['test1']
        );
        $this->assertEquals(150, $updatedValue, "Value should be updated in the database");
    }
}

<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Entity.php';

class EntityTest extends PHPUnit\Framework\TestCase {
    private $originalJsonPath;
    private $testJsonPath;
    private $testEntities;

    protected function setUp(): void {
        // Store the original JSON path
        $reflection = new ReflectionClass('Entity');
        $property = $reflection->getProperty('jsonPath');
        $property->setAccessible(true);
        $this->originalJsonPath = $property->getValue();

        // Create a temporary test JSON file
        $this->testJsonPath = tempnam(sys_get_temp_dir(), 'entity_test_');
        $this->testEntities = json_decode('{
            "test-entity-1": {
                "name": "Test Entity 1",
                "email": "test1@example.com",
                "type": "test",
                "org_num": "123456789"
            },
            "test-entity-2": {
                "name": "Test Entity 2",
                "email": "test2@example.com",
                "type": "test",
                "org_num": "987654321"
            }
        }');
        file_put_contents($this->testJsonPath, json_encode($this->testEntities));

        // Set the Entity class to use our test JSON file
        $property->setValue($this->testJsonPath);

        // Reset the entities cache
        $cacheProperty = $reflection->getProperty('entities');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue(null);
    }

    protected function tearDown(): void {
        // Restore the original JSON path
        $reflection = new ReflectionClass('Entity');
        $property = $reflection->getProperty('jsonPath');
        $property->setAccessible(true);
        $property->setValue($this->originalJsonPath);

        // Reset the entities cache
        $cacheProperty = $reflection->getProperty('entities');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue(null);

        // Remove the test JSON file
        if (file_exists($this->testJsonPath)) {
            unlink($this->testJsonPath);
        }
    }

    public function testGetAll() {
        // :: Setup
        // Done in setUp()

        // :: Act
        $entities = Entity::getAll();

        // :: Assert
        $this->assertEquals($this->testEntities, $entities, "Entity::getAll() should return all entities from the JSON file");
    }

    public function testExists() {
        // :: Setup
        // Done in setUp()

        // :: Act & Assert
        $this->assertTrue(Entity::exists('test-entity-1'), "Entity::exists() should return true for existing entity");
        $this->assertTrue(Entity::exists('test-entity-2'), "Entity::exists() should return true for existing entity");
        $this->assertFalse(Entity::exists('non-existent-entity'), "Entity::exists() should return false for non-existent entity");
    }

    public function testGetById() {
        // :: Setup
        // Done in setUp()

        // :: Act
        $entity1 = Entity::getById('test-entity-1');
        $entity2 = Entity::getById('test-entity-2');

        // :: Assert
        $this->assertEquals($this->testEntities->{'test-entity-1'}, $entity1, "Entity::getById() should return the correct entity");
        $this->assertEquals($this->testEntities->{'test-entity-2'}, $entity2, "Entity::getById() should return the correct entity");
    }

    public function testNonExisting() {
        $this->expectException(InvalidArgumentException::class);
        Entity::getById('non-existent-entity');
    }
}

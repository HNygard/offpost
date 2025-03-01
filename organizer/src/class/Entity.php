<?php

/**
 * Entity class for managing public entities
 * 
 * This class provides methods to validate and retrieve information about public entities
 * from a JSON file stored at data/entities.json
 */
class Entity {
    public string $entity_id;
    public string $name;
    public string $email;
    public string $type;
    public string $org_num;

    private static $entities = null;
    private static $jsonPath =  '/organizer-entities.json';
    
    /**
     * Load entities from JSON file
     * 
     * @return Entity[]
     */
    private static function loadEntities() {
        if (self::$entities === null) {
            if (self::$jsonPath == '/tmp/organizer-test-data/entities.json') {
                self::$jsonPath = __DIR__ . '/../../../data/entities_test.json';
            }

            if (!file_exists(self::$jsonPath)) {
                throw new RuntimeException('Entities JSON file not found: ' . self::$jsonPath);
            }
            
            $json = file_get_contents(self::$jsonPath);
            self::$entities = json_decode($json);
        }
        return self::$entities;
    }
    
    /**
     * Get all entities
     * 
     * @return Entity[]
     */
    public static function getAll() {
        return self::loadEntities();
    }
    
    /**
     * Check if an entity ID exists
     * @param string $entityId The entity ID to check
     * @return bool True if the entity exists, false otherwise
     */
    public static function exists($entityId) {
        $entities = self::loadEntities();
        return isset($entities->$entityId);
    }
    
    /**
     * Get entity details by ID
     * @param string $entityId The entity ID
     * @return Entity Entity details
     * @throws InvalidArgumentException If the entity ID is not found
     */
    public static function getById($entityId) {
        $entities = self::loadEntities();

        if(!self::exists($entityId)) {
            throw new InvalidArgumentException("Entity ID not found: $entityId");
        }

        return $entities->$entityId;
    }
}

<?php

/**
 * Entity class for managing public entities
 * 
 * This class provides methods to validate and retrieve information about public entities
 * from a JSON file stored at data/entities.json
 */
class Entity {
    public string $entity_id;
    public ?string $entity_id_norske_postlister = null;
    public string $name;
    public ?string $email = null;
    public string $type;
    public ?string $org_num = null;

    // Optional fields
    public ?string $entity_existed_to_and_including = null;
    public ?string $entity_merged_into = null;


    private static $entities = null;
    private static $jsonPath =  DATA_DIR . '/entities.json';
    
    public static function getNameHtml($entity) {
        $name = htmlspecialchars($entity->name);
        if (isset($entity->entity_existed_to_and_including)) {
            $name .= ' <span style="font-size:0.6em">(up to and including ' . date('d.m.Y', strtotime($entity->entity_existed_to_and_including));
            if (isset($entity->entity_merged_into)) {
                $name .= ', merged into ' . htmlspecialchars(self::loadEntities()->{$entity->entity_merged_into}->name);
            }
            $name .= ')</span>';
        }
        return $name;
    }

    /**
     * Convert a stdClass entity object to an Entity instance
     */
    private static function createEntityFromData($data): Entity {
        $entity = new Entity();
        foreach ((array)$data as $key => $value) {
            if (property_exists($entity, $key)) {
                $entity->$key = $value;
            }
        }
        return $entity;
    }

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
    public static function getById($entityId): Entity {
        $entities = self::loadEntities();

        if(!self::exists($entityId)) {
            throw new InvalidArgumentException("Entity ID not found: $entityId");
        }

        return self::createEntityFromData($entities->$entityId);
    }

    /**
     * Look up an entity by its norske-postlister.no entity id.
     * Returns null if no entity carries the id (caller must handle explicitly).
     */
    public static function getByNorskePostlisterId(string $npId): ?Entity {
        foreach (self::loadEntities() as $entity) {
            if (isset($entity->entity_id_norske_postlister)
                && $entity->entity_id_norske_postlister === $npId) {
                return self::getById($entity->entity_id);
            }
        }
        return null;
    }

    /**
     * All norske-postlister ids this instance can resolve (active, non-test entities only).
     */
    public static function getAllNorskePostlisterIds(): array {
        $ids = [];
        foreach (self::loadEntities() as $entity) {
            if (isset($entity->entity_id_norske_postlister)
                && $entity->entity_id_norske_postlister !== ''
                && !isset($entity->entity_existed_to_and_including)
                && (!isset($entity->type) || $entity->type !== 'test')
                // Same rule createThread enforces: without a recipient email the
                // request can never be sent, so the entity must not be advertised
                // as supported (norske-postlister then offers copy-paste only).
                && isset($entity->email) && trim((string)$entity->email) !== '') {
                $ids[] = $entity->entity_id_norske_postlister;
            }
        }
        sort($ids);
        return $ids;
    }
}

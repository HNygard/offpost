<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Entity.php';

class EntityNpLookupTest extends \PHPUnit\Framework\TestCase {
    public function testLookupByNpId(): void {
        $entity = Entity::getByNorskePostlisterId('9999-test-entity-development');
        $this->assertNotNull($entity);
        $this->assertEquals('000000000-test-entity-development', $entity->entity_id);
    }

    public function testUnknownNpIdReturnsNull(): void {
        $this->assertNull(Entity::getByNorskePostlisterId('0000-does-not-exist'));
    }

    public function testGetAllNorskePostlisterIds(): void {
        $ids = Entity::getAllNorskePostlisterIds();
        $this->assertContains('9999-test-entity-development', $ids);
    }

    public function testGetByIdWithMissingEmailAndOrgNumDoesNotFatal(): void {
        $entity = Entity::getById('000000000-test-entity-no-email');
        $this->assertInstanceOf(Entity::class, $entity);
        $this->assertNull($entity->email, "email should be null (not fatal) when absent from entities.json");
        $this->assertNull($entity->org_num, "org_num should be null (not fatal) when absent from entities.json");
    }
}

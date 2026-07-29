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

    public function testGetAllNorskePostlisterIdsExcludesTestEntities(): void {
        $ids = Entity::getAllNorskePostlisterIds();
        $this->assertNotContains('9999-test-entity-development', $ids);
    }

    public function testGetAllNorskePostlisterIdsIncludesNonTestEntityButExcludesTestEntities(): void {
        // Regression coverage: every NP-mapped entity in entities_test.json used to be
        // type "test", so an inverted type !== 'test' filter would still pass. This
        // entity is type "municipality" and must be included.
        $ids = Entity::getAllNorskePostlisterIds();

        $this->assertContains('9996-test-municipality', $ids);
        $this->assertNotContains('9999-test-entity-development', $ids);
        $this->assertNotContains('9998-test-entity-no-email', $ids);
        $this->assertNotContains('9997-test-entity-two', $ids);
    }

    public function testGetByNorskePostlisterIdStillResolvesTestEntity(): void {
        $entity = Entity::getByNorskePostlisterId('9999-test-entity-development');
        $this->assertNotNull($entity);
        $this->assertEquals('000000000-test-entity-development', $entity->entity_id);
    }

    public function testGetByIdWithMissingEmailAndOrgNumDoesNotFatal(): void {
        $entity = Entity::getById('000000000-test-entity-no-email');
        $this->assertInstanceOf(Entity::class, $entity);
        $this->assertNull($entity->email, "email should be null (not fatal) when absent from entities.json");
        $this->assertNull($entity->org_num, "org_num should be null (not fatal) when absent from entities.json");
    }
}

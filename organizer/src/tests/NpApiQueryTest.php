<?php
// organizer/src/tests/NpApiQueryTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/np/np-api-query.php';

class NpApiQueryTest extends TestCase {
    public function testRepeatedPlainKeyReturnsEveryValueInOrder(): void {
        $values = npApiQueryValues('label=postliste&label=postliste%3A2026-W38&other=x', 'label');
        $this->assertEquals(['postliste', 'postliste:2026-W38'], $values);
    }

    public function testArrayStyleKeyAlsoAccepted(): void {
        $values = npApiQueryValues('label%5B%5D=a&label[]=b', 'label');
        $this->assertEquals(['a', 'b'], $values);
    }

    public function testMissingAndEmptyValues(): void {
        $this->assertEquals([], npApiQueryValues('', 'label'));
        $this->assertEquals([], npApiQueryValues('other=1', 'label'));
        $this->assertEquals([''], npApiQueryValues('label=', 'label'), 'empty value is returned so the endpoint can reject it');
        $this->assertEquals([''], npApiQueryValues('label', 'label'));
    }

    public function testDoesNotMatchPrefixedKeys(): void {
        $this->assertEquals([], npApiQueryValues('labels=a&label_x=b', 'label'));
    }
}

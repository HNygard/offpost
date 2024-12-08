<?php

use PHPUnit\Framework\TestCase;

class RandomProfileTest extends TestCase
{
    private $originalFirstNames;
    private $originalLastNames;

    protected function setUp(): void
    {
        global $first_names_to_clean, $last_names_to_clean;
        
        // Store original values
        $this->originalFirstNames = $first_names_to_clean;
        $this->originalLastNames = $last_names_to_clean;

        // Include the file after storing originals
        require_once __DIR__ . '/../class/random-profile.php';

        // Override with test data
        $first_names_to_clean = array_merge(
            getNamesFromCsv(__DIR__ . '/test-data/guttenavn.csv'),
            getNamesFromCsv(__DIR__ . '/test-data/jentenavn.csv')
        );
        $last_names_to_clean = getNamesFromCsv(__DIR__ . '/test-data/etternavn.csv');
    }

    protected function tearDown(): void
    {
        global $first_names_to_clean, $last_names_to_clean;
        
        // Restore original values
        $first_names_to_clean = $this->originalFirstNames;
        $last_names_to_clean = $this->originalLastNames;
    }

    public function testProfileRandomFunction()
    {
        // Test with 0% chance - should always return first string
        $result = profileRandom(0, "first", "second");
        $this->assertEquals("first", $result);

        // Test with 100% chance - should always return second string
        $result = profileRandom(100, "first", "second");
        $this->assertEquals("second", $result);
    }

    public function testMbUcfirst()
    {
        $this->assertEquals("Test", mb_ucfirst("test", "UTF-8"));
        $this->assertEquals("Æble", mb_ucfirst("æble", "UTF-8"));
        $this->assertEquals("Øre", mb_ucfirst("øre", "UTF-8"));
        $this->assertEquals("Åre", mb_ucfirst("åre", "UTF-8"));
    }

    public function testGetRandomNameAndEmail()
    {
        $result = getRandomNameAndEmail();

        // Test object structure
        $this->assertIsObject($result);
        $this->assertObjectHasProperty('firstName', $result);
        $this->assertObjectHasProperty('middleName', $result);
        $this->assertObjectHasProperty('lastName', $result);
        $this->assertObjectHasProperty('email', $result);

        // Test name capitalization
        $this->assertMatchesRegularExpression('/^[A-ZÆØÅ][a-zæøå]+$/', $result->firstName);
        $this->assertMatchesRegularExpression('/^[A-ZÆØÅ][a-zæøå]+$/', $result->lastName);

        // Test email format
        $this->assertMatchesRegularExpression('/^[a-z0-9.-]+@offpost\.no$/', $result->email);
        
        // Test special character replacements in email
        $this->assertStringNotContainsString('æ', $result->email);
        $this->assertStringNotContainsString('ø', $result->email);
        $this->assertStringNotContainsString('å', $result->email);
    }

    public function testGetNamesFromCsv()
    {
        $firstNames = getNamesFromCsv(__DIR__ . '/test-data/guttenavn.csv');
        $this->assertIsArray($firstNames);
        $this->assertNotEmpty($firstNames);
        
        // The function returns both uppercase and lowercase versions
        $allNames = array_map('strtoupper', $firstNames);
        $this->assertContains('ANDERS', $allNames);

        $lastNames = getNamesFromCsv(__DIR__ . '/test-data/etternavn.csv');
        $this->assertIsArray($lastNames);
        $this->assertNotEmpty($lastNames);
        
        $allLastNames = array_map('strtoupper', $lastNames);
        $this->assertContains('ANDERSEN', $allLastNames);
    }

    public function testMultipleRandomProfiles()
    {
        // Generate multiple profiles to ensure they're different
        $profiles = [];
        for ($i = 0; $i < 5; $i++) {
            $profiles[] = getRandomNameAndEmail();
        }

        // Test that we get different names (it's theoretically possible but unlikely to get duplicates with our test data)
        $emails = array_map(function($p) { return $p->email; }, $profiles);
        $uniqueEmails = array_unique($emails);
        $this->assertGreaterThan(3, count($uniqueEmails), "Should generate mostly unique profiles even with limited test data");
    }
}

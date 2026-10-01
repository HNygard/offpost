<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/GitVersion.php';

class GitVersionTest extends TestCase {
    private string $file;

    protected function setUp(): void {
        $this->file = sys_get_temp_dir() . '/offpost-git-version-test.txt';
        if (file_exists($this->file)) {
            unlink($this->file);
        }
    }

    protected function tearDown(): void {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function testReadsShaWrittenByGitRevParse(): void {
        // :: Setup
        file_put_contents($this->file, "9ace0754b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6\n");

        // :: Act
        $sha = GitVersion::getSha($this->file);

        // :: Assert
        $this->assertEquals('9ace0754b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6', $sha);
        $this->assertEquals('9ace075', GitVersion::shortSha($sha));
        $this->assertEquals(
            'https://github.com/hnygard/offpost/commit/9ace0754b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6',
            GitVersion::commitUrl($sha)
        );
    }

    public function testMissingFileGivesNull(): void {
        // :: Act
        $sha = GitVersion::getSha($this->file);

        // :: Assert
        $this->assertNull($sha, 'Development has no git-sha.txt');
    }

    public function testContentThatIsNotAShaGivesNull(): void {
        // :: Setup
        file_put_contents($this->file, "fatal: not a git repository\n");

        // :: Act
        $sha = GitVersion::getSha($this->file);

        // :: Assert
        $this->assertNull($sha, 'Garbage in the file must not be printed on every page');
    }
}

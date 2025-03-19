<?php

use PHPUnit\Framework\TestCase;

class ThreadEmailAttachmentTest extends TestCase {
    // :: Setup
    private $attachment;

    protected function setUp(): void {
        $this->attachment = new ThreadEmailAttachment();
    }

    // :: Tests

    public function testContentProperty() {
        // :: Setup
        $content = "Test content data";

        // :: Act
        $this->attachment->content = $content;

        // :: Assert
        $this->assertEquals($content, $this->attachment->content, "Content property should store and retrieve the attachment content");
    }

    public function testAllPropertiesAreAccessible() {
        // :: Setup
        $data = [
            'name' => 'test.pdf',
            'filename' => '/path/to/test.pdf',
            'filetype' => 'application/pdf',
            'location' => '/storage/attachments/test.pdf',
            'status_type' => 'processed',
            'status_text' => 'Successfully processed',
            'content' => 'PDF content here'
        ];

        // :: Act
        foreach ($data as $property => $value) {
            $this->attachment->$property = $value;
        }

        // :: Assert
        foreach ($data as $property => $value) {
            $this->assertEquals($value, $this->attachment->$property, "Property '$property' should be accessible");
        }
    }

    public function testGetIconClassForPdf() {
        // :: Setup
        $this->attachment->filetype = 'application/pdf';

        // :: Act
        $iconClass = $this->attachment->getIconClass();

        // :: Assert
        $this->assertEquals('icon-pdf', $iconClass, "PDF files should return 'icon-pdf' class");
    }

    public function testGetIconClassForImage() {
        // :: Setup
        $imageTypes = ['image/jpeg', 'image/png', 'image/gif'];

        foreach ($imageTypes as $type) {
            // :: Act
            $this->attachment->filetype = $type;
            $iconClass = $this->attachment->getIconClass();

            // :: Assert
            $this->assertEquals('icon-image', $iconClass, "$type files should return 'icon-image' class");
        }
    }

    public function testGetIconClassForUnknownType() {
        // :: Setup
        $this->attachment->filetype = 'application/unknown';

        // :: Act
        $iconClass = $this->attachment->getIconClass();

        // :: Assert
        $this->assertEquals('', $iconClass, "Unknown file types should return empty string");
    }
}

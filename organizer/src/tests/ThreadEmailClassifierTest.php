<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadEmailClassifier.php');

class ThreadEmailClassifierTest extends TestCase {
    private $classifier;

    protected function setUp(): void {
        parent::setUp();
        $this->classifier = new ThreadEmailClassifier();
    }

    public function testClassifyFirstEmailWhenOutbound() {
        // Create test thread with first email being outbound
        $thread = (object)[
            'emails' => [
                (object)[
                    'email_type' => 'OUT',
                    'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
                    'status_text' => 'Uklassifisert'
                ],
                (object)[
                    'email_type' => 'IN',
                    'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
                    'status_text' => 'Uklassifisert'
                ],
                (object)[
                    'email_type' => 'OUT',
                    'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
                    'status_text' => 'Uklassifisert'
                ]
            ]
        ];

        // Classify emails
        $result = $this->classifier->classifyEmails($thread);

        // Verify first email was classified
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::OUR_REQUEST, $result->emails[0]->status_type);
        $this->assertEquals('Initiell henvendelse', $result->emails[0]->status_text);
        $this->assertEquals('algo', $result->emails[0]->auto_classification);

        // Verify other emails were not classified
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::UNKNOWN->value, $result->emails[1]->status_type);
        $this->assertEquals('Uklassifisert', $result->emails[1]->status_text);
        $this->assertObjectNotHasProperty('auto_classification', $result->emails[1]);
        
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::UNKNOWN->value, $result->emails[2]->status_type);
        $this->assertEquals('Uklassifisert', $result->emails[2]->status_text);
        $this->assertObjectNotHasProperty('auto_classification', $result->emails[2]);
    }

    public function testDoNotClassifyFirstEmailWhenInbound() {
        // Create test thread with first email being inbound
        $thread = (object)[
            'emails' => [
                (object)[
                    'email_type' => 'IN',
                    'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
                    'status_text' => 'Uklassifisert'
                ],
                (object)[
                    'email_type' => 'OUT',
                    'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
                    'status_text' => 'Uklassifisert'
                ]
            ]
        ];

        // Classify emails
        $result = $this->classifier->classifyEmails($thread);

        // Verify no emails were classified
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::UNKNOWN->value, $result->emails[0]->status_type);
        $this->assertEquals('Uklassifisert', $result->emails[0]->status_text);
        $this->assertObjectNotHasProperty('auto_classification', $result->emails[0]);
        
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::UNKNOWN->value, $result->emails[1]->status_type);
        $this->assertEquals('Uklassifisert', $result->emails[1]->status_text);
        $this->assertObjectNotHasProperty('auto_classification', $result->emails[1]);
    }

    public function testClassifyEmptyThread() {
        // Test with empty thread
        $thread = (object)['emails' => []];
        $result = $this->classifier->classifyEmails($thread);
        $this->assertEmpty($result->emails);

        // Test with thread missing emails property
        $thread = new stdClass();
        $result = $this->classifier->classifyEmails($thread);
        $this->assertObjectNotHasProperty('emails', $result);
    }

    public function testRemoveAutoClassificationOnManualClassify() {
        // Create test email with auto classification
        $email = (object)[
            'email_type' => 'OUT',
            'status_type' => \App\Enums\ThreadEmailStatusType::INFO,
            'status_text' => 'Initiell henvendelse',
            'auto_classification' => 'algo'
        ];

        // Remove auto classification
        $result = $this->classifier->removeAutoClassification($email);

        // Verify auto classification was removed
        $this->assertObjectNotHasProperty('auto_classification', $result);
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::INFO, $result->status_type);
        $this->assertEquals('Initiell henvendelse', $result->status_text);
    }

    public function testDoNotClassifyEmailWithExistingStatus() {
        // Create test thread with first outbound email having existing status
        $thread = (object)[
            'emails' => [
                (object)[
                    'email_type' => 'OUT',
                    'status_type' => \App\Enums\ThreadEmailStatusType::SUCCESS->value,
                    'status_text' => 'Existing Status'
                ]
            ]
        ];

        // Classify emails
        $result = $this->classifier->classifyEmails($thread);

        // Verify email status was not changed
        $this->assertEquals(\App\Enums\ThreadEmailStatusType::SUCCESS->value, $result->emails[0]->status_type);
        $this->assertEquals('Existing Status', $result->emails[0]->status_text);
        $this->assertObjectNotHasProperty('auto_classification', $result->emails[0]);
    }

    public function testRemoveAutoClassificationOnUnclassifiedEmail() {
        // Create test email without auto classification
        $email = (object)[
            'email_type' => 'OUT',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Uklassifisert'
        ];

        // Try to remove auto classification
        $result = $this->classifier->removeAutoClassification($email);

        // Verify email remains unchanged
        $this->assertEquals($email, $result);
    }
}

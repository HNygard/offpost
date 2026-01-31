<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapEmail;

require_once __DIR__ . '/../../class/Imap/ImapEmail.php';

class ImapEmailTest extends TestCase {

    public function testGetEmailSubjectWithValidEml() {
        // :: Setup
        $validEml = "Return-Path: <test@example.com>\r\n" .
                   "From: Test Sender <test@example.com>\r\n" .
                   "To: recipient@example.com\r\n" .
                   "Subject: Test Subject Line\r\n" .
                   "Date: Mon, 14 Apr 2025 12:16:03 +0000\r\n" .
                   "Message-ID: <test123@example.com>\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "\r\n" .
                   "This is the email body.";

        // :: Act
        $subject = ImapEmail::getEmailSubject($validEml);

        // :: Assert
        $this->assertEquals('Test Subject Line', $subject, 'Should extract subject from valid EML');
    }

    public function testGetEmailSubjectWithEncodedSubject() {
        // :: Setup
        $emlWithEncodedSubject = "Return-Path: <test@example.com>\r\n" .
                                "From: Test Sender <test@example.com>\r\n" .
                                "To: recipient@example.com\r\n" .
                                "Subject: =?iso-8859-1?Q?Offentlig_journal_for_M=F8re_og_Romsdal_politidistrikt_uke?=\r\n" .
                                " =?iso-8859-1?Q?_15_-_2025_?=\r\n" .
                                "Date: Mon, 14 Apr 2025 12:16:03 +0000\r\n" .
                                "Message-ID: <test123@example.com>\r\n" .
                                "Content-Type: text/plain\r\n" .
                                "\r\n" .
                                "This is the email body.";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emlWithEncodedSubject);

        // :: Assert
        $this->assertEquals('Offentlig journal for Møre og Romsdal politidistrikt uke 15 - 2025 ', $subject, 
                           'Should decode encoded subject header');
    }

    public function testGetEmailSubjectWithNoSubjectHeader() {
        // :: Setup
        $emlWithoutSubject = "Return-Path: <test@example.com>\r\n" .
                            "From: Test Sender <test@example.com>\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Date: Mon, 14 Apr 2025 12:16:03 +0000\r\n" .
                            "Message-ID: <test123@example.com>\r\n" .
                            "Content-Type: text/plain\r\n" .
                            "\r\n" .
                            "This is the email body.";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emlWithoutSubject);

        // :: Assert
        // Zbateson returns null for missing headers, which is converted to empty string
        $this->assertEquals('', $subject,
                           'Should return empty string when subject header is missing');
    }

    public function testGetEmailSubjectWithEmptySubject() {
        // :: Setup
        $emlWithEmptySubject = "Return-Path: <test@example.com>\r\n" .
                              "From: Test Sender <test@example.com>\r\n" .
                              "To: recipient@example.com\r\n" .
                              "Subject: \r\n" .
                              "Date: Mon, 14 Apr 2025 12:16:03 +0000\r\n" .
                              "Message-ID: <test123@example.com>\r\n" .
                              "Content-Type: text/plain\r\n" .
                              "\r\n" .
                              "This is the email body.";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emlWithEmptySubject);

        // :: Assert
        $this->assertEquals('', $subject, 'Should return empty string for empty subject');
    }

    public function testGetEmailSubjectWithMalformedEml() {
        // :: Setup
        $malformedEml = "This is not a valid EML format";

        // :: Act
        $subject = ImapEmail::getEmailSubject($malformedEml);

        // :: Assert
        // Zbateson parses malformed emails gracefully, returning empty subject if no Subject header
        $this->assertEquals('', $subject,
                           'Should return empty string for malformed EML without subject');
    }

    public function testGetEmailSubjectWithPartialEml() {
        // :: Setup
        $partialEml = "Subject: Partial EML Test\r\n" .
                     "From: test@example.com\r\n";

        // :: Act
        $subject = ImapEmail::getEmailSubject($partialEml);

        // :: Assert
        $this->assertEquals('Partial EML Test', $subject, 
                           'Should extract subject from partial EML with minimal headers');
    }

    public function testGetEmailSubjectWithMultilineSubject() {
        // :: Setup
        $emlWithMultilineSubject = "Return-Path: <test@example.com>\r\n" .
                                  "From: Test Sender <test@example.com>\r\n" .
                                  "To: recipient@example.com\r\n" .
                                  "Subject: This is a very long subject line that spans\r\n" .
                                  " multiple lines in the email header\r\n" .
                                  "Date: Mon, 14 Apr 2025 12:16:03 +0000\r\n" .
                                  "Message-ID: <test123@example.com>\r\n" .
                                  "Content-Type: text/plain\r\n" .
                                  "\r\n" .
                                  "This is the email body.";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emlWithMultilineSubject);

        // :: Assert
        $this->assertEquals('This is a very long subject line that spans multiple lines in the email header', $subject, 
                           'Should handle multiline subject headers correctly');
    }

    public function testGetEmailSubjectWithSpecialCharacters() {
        // :: Setup
        $emlWithSpecialChars = "Return-Path: <test@example.com>\r\n" .
                              "From: Test Sender <test@example.com>\r\n" .
                              "To: recipient@example.com\r\n" .
                              "Subject: Test with special chars: åæø ÄÖÜ €£$\r\n" .
                              "Date: Mon, 14 Apr 2025 12:16:03 +0000\r\n" .
                              "Message-ID: <test123@example.com>\r\n" .
                              "Content-Type: text/plain; charset=utf-8\r\n" .
                              "\r\n" .
                              "This is the email body.";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emlWithSpecialChars);

        // :: Assert
        // Zbateson handles raw UTF-8 characters in headers natively
        $this->assertEquals('Test with special chars: åæø ÄÖÜ €£$', $subject,
                           'Should preserve special characters in subject header');
    }

    public function testGetEmailSubjectWithEmptyString() {
        // :: Setup
        $emptyEml = "";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emptyEml);

        // :: Assert
        // Zbateson parses empty strings gracefully, returning empty subject
        $this->assertEquals('', $subject,
                           'Should return empty string for empty EML string');
    }

    public function testGetEmailSubjectWithUtf8ImapHeader() {
        // :: Setup
        $emlWithUtf8Header = "Subject: =?UTF-8?Q?Re:=20Innsyn=20valggjennomf=C3=B8ring=2C=20Nord-Odal=20kommune?=";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emlWithUtf8Header);

        // :: Assert
        $this->assertEquals('Re: Innsyn valggjennomføring, Nord-Odal kommune', $subject,
                           'Should handle UTF-8 encoded subject header correctly');
    }

    public function testGetEmailAddressesWithMultipleXForwardedFor() {
        // :: Setup
        $rawEmail = "From: sender@example.com\r\n" .
                   "To: recipient@example.com\r\n" .
                   "X-Forwarded-For: first@example.com\r\n" .
                   "X-Forwarded-For: second@example.com\r\n" .
                   "X-Forwarded-For: third@example.com\r\n" .
                   "Subject: Test\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "\r\n" .
                   "Body";

        // Create ImapEmail with minimal headers
        $email = new ImapEmail();
        $email->mailHeaders = (object)[
            'from' => [(object)['mailbox' => 'sender', 'host' => 'example.com']],
            'to' => [(object)['mailbox' => 'recipient', 'host' => 'example.com']]
        ];

        // :: Act
        $addresses = $email->getEmailAddresses($rawEmail);

        // :: Assert
        $this->assertContains('first@example.com', $addresses, 'Should capture first X-Forwarded-For header');
        $this->assertContains('second@example.com', $addresses, 'Should capture second X-Forwarded-For header');
        $this->assertContains('third@example.com', $addresses, 'Should capture third X-Forwarded-For header');
        $this->assertContains('sender@example.com', $addresses, 'Should include From address');
        $this->assertContains('recipient@example.com', $addresses, 'Should include To address');
    }

    public function testGetEmailAddressesWithSingleXForwardedFor() {
        // :: Setup
        $rawEmail = "From: sender@example.com\r\n" .
                   "To: recipient@example.com\r\n" .
                   "X-Forwarded-For: forwarded@example.com\r\n" .
                   "Subject: Test\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "\r\n" .
                   "Body";

        $email = new ImapEmail();
        $email->mailHeaders = (object)[
            'from' => [(object)['mailbox' => 'sender', 'host' => 'example.com']],
            'to' => [(object)['mailbox' => 'recipient', 'host' => 'example.com']]
        ];

        // :: Act
        $addresses = $email->getEmailAddresses($rawEmail);

        // :: Assert
        $this->assertContains('forwarded@example.com', $addresses, 'Should capture single X-Forwarded-For header');
    }

    public function testGetEmailAddressesWithNoXForwardedFor() {
        // :: Setup
        $rawEmail = "From: sender@example.com\r\n" .
                   "To: recipient@example.com\r\n" .
                   "Subject: Test\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "\r\n" .
                   "Body";

        $email = new ImapEmail();
        $email->mailHeaders = (object)[
            'from' => [(object)['mailbox' => 'sender', 'host' => 'example.com']],
            'to' => [(object)['mailbox' => 'recipient', 'host' => 'example.com']]
        ];

        // :: Act
        $addresses = $email->getEmailAddresses($rawEmail);

        // :: Assert
        $this->assertCount(2, $addresses, 'Should only have From and To addresses');
        $this->assertContains('sender@example.com', $addresses);
        $this->assertContains('recipient@example.com', $addresses);
    }
}

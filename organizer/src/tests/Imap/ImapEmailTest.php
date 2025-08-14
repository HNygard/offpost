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
        $this->assertStringStartsWith('Error getting subject - ', $subject, 
                                     'Should return error message when subject header is missing');
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
        $this->assertStringStartsWith('Error getting subject - ', $subject, 
                                     'Should return error message for malformed EML');
        $this->assertStringContainsString('subject not found', $subject, 
                                         'Error message should indicate subject header not found');
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
        $this->assertStringStartsWith('Error getting subject - ', $subject, 
                                     'Should return error message for invalid header value with raw special characters');
        $this->assertStringContainsString('Invalid header value', $subject, 
                                         'Error message should indicate invalid header value');
    }

    public function testGetEmailSubjectWithEmptyString() {
        // :: Setup
        $emptyEml = "";

        // :: Act
        $subject = ImapEmail::getEmailSubject($emptyEml);

        // :: Assert
        $this->assertStringStartsWith('Error getting subject - ', $subject, 
                                     'Should return error message for empty EML string');
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
}

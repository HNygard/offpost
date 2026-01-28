<?php

/**
 * Real API tests for EmailParserOpenAi
 * 
 * These tests call the actual OpenAI API and require a valid API key.
 * Run with: OPENAI_API_KEY=your-key phpunit tests/Ai/EmailParserOpenAiRealApiTest.php
 * 
 * @group real-api
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Ai/EmailParserOpenAi.php';
require_once __DIR__ . '/../../class/Ai/OpenAiIntegration.php';

use Offpost\Ai\EmailParserOpenAi;
use Offpost\Ai\OpenAiIntegration;
use Offpost\Ai\ParsedEmail;

/**
 * OpenAI integration without database logging for testing
 */
class OpenAiIntegrationNoLogging extends OpenAiIntegration
{
    /**
     * Override sendRequest to skip database logging
     */
    public function sendRequest(array $input, $structured_output, string $model, string $source = null, ?int $extractionId = null): ?array
    {
        if ($source == null) {
            $source = 'unknown';
        }
        $apiEndpoint = 'https://api.openai.com/v1/responses';
        
        $requestData = [
            'model' => $model,
            'input' => $input
        ];
        if ($structured_output) {
            $requestData['text'] = [
                'format' => $structured_output
            ];
        }
        
        // Skip logging - directly send the request
        $responseData = $this->internalSendRequest($apiEndpoint, $requestData);
        $response = $responseData['response'];
        $httpCode = $responseData['httpCode'];
        $error = $responseData['error'];
        $debuggingInfo = $responseData['debuggingInfo'];
        
        if ($error) {
            throw new \Exception("OpenAI API error: Curl error: $error");
        }
        
        if ($httpCode >= 400) {
            throw new \Exception("OpenAI API error: HTTP code $httpCode\n$response");
        }
        
        return json_decode($response, true);
    }
}

/**
 * EmailParserOpenAi subclass that uses the no-logging integration
 */
class EmailParserOpenAiNoLogging extends EmailParserOpenAi
{
    public function __construct(string $apiKey, string $model = 'gpt-4o-mini')
    {
        // Call parent constructor but then replace the integration
        parent::__construct($apiKey, $model);
        
        // Use reflection to replace the openai property on parent class
        $reflection = new \ReflectionClass(EmailParserOpenAi::class);
        $property = $reflection->getProperty('openai');
        $property->setAccessible(true);
        $property->setValue($this, new OpenAiIntegrationNoLogging($apiKey));
    }
}

class EmailParserOpenAiRealApiTest extends PHPUnit\Framework\TestCase
{
    private ?EmailParserOpenAiNoLogging $parser = null;
    private static ?string $apiKey = null;

    public static function setUpBeforeClass(): void
    {
        // Get API key from environment variable
        self::$apiKey = getenv('OPENAI_API_KEY');
        
        if (empty(self::$apiKey)) {
            self::markTestSkipped('OPENAI_API_KEY environment variable not set. Skipping real API tests.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        if (empty(self::$apiKey)) {
            $this->markTestSkipped('OPENAI_API_KEY environment variable not set.');
            return;
        }
        
        $this->parser = new EmailParserOpenAiNoLogging(self::$apiKey);
    }

    /**
     * Test parsing a simple plain text email with real API
     */
    public function testParseSimplePlainTextEmailRealApi(): void
    {
        $eml = "From: John Doe <john.doe@example.com>\r\n" .
               "To: Jane Smith <jane.smith@example.com>\r\n" .
               "Subject: Meeting Tomorrow\r\n" .
               "Date: Mon, 27 Jan 2026 10:30:00 +0100\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "\r\n" .
               "Hi Jane,\r\n\r\n" .
               "Just a reminder that we have a meeting scheduled for tomorrow at 2pm.\r\n\r\n" .
               "Best regards,\r\nJohn";

        $result = $this->parser->parseEmail($eml);

        // Verify the parsed result contains expected data
        $this->assertInstanceOf(ParsedEmail::class, $result);
        $this->assertStringContainsString('john', strtolower($result->from));
        $this->assertStringContainsString('jane', strtolower($result->to));
        $this->assertStringContainsString('Meeting', $result->subject);
        $this->assertStringContainsString('meeting', strtolower($result->getBody()));
        $this->assertStringContainsString('tomorrow', strtolower($result->getBody()));
    }

    /**
     * Test parsing email with Norwegian characters (æ, ø, å)
     */
    public function testParseNorwegianCharactersRealApi(): void
    {
        $eml = "From: =?utf-8?Q?P=C3=A5l_=C3=98stberg?= <pal@kommune.no>\r\n" .
               "To: =?utf-8?Q?Kj=C3=A6re_v=C3=A5r?= <recipient@test.no>\r\n" .
               "Subject: =?utf-8?Q?Innsynsforesp=C3=B8rsel_-_M=C3=B8tereferat?=\r\n" .
               "Date: Tue, 28 Jan 2026 09:00:00 +0100\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "\r\n" .
               "Hei,\r\n\r\n" .
               "Vi viser til din innsynsforespørsel angående møtereferatet.\r\n\r\n" .
               "Dokumentet er vedlagt.\r\n\r\n" .
               "Med vennlig hilsen,\r\n" .
               "Pål Østberg\r\n" .
               "Saksbehandler";

        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        // Verify Norwegian characters are decoded correctly
        $this->assertStringContainsString('Pål', $result->from);
        $this->assertStringContainsString('Østberg', $result->from);
        $this->assertStringContainsString('Innsynsforespørsel', $result->subject);
        $this->assertStringContainsString('innsynsforespørsel', strtolower($result->getBody()));
    }

    /**
     * Test parsing multipart email with HTML and plain text
     */
    public function testParseMultipartEmailRealApi(): void
    {
        $eml = "From: sender@example.com\r\n" .
               "To: recipient@example.com\r\n" .
               "Subject: Multipart Test Email\r\n" .
               "Date: Tue, 28 Jan 2026 11:00:00 +0100\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: multipart/alternative; boundary=\"----=_Part_123\"\r\n" .
               "\r\n" .
               "------=_Part_123\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "\r\n" .
               "This is the plain text version of the email.\r\n" .
               "It contains important information.\r\n" .
               "\r\n" .
               "------=_Part_123\r\n" .
               "Content-Type: text/html; charset=utf-8\r\n" .
               "\r\n" .
               "<html>\r\n" .
               "<body>\r\n" .
               "<h1>Important Information</h1>\r\n" .
               "<p>This is the <strong>HTML version</strong> of the email.</p>\r\n" .
               "<p>It contains important information.</p>\r\n" .
               "</body>\r\n" .
               "</html>\r\n" .
               "\r\n" .
               "------=_Part_123--";

        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        $this->assertStringContainsString('sender@example.com', $result->from);
        $this->assertStringContainsString('recipient@example.com', $result->to);
        $this->assertEquals('Multipart Test Email', $result->subject);
        
        // Should have either plain text or HTML as text
        $body = $result->getBody();
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('important', strtolower($body));
    }

    /**
     * Test parsing email with CC and Reply-To headers
     */
    public function testParseCcAndReplyToRealApi(): void
    {
        $eml = "From: John <john@example.com>\r\n" .
               "To: Jane <jane@example.com>\r\n" .
               "Cc: Bob <bob@example.com>, Alice <alice@example.com>\r\n" .
               "Reply-To: noreply@example.com\r\n" .
               "Subject: Test with CC and Reply-To\r\n" .
               "Date: Tue, 28 Jan 2026 12:00:00 +0100\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "\r\n" .
               "This email has CC and Reply-To headers.";

        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        $this->assertNotNull($result->cc);
        $this->assertStringContainsString('bob', strtolower($result->cc));
        $this->assertNotNull($result->replyTo);
        $this->assertStringContainsString('noreply', strtolower($result->replyTo));
    }

    /**
     * Test parsing a real-world email from test data folder
     */
    public function testParseRealWorldEmailFromTestData(): void
    {
        // Try Docker container path first, then local dev path
        $dockerPath = '/organizer-data/test-emails/bcc-with-x-forwarded-for-header.eml';
        $localPath = __DIR__ . '/../../../../data/test-emails/bcc-with-x-forwarded-for-header.eml';
        $emlPath = file_exists($dockerPath) ? $dockerPath : $localPath;
        
        if (!file_exists($emlPath)) {
            $this->markTestSkipped('Test email file not found: ' . $emlPath);
            return;
        }

        $eml = file_get_contents($emlPath);
        
        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        // Just verify we can parse it without errors
        $this->assertNotEmpty($result->from);
        $this->assertNotEmpty($result->to);
        $this->assertNotEmpty($result->subject);
    }

    /**
     * Test parsing email with quoted-printable encoding
     */
    public function testParseQuotedPrintableEncodingRealApi(): void
    {
        $eml = "From: test@example.com\r\n" .
               "To: recipient@example.com\r\n" .
               "Subject: =?utf-8?Q?Test_med_=C3=A6=C3=B8=C3=A5?=\r\n" .
               "Date: Tue, 28 Jan 2026 13:00:00 +0100\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "Content-Transfer-Encoding: quoted-printable\r\n" .
               "\r\n" .
               "Dette er en test med norske tegn: =C3=A6=C3=B8=C3=A5 =C3=86=C3=98=C3=85";

        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        // Verify quoted-printable is decoded
        $this->assertStringContainsString('æøå', $result->subject);
        $body = $result->getBody();
        $this->assertStringContainsString('æøå', $body);
        $this->assertStringContainsString('ÆØÅ', $body);
    }

    /**
     * Test parsing a government/kommune style email
     */
    public function testParseKommuneStyleEmailRealApi(): void
    {
        $eml = "From: postmottak@kommune.no\r\n" .
               "To: innsyn@offpost.no\r\n" .
               "Subject: Svar på innsynsforespørsel - Sak 2025/12345\r\n" .
               "Date: Tue, 28 Jan 2026 14:00:00 +0100\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "\r\n" .
               "Viser til din innsynsforespørsel datert 15.01.2026.\r\n\r\n" .
               "Vi har behandlet din forespørsel i henhold til offentleglova.\r\n\r\n" .
               "Saksnummer: 2025/12345-3\r\n" .
               "Dokumentnummer: 2025/12345-3-1\r\n\r\n" .
               "Vedlagt finner du de forespurte dokumentene.\r\n\r\n" .
               "Med vennlig hilsen\r\n" .
               "Dokumentsenteret\r\n" .
               "Test Kommune";

        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        $this->assertStringContainsString('postmottak@kommune.no', $result->from);
        $this->assertStringContainsString('2025/12345', $result->subject);
        
        $body = $result->getBody();
        $this->assertStringContainsString('innsynsforespørsel', strtolower($body));
        $this->assertStringContainsString('2025/12345', $body);
    }

    /**
     * Test that the API handles edge cases without crashing
     */
    public function testParseMinimalEmailRealApi(): void
    {
        // Minimal valid email
        $eml = "From: a@b.c\r\n" .
               "To: d@e.f\r\n" .
               "Subject: X\r\n" .
               "Date: Tue, 28 Jan 2026 15:00:00 +0100\r\n" .
               "\r\n" .
               "Y";

        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        $this->assertNotEmpty($result->from);
        $this->assertNotEmpty($result->to);
        $this->assertEquals('X', $result->subject);
    }

    /**
     * Test parsing the complex email with strange characters from test data
     */
    public function testParseComplexEmailWithAttachmentNamesRealApi(): void
    {
        // Try Docker container path first, then local dev path
        $dockerPath = '/organizer-data/test-emails/attachment-with-strange-characters.eml';
        $localPath = __DIR__ . '/../../../../data/test-emails/attachment-with-strange-characters.eml';
        $emlPath = file_exists($dockerPath) ? $dockerPath : $localPath;
        
        if (!file_exists($emlPath)) {
            $this->markTestSkipped('Test email file not found: ' . $emlPath);
            return;
        }

        $eml = file_get_contents($emlPath);
        
        $result = $this->parser->parseEmail($eml);

        $this->assertInstanceOf(ParsedEmail::class, $result);
        // Verify we can parse the complex email
        $this->assertNotEmpty($result->from);
        $this->assertStringContainsString('kristiansand', strtolower($result->from));
        $this->assertStringContainsString('Dokument', $result->subject);
    }
}

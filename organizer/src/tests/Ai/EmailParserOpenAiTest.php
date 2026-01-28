<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Ai/EmailParserOpenAi.php';
require_once __DIR__ . '/../../class/Ai/OpenAiIntegration.php';

use Offpost\Ai\EmailParserOpenAi;
use Offpost\Ai\ParsedEmail;
use Offpost\Ai\OpenAiIntegration;

/**
 * Mock class for EmailParserOpenAi that overrides the OpenAI integration
 */
class EmailParserOpenAiMock extends EmailParserOpenAi
{
    private array $mockResponse = [];

    public function __construct(string $apiKey, string $model = 'gpt-4o-mini')
    {
        parent::__construct($apiKey, $model);
    }

    public function setMockResponse(array $parsedContent): void
    {
        $this->mockResponse = $parsedContent;
    }

    public function parseEmail(string $eml, ?int $extractionId = null): ParsedEmail
    {
        if (empty($eml)) {
            throw new \Exception("Empty email content provided for parsing");
        }
        
        if (empty($this->mockResponse)) {
            throw new \Exception("No mock response configured");
        }

        // Return mocked parsed email
        $parsed = new ParsedEmail();
        
        $headers = $this->mockResponse['headers'] ?? [];
        $parsed->from = $headers['from'] ?? '';
        $parsed->to = $headers['to'] ?? '';
        $parsed->subject = $headers['subject'] ?? '';
        $parsed->date = $headers['date'] ?? '';
        $parsed->cc = $headers['cc'] ?? null;
        $parsed->replyTo = $headers['reply_to'] ?? null;

        $body = $this->mockResponse['body'] ?? [];
        $parsed->plainText = $body['plain_text'] ?? '';
        $parsed->htmlAsText = $body['html_as_text'] ?? '';

        return $parsed;
    }
}

class EmailParserOpenAiTest extends PHPUnit\Framework\TestCase
{
    private EmailParserOpenAiMock $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new EmailParserOpenAiMock('test-api-key');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test parsing a simple plain text email
     */
    public function testParseSimplePlainTextEmail(): void
    {
        // :: Setup
        $eml = "From: sender@example.com\r\n" .
               "To: recipient@example.com\r\n" .
               "Subject: Test Subject\r\n" .
               "Date: Mon, 1 Jan 2024 12:00:00 +0000\r\n" .
               "Content-Type: text/plain\r\n" .
               "\r\n" .
               "This is the email body.";

        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'sender@example.com',
                'to' => 'recipient@example.com',
                'subject' => 'Test Subject',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => null,
                'reply_to' => null
            ],
            'body' => [
                'plain_text' => 'This is the email body.',
                'html_as_text' => null
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail($eml);

        // :: Assert
        $this->assertEquals('sender@example.com', $result->from);
        $this->assertEquals('recipient@example.com', $result->to);
        $this->assertEquals('Test Subject', $result->subject);
        $this->assertEquals('Mon, 1 Jan 2024 12:00:00 +0000', $result->date);
        $this->assertEquals('This is the email body.', $result->plainText);
        $this->assertNull($result->cc);
        $this->assertNull($result->replyTo);
    }

    /**
     * Test parsing email with HTML body
     */
    public function testParseEmailWithHtmlBody(): void
    {
        // :: Setup
        $eml = "From: sender@example.com\r\n" .
               "To: recipient@example.com\r\n" .
               "Subject: HTML Email\r\n" .
               "Date: Mon, 1 Jan 2024 12:00:00 +0000\r\n" .
               "Content-Type: text/html\r\n" .
               "\r\n" .
               "<html><body><h1>Hello</h1><p>This is HTML content.</p></body></html>";

        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'sender@example.com',
                'to' => 'recipient@example.com',
                'subject' => 'HTML Email',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => null,
                'reply_to' => null
            ],
            'body' => [
                'plain_text' => null,
                'html_as_text' => "Hello\n\nThis is HTML content."
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail($eml);

        // :: Assert
        $this->assertEquals("Hello\n\nThis is HTML content.", $result->htmlAsText);
        $this->assertEquals("Hello\n\nThis is HTML content.", $result->getBody());
    }

    /**
     * Test parsing multipart email with both plain text and HTML
     */
    public function testParseMultipartEmail(): void
    {
        // :: Setup
        $eml = "From: sender@example.com\r\n" .
               "To: recipient@example.com\r\n" .
               "Subject: Multipart Email\r\n" .
               "Date: Mon, 1 Jan 2024 12:00:00 +0000\r\n" .
               "Content-Type: multipart/alternative; boundary=\"boundary123\"\r\n" .
               "\r\n" .
               "--boundary123\r\n" .
               "Content-Type: text/plain\r\n" .
               "\r\n" .
               "Plain text version\r\n" .
               "--boundary123\r\n" .
               "Content-Type: text/html\r\n" .
               "\r\n" .
               "<html><body>HTML version</body></html>\r\n" .
               "--boundary123--";

        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'sender@example.com',
                'to' => 'recipient@example.com',
                'subject' => 'Multipart Email',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => null,
                'reply_to' => null
            ],
            'body' => [
                'plain_text' => 'Plain text version',
                'html_as_text' => 'HTML version'
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail($eml);

        // :: Assert
        $this->assertEquals('Plain text version', $result->plainText);
        $this->assertEquals('HTML version', $result->htmlAsText);
        $this->assertEquals('Plain text version', $result->getBody());
    }

    /**
     * Test parsing email with Norwegian characters in headers
     */
    public function testParseEmailWithNorwegianCharacters(): void
    {
        // :: Setup
        $eml = "From: =?utf-8?Q?P=C3=A5l_=C3=86rlig?= <pal@example.com>\r\n" .
               "To: =?utf-8?Q?Kj=C3=A6re_venner?= <friends@example.com>\r\n" .
               "Subject: =?utf-8?Q?M=C3=B8te_i_morgen?=\r\n" .
               "Date: Mon, 1 Jan 2024 12:00:00 +0000\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "\r\n" .
               "Hei på deg!";

        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'Pål Ærlig <pal@example.com>',
                'to' => 'Kjære venner <friends@example.com>',
                'subject' => 'Møte i morgen',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => null,
                'reply_to' => null
            ],
            'body' => [
                'plain_text' => 'Hei på deg!',
                'html_as_text' => null
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail($eml);

        // :: Assert
        $this->assertStringContainsString('Pål', $result->from);
        $this->assertStringContainsString('Ærlig', $result->from);
        $this->assertStringContainsString('Kjære', $result->to);
        $this->assertEquals('Møte i morgen', $result->subject);
        $this->assertEquals('Hei på deg!', $result->plainText);
    }

    /**
     * Test parsing email with CC and Reply-To headers
     */
    public function testParseEmailWithCcAndReplyTo(): void
    {
        // :: Setup
        $eml = "From: sender@example.com\r\n" .
               "To: recipient@example.com\r\n" .
               "Cc: cc1@example.com, cc2@example.com\r\n" .
               "Reply-To: reply@example.com\r\n" .
               "Subject: Test\r\n" .
               "Date: Mon, 1 Jan 2024 12:00:00 +0000\r\n" .
               "Content-Type: text/plain\r\n" .
               "\r\n" .
               "Body";

        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'sender@example.com',
                'to' => 'recipient@example.com',
                'subject' => 'Test',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => 'cc1@example.com, cc2@example.com',
                'reply_to' => 'reply@example.com'
            ],
            'body' => [
                'plain_text' => 'Body',
                'html_as_text' => null
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail($eml);

        // :: Assert
        $this->assertEquals('cc1@example.com, cc2@example.com', $result->cc);
        $this->assertEquals('reply@example.com', $result->replyTo);
    }

    /**
     * Test that empty email throws exception
     */
    public function testEmptyEmailThrowsException(): void
    {
        // :: Setup & Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Empty email content provided for parsing');
        
        $this->parser->parseEmail('');
    }

    /**
     * Test getCombinedBody returns both plain text and HTML when different
     */
    public function testGetCombinedBodyWithDifferentContent(): void
    {
        // :: Setup
        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'sender@example.com',
                'to' => 'recipient@example.com',
                'subject' => 'Test',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => null,
                'reply_to' => null
            ],
            'body' => [
                'plain_text' => 'Plain text content',
                'html_as_text' => 'Different HTML content'
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail("From: test@test.com\r\n\r\nBody");
        $combined = $result->getCombinedBody();

        // :: Assert
        $this->assertStringContainsString('Plain text content', $combined);
        $this->assertStringContainsString('Different HTML content', $combined);
    }

    /**
     * Test getCombinedBody returns only plain text when HTML is same
     */
    public function testGetCombinedBodyWithSameContent(): void
    {
        // :: Setup
        $this->parser->setMockResponse([
            'headers' => [
                'from' => 'sender@example.com',
                'to' => 'recipient@example.com',
                'subject' => 'Test',
                'date' => 'Mon, 1 Jan 2024 12:00:00 +0000',
                'cc' => null,
                'reply_to' => null
            ],
            'body' => [
                'plain_text' => 'Same content',
                'html_as_text' => 'Same content'
            ]
        ]);

        // :: Act
        $result = $this->parser->parseEmail("From: test@test.com\r\n\r\nBody");
        $combined = $result->getCombinedBody();

        // :: Assert
        $this->assertEquals('Same content', $combined);
    }

    /**
     * Test ParsedEmail class instantiation
     */
    public function testParsedEmailClassDefaults(): void
    {
        // :: Setup & Act
        $parsed = new ParsedEmail();

        // :: Assert
        $this->assertEquals('', $parsed->from);
        $this->assertEquals('', $parsed->to);
        $this->assertEquals('', $parsed->subject);
        $this->assertEquals('', $parsed->date);
        $this->assertNull($parsed->cc);
        $this->assertNull($parsed->replyTo);
        $this->assertEquals('', $parsed->plainText);
        $this->assertEquals('', $parsed->htmlAsText);
        $this->assertEquals('', $parsed->getBody());
        $this->assertEquals('', $parsed->getCombinedBody());
    }
}

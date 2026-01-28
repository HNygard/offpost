<?php

namespace Offpost\Ai;

require_once __DIR__ . '/OpenAiIntegration.php';

/**
 * Email parser that uses OpenAI to extract structured data from raw email content.
 * 
 * This module provides an alternative to the laminas-mail library for parsing emails.
 * It sends the raw EML content to OpenAI and extracts:
 * - Headers (from, to, subject, date, etc.)
 * - Plain text body
 * - HTML body (converted to plain text)
 */
class EmailParserOpenAi
{
    private OpenAiIntegration $openai;
    private string $model;

    /**
     * Constructor
     * 
     * @param string $apiKey OpenAI API key
     * @param string $model Model to use (defaults to gpt-4o-mini for cost efficiency)
     */
    public function __construct(string $apiKey, string $model = 'gpt-4o-mini')
    {
        $this->openai = new OpenAiIntegration($apiKey);
        $this->model = $model;
    }

    /**
     * Parse an email and extract its content using OpenAI
     * 
     * @param string $eml Raw email content (EML format)
     * @param int|null $extractionId Optional extraction ID for logging
     * @return ParsedEmail Parsed email data
     * @throws \Exception If parsing fails
     */
    public function parseEmail(string $eml, ?int $extractionId = null): ParsedEmail
    {
        if (empty($eml)) {
            throw new \Exception("Empty email content provided for parsing");
        }

        // Truncate very long emails to avoid token limits
        // 100KB is an approximate limit - actual token count varies by encoding and language
        $maxLength = 100000;
        $truncated = false;
        if (strlen($eml) > $maxLength) {
            // Try to truncate at a newline to avoid cutting mid-header or mid-content
            $lastNewline = strrpos(substr($eml, 0, $maxLength), "\n");
            if ($lastNewline !== false && $lastNewline > $maxLength * 0.9) {
                $eml = substr($eml, 0, $lastNewline + 1);
            } else {
                $eml = substr($eml, 0, $maxLength);
            }
            $truncated = true;
        }

        // Filter out base64 encoded content blocks (typically found in MIME attachments)
        // Match sequences of base64 characters that span multiple lines (MIME-style encoding)
        $cleanedEml = preg_replace('/(?:[A-Za-z0-9+\/]{60,76}\r?\n)+[A-Za-z0-9+\/]+=*/', '[Base64 content removed]', $eml);

        $prompt = $this->buildPrompt($truncated);
        
        $input = [
            $this->openai->createTextMessage($prompt, 'system'),
            $this->openai->createTextMessage("Parse this email:\n\n" . $cleanedEml, 'user')
        ];

        $structuredOutput = $this->getStructuredOutput();

        $response = $this->openai->sendRequest(
            $input,
            $structuredOutput,
            $this->model,
            'email_parser',
            $extractionId
        );

        return $this->processResponse($response);
    }

    /**
     * Build the system prompt for email parsing
     * 
     * @param bool $truncated Whether the email was truncated
     * @return string System prompt
     */
    private function buildPrompt(bool $truncated): string
    {
        $prompt = "You are an email parser. Extract the following information from the raw email (EML format):

1. Headers:
   - from: The sender's email address and name
   - to: The recipient(s) email address(es) and names
   - subject: The email subject
   - date: The date the email was sent
   - cc: Carbon copy recipients (if any)
   - reply_to: Reply-to address (if different from sender)

2. Body:
   - plain_text: The plain text version of the email body. If only HTML is available, extract the text content from HTML (remove tags, decode entities).
   - html_as_text: If there's an HTML version, convert it to readable plain text (preserve line breaks, remove scripts/styles).

Important:
- Handle MIME multipart messages correctly
- Decode quoted-printable and base64 encoded content
- Convert character encodings to UTF-8
- Handle RFC 2047 encoded headers (=?charset?encoding?text?=)
- If a field is not present, use null";

        if ($truncated) {
            $prompt .= "\n\nNote: This email was truncated due to size. Extract what you can from the available content.";
        }

        return $prompt;
    }

    /**
     * Get the structured output schema for OpenAI
     * 
     * @return array Structured output schema
     */
    private function getStructuredOutput(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'parsed_email',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'headers' => [
                            'type' => 'object',
                            'properties' => [
                                'from' => ['type' => 'string', 'description' => 'Sender email and name'],
                                'to' => ['type' => 'string', 'description' => 'Recipient(s) email and name'],
                                'subject' => ['type' => 'string', 'description' => 'Email subject'],
                                'date' => ['type' => 'string', 'description' => 'Email date'],
                                'cc' => ['type' => ['string', 'null'], 'description' => 'CC recipients'],
                                'reply_to' => ['type' => ['string', 'null'], 'description' => 'Reply-to address']
                            ],
                            'required' => ['from', 'to', 'subject', 'date', 'cc', 'reply_to'],
                            'additionalProperties' => false
                        ],
                        'body' => [
                            'type' => 'object',
                            'properties' => [
                                'plain_text' => ['type' => ['string', 'null'], 'description' => 'Plain text body'],
                                'html_as_text' => ['type' => ['string', 'null'], 'description' => 'HTML body converted to text']
                            ],
                            'required' => ['plain_text', 'html_as_text'],
                            'additionalProperties' => false
                        ]
                    ],
                    'required' => ['headers', 'body'],
                    'additionalProperties' => false
                ]
            ]
        ];
    }

    /**
     * Process the OpenAI response and create a ParsedEmail object
     * 
     * @param array $response OpenAI response
     * @return ParsedEmail Parsed email data
     * @throws \Exception If response cannot be processed
     */
    private function processResponse(array $response): ParsedEmail
    {
        $parsedContent = null;
        
        foreach ($response['output'] as $output) {
            if ($output['status'] === 'completed') {
                $text = $output['content'][0]['text'] ?? null;
                if ($text) {
                    $parsedContent = json_decode($text, true);
                    break;
                }
            }
        }

        if ($parsedContent === null) {
            throw new \Exception("Failed to parse email: No valid response from OpenAI");
        }

        $parsed = new ParsedEmail();
        
        // Extract headers (cc and replyTo can be null as per schema)
        $headers = $parsedContent['headers'] ?? [];
        $parsed->from = $headers['from'] ?? '';
        $parsed->to = $headers['to'] ?? '';
        $parsed->subject = $headers['subject'] ?? '';
        $parsed->date = $headers['date'] ?? '';
        $parsed->cc = $headers['cc'] ?? null;
        $parsed->replyTo = $headers['reply_to'] ?? null;

        // Extract body
        $body = $parsedContent['body'] ?? [];
        $parsed->plainText = $body['plain_text'] ?? '';
        $parsed->htmlAsText = $body['html_as_text'] ?? '';

        return $parsed;
    }
}

/**
 * Data class representing a parsed email
 */
class ParsedEmail
{
    /** @var string Sender email and name */
    public string $from = '';
    
    /** @var string Recipient(s) email and name */
    public string $to = '';
    
    /** @var string Email subject */
    public string $subject = '';
    
    /** @var string Email date */
    public string $date = '';
    
    /** @var string|null CC recipients */
    public ?string $cc = null;
    
    /** @var string|null Reply-to address (maps from 'reply_to' in API schema) */
    public ?string $replyTo = null;
    
    /** @var string Plain text body */
    public string $plainText = '';
    
    /** @var string HTML body converted to text */
    public string $htmlAsText = '';

    /**
     * Get the email body, preferring plain text over HTML
     * 
     * @return string Email body content
     */
    public function getBody(): string
    {
        if (!empty($this->plainText)) {
            return $this->plainText;
        }
        return $this->htmlAsText;
    }

    /**
     * Get combined body (plain text + HTML if different)
     * 
     * @return string Combined body content
     */
    public function getCombinedBody(): string
    {
        $combined = '';
        
        if (!empty($this->plainText)) {
            $combined = $this->plainText;
        }
        
        if (!empty($this->htmlAsText) && $this->htmlAsText !== $this->plainText) {
            if (!empty($combined)) {
                $combined .= "\n\n";
            }
            $combined .= $this->htmlAsText;
        }
        
        return trim($combined);
    }
}

<?php

require_once __DIR__ . '/OpenAiPrompt.php';

/**
 * Prompt for extracting text content from raw email (EML) using OpenAI.
 *
 * This replaces the PHP Laminas Mail library-based parsing with AI-powered extraction.
 * The AI can handle malformed emails, encoding issues, and complex MIME structures
 * more gracefully than traditional parsing libraries.
 */
class EmailBodyExtractionPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'email-body-extraction';
    }

    public function getPromptText(): string {
        return <<<'PROMPT'
You are an expert email parser. Your task is to extract the readable text content from a raw email (EML format).

Instructions:
1. Parse the raw email content including MIME headers and body
2. Extract the human-readable text from the email body
3. Handle multipart MIME emails (text/plain and text/html parts)
4. Decode base64 and quoted-printable encoded content
5. Convert HTML content to plain text if no text/plain part exists
6. Handle various character encodings (UTF-8, ISO-8859-1, Windows-1252, etc.)
7. Ignore email headers, attachment binary data, and email signatures/footers that are purely technical
8. Preserve the logical structure of the email (paragraphs, line breaks)

Important:
- Do NOT include email headers (From, To, Subject, Date, etc.) in the output
- Do NOT include base64 encoded attachment data
- Do NOT include MIME boundary markers or Content-Type headers
- If the email contains both plain text and HTML versions, prefer the plain text version
- If only HTML exists, extract the text content from the HTML
- Preserve Norwegian and other special characters correctly

Return ONLY the extracted text content, nothing else.
PROMPT;
    }

    public function getModel(String $input_from_email): string {
        // Use gpt-4o-mini for cost efficiency - it's sufficient for parsing tasks
        // The email content is typically structured, making it suitable for smaller models
        $inputLength = strlen($input_from_email);

        // For very large emails, still use the mini model but be aware of token limits
        // gpt-4o-mini has 128k context window which should handle most emails
        return 'gpt-4o-mini-2024-07-18';
    }

    public function getInput(String $input_from_email): array {
        return [
            ['role' => 'system', 'content' => $this->getPromptText()],
            ['role' => 'user', 'content' => "Extract the text content from this raw email:\n\n" . $input_from_email]
        ];
    }

    /**
     * Clean up the extracted text output
     */
    public function filterOutput($output): string {
        // Normalize line endings
        $output = str_replace("\r\n", "\n", $output);
        $output = str_replace("\r", "\n", $output);

        // Remove excessive whitespace
        $output = preg_replace('/\n{3,}/', "\n\n", $output);

        return trim($output);
    }
}

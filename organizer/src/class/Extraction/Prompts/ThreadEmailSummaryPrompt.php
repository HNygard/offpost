<?php

class ThreadEmailSummaryPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'thread-email-summary';
    }

    public function getPromptText(): string {
        return 'Du er et system for å analysere e-poster på norsk.'
                . ' Oppgaven er å lage et kort sammendrag av e-posten som skal hjelpe en saksbehandler å forstå innholdet raskt.'
                . ' Sammendraget skal være på norsk og skal være kort og presist (maksimalt 2-3 setninger).'
                . ' Fokuser på hovedbudskapet, viktige datoer, og hva avsenderen ber om eller informerer om.'
                . ' Hvis e-posten inneholder en forespørsel, nevn hva som blir bedt om.'
                . ' Hvis e-posten inneholder et svar eller informasjon, nevn hva som blir kommunisert.'
                . ' Svar kun med sammendraget, ingen forklaringer eller formatering.';
    }

    public function getModel(String $input_from_email): string {
        // Use gpt-4o-mini for cost efficiency, good enough for summarization
        return 'gpt-4o-mini-2024-07-18';
    }

    public function getInput(String $input_from_email): array {
        // Limit the input to approximately 2000 characters to stay within reasonable token limits
        $length = 2000;
        if (mb_strlen($input_from_email, 'UTF-8') > $length) {
            $input_from_email = mb_substr($input_from_email, 0, $length, 'UTF-8') . '... [Tekst forkortet]';
        }

        return [
            ['role' => 'system', 'content' => $this->getPromptText()],
            ['role' => 'user', 'content' => $input_from_email]
        ];
    }

    public function getStructuredOutput() { 
        // No structured output needed, we want plain text summary
        return null;
    }

    public function filterOutput($output): string {
        // Trim whitespace and ensure the summary isn't too long
        $output = trim($output);
        
        // If the output is longer than 200 characters, truncate it at sentence boundary
        if (mb_strlen($output, 'UTF-8') > 200) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $output);
            $truncated = '';
            foreach ($sentences as $sentence) {
                if (mb_strlen($truncated . $sentence, 'UTF-8') <= 200) {
                    $truncated .= ($truncated ? ' ' : '') . $sentence;
                } else {
                    break;
                }
            }
            $output = $truncated ?: mb_substr($output, 0, 197, 'UTF-8') . '...';
        }
        
        return $output;
    }
}
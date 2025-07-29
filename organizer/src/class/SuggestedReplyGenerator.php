<?php

require_once __DIR__ . '/Extraction/ThreadEmailExtractionService.php';

/**
 * Class for generating suggested replies for threads
 * Extracts the logic from view-thread.php to make it testable
 */
class SuggestedReplyGenerator {
    
    private $extractionService;
    
    public function __construct($extractionService = null) {
        $this->extractionService = $extractionService ?: new ThreadEmailExtractionService();
    }
    
    /**
     * Generate a suggested reply for a thread
     * 
     * @param Thread $thread The thread object
     * @return string The suggested reply text
     */
    public function generateSuggestedReply($thread) {
        $suggestedReply = "Tidligere e-poster:\n\n";
        
        // Add previous emails summary
        if (isset($thread->emails)) {
            $emailCount = 0;
            foreach (array_reverse($thread->emails) as $email) {
                $emailCount++;
                if ($emailCount > 5) break; // Limit to last 5 emails
                
                $direction = ($email->email_type === 'IN') ? 'Mottatt' : 'Sendt';
                $suggestedReply .= "{$emailCount}. {$direction} den {$email->datetime_received}\n";
                if (isset($email->description) && $email->description) {
                    $suggestedReply .= "   Sammendrag: " . strip_tags($email->description) . "\n";
                }
                $suggestedReply .= "\n";
            }
        }
        
        // Add case number information from saksnummer extractions
        $caseNumberInfo = $this->getCaseNumberInfo($thread);
        if (!empty($caseNumberInfo)) {
            $suggestedReply .= $caseNumberInfo . "\n\n";
        }
        
        // Add copy request information
        $copyRequestInfo = $this->getCopyRequestInfo($thread);
        if (!empty($copyRequestInfo)) {
            $suggestedReply .= $copyRequestInfo . "\n\n";
        }
        
        $suggestedReply .= "--\n" . $thread->my_name;
        
        return $suggestedReply;
    }
    
    /**
     * Get case number information from saksnummer extractions
     * 
     * @param Thread $thread The thread object
     * @return string Case number information or empty string
     */
    private function getCaseNumberInfo($thread) {
        $caseNumbers = [];
        
        if (!isset($thread->emails)) {
            return '';
        }
        
        foreach ($thread->emails as $email) {
            $extractions = $this->extractionService->getExtractionsForEmail($email->id);
            
            foreach ($extractions as $extraction) {
                if ($extraction->prompt_service == 'openai' && 
                    $extraction->prompt_id == 'saksnummer' && 
                    !empty($extraction->extracted_text)) {
                    
                    $obj = json_decode($extraction->extracted_text);
                    if ($obj) {
                        foreach($obj as $case_number) {
                            $caseText = '';
                            if (!empty($case_number->document_number)) {
                                $caseText = "Dokumentnummer: " . $case_number->document_number;
                            }
                            elseif (!empty($case_number->case_number)) {
                                $caseText = "Saksnummer: " . $case_number->case_number;
                            }
                            if (!empty($case_number->entity_name)) {
                                $caseText .= " (" . $case_number->entity_name . ")";
                            }
                            if (!empty($caseText)) {
                                $caseNumbers[] = $caseText;
                            }
                        }
                    }
                }
            }
        }
        
        if (!empty($caseNumbers)) {
            return "Saksnummer informasjon:\n" . implode("\n", array_unique($caseNumbers));
        }
        
        return '';
    }
    
    /**
     * Get copy request information from copy-asking-for extractions
     * 
     * @param Thread $thread The thread object
     * @return string Copy request information or empty string
     */
    private function getCopyRequestInfo($thread) {
        if (!isset($thread->emails)) {
            return '';
        }
        
        foreach ($thread->emails as $email) {
            $extractions = $this->extractionService->getExtractionsForEmail($email->id);
            
            foreach ($extractions as $extraction) {
                if ($extraction->prompt_service == 'openai' && 
                    $extraction->prompt_id == 'copy-asking-for' && 
                    !empty($extraction->extracted_text)) {
                    
                    $obj = json_decode($extraction->extracted_text);
                    if ($obj && isset($obj->is_requesting_copy) && $obj->is_requesting_copy) {
                        return "Merk: Avsenderen ber om en kopi av e-posten.";
                    }
                }
            }
        }
        
        return '';
    }
}
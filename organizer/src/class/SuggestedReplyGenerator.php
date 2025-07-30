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
        
        // Get all extractions once for efficiency
        $allExtractions = $this->getAllExtractions($thread);
        
        // Track unique case numbers to avoid duplicates
        $uniqueCaseNumbers = [];
        
        // Add previous emails summary with their specific extractions
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
                
                // Add extractions specific to this email
                if (isset($allExtractions[$email->id])) {
                    $emailExtractions = $allExtractions[$email->id];
                    
                    // Add case numbers and document numbers for this email
                    $emailCaseInfo = $this->getCaseNumberInfoForEmail($emailExtractions, $uniqueCaseNumbers);
                    if (!empty($emailCaseInfo)) {
                        $suggestedReply .= $emailCaseInfo;
                    }
                    
                    // Add copy request notification for this email
                    $emailCopyInfo = $this->getCopyRequestInfoForEmail($emailExtractions);
                    if (!empty($emailCopyInfo)) {
                        $suggestedReply .= "   " . $emailCopyInfo . "\n";
                    }
                }
                
                $suggestedReply .= "\n";
            }
        }
        
        // Add any remaining unique case numbers that weren't shown per email
        $remainingCaseInfo = $this->getRemainingCaseNumberInfo($allExtractions, $uniqueCaseNumbers);
        if (!empty($remainingCaseInfo)) {
            $suggestedReply .= $remainingCaseInfo . "\n\n";
        }
        
        $suggestedReply .= "--\n" . $thread->my_name;
        
        return $suggestedReply;
    }
    
    /**
     * Get all extractions for all emails in the thread
     * 
     * @param Thread $thread The thread object
     * @return array All extractions grouped by email
     */
    private function getAllExtractions($thread) {
        $allExtractions = [];
        
        if (!isset($thread->emails)) {
            return $allExtractions;
        }
        
        foreach ($thread->emails as $email) {
            $extractions = $this->extractionService->getExtractionsForEmail($email->id);
            $allExtractions[$email->id] = $extractions;
        }
        
        return $allExtractions;
    }
    
    /**
     * Get case number information for a specific email
     * 
     * @param array $emailExtractions Extractions for a specific email
     * @param array $uniqueCaseNumbers Reference to array tracking unique case numbers
     * @return string Case number information for this email or empty string
     */
    private function getCaseNumberInfoForEmail($emailExtractions, &$uniqueCaseNumbers) {
        $caseInfo = '';
        
        foreach ($emailExtractions as $extraction) {
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
                        
                        // Only add if we haven't seen this case number before
                        if (!empty($caseText) && !in_array($caseText, $uniqueCaseNumbers)) {
                            $uniqueCaseNumbers[] = $caseText;
                            $caseInfo .= "   " . $caseText . "\n";
                        }
                    }
                }
            }
        }
        
        return $caseInfo;
    }
    
    /**
     * Get copy request information for a specific email
     * 
     * @param array $emailExtractions Extractions for a specific email
     * @return string Copy request information for this email or empty string
     */
    private function getCopyRequestInfoForEmail($emailExtractions) {
        foreach ($emailExtractions as $extraction) {
            if ($extraction->prompt_service == 'openai' && 
                $extraction->prompt_id == 'copy-asking-for' && 
                !empty($extraction->extracted_text)) {
                
                $obj = json_decode($extraction->extracted_text);
                if ($obj && isset($obj->is_requesting_copy) && $obj->is_requesting_copy) {
                    return "Merk: Avsenderen ber om en kopi av e-posten.";
                }
            }
        }
        
        return '';
    }
    
    /**
     * Get any remaining case number information not yet shown
     * 
     * @param array $allExtractions All extractions grouped by email
     * @param array $uniqueCaseNumbers Array of case numbers already shown
     * @return string Remaining case number information or empty string
     */
    private function getRemainingCaseNumberInfo($allExtractions, $uniqueCaseNumbers) {
        $remainingCaseNumbers = [];
        
        foreach ($allExtractions as $emailId => $extractions) {
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
                            
                            // Only add if we haven't seen this case number before
                            if (!empty($caseText) && !in_array($caseText, $uniqueCaseNumbers)) {
                                $remainingCaseNumbers[] = $caseText;
                            }
                        }
                    }
                }
            }
        }
        
        if (!empty($remainingCaseNumbers)) {
            return "Saksnummer:\n" . implode("\n", array_unique($remainingCaseNumbers));
        }
        
        return '';
    }
    
    /**
     * Get case number information from extractions
     * 
     * @param array $allExtractions All extractions grouped by email
     * @return string Case number information or empty string
     */
    private function getCaseNumberInfoFromExtractions($allExtractions) {
        $caseNumbers = [];
        
        foreach ($allExtractions as $emailId => $extractions) {
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
            return "Saksnummer:\n" . implode("\n", array_unique($caseNumbers));
        }
        
        return '';
    }
    
    /**
     * Get copy request information from extractions
     * 
     * @param array $allExtractions All extractions grouped by email
     * @return string Copy request information or empty string
     */
    private function getCopyRequestInfoFromExtractions($allExtractions) {
        foreach ($allExtractions as $emailId => $extractions) {
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
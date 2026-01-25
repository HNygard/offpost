/**
 * Extraction Dialog Handler
 * Displays extraction information in a modal dialog
 */

(function() {
    'use strict';

    // Track if escape key listener is registered
    let escapeListenerRegistered = false;

    // Create and inject modal HTML when DOM is ready
    function createModal() {
        const modalHTML = `
            <div id="extraction-modal" class="extraction-modal" style="display: none;">
                <div class="extraction-modal-overlay"></div>
                <div class="extraction-modal-content">
                    <div class="extraction-modal-header">
                        <h2>Extraction Details</h2>
                        <button class="extraction-modal-close">&times;</button>
                    </div>
                    <div class="extraction-modal-body">
                        <div class="extraction-loading">Loading extraction data...</div>
                        <div class="extraction-error" style="display: none;"></div>
                        <div class="extraction-data" style="display: none;">
                            <div class="extraction-metadata">
                                <h3>Metadata</h3>
                                <table class="extraction-metadata-table">
                                    <tr>
                                        <th>Extraction ID:</th>
                                        <td id="extraction-id"></td>
                                    </tr>
                                    <tr>
                                        <th>Prompt Service:</th>
                                        <td id="extraction-service"></td>
                                    </tr>
                                    <tr>
                                        <th>Prompt ID:</th>
                                        <td id="extraction-prompt-id"></td>
                                    </tr>
                                    <tr>
                                        <th>Created At:</th>
                                        <td id="extraction-created"></td>
                                    </tr>
                                    <tr>
                                        <th>Updated At:</th>
                                        <td id="extraction-updated"></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="extraction-result">
                                <h3>Extracted Result</h3>
                                <div id="extraction-text" class="extraction-text-content"></div>
                            </div>
                            <div class="extraction-prompt" style="display: none;">
                                <h3>Prompt Text</h3>
                                <div id="extraction-prompt-text" class="extraction-prompt-content"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Add close handlers
        const modal = document.getElementById('extraction-modal');
        const closeBtn = modal.querySelector('.extraction-modal-close');
        const overlay = modal.querySelector('.extraction-modal-overlay');
        
        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', closeModal);
        
        // Close on Escape key (only register once)
        if (!escapeListenerRegistered) {
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'block') {
                    closeModal();
                }
            });
            escapeListenerRegistered = true;
        }
    }

    function openModal() {
        const modal = document.getElementById('extraction-modal');
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const modal = document.getElementById('extraction-modal');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function showLoading() {
        const modal = document.getElementById('extraction-modal');
        modal.querySelector('.extraction-loading').style.display = 'block';
        modal.querySelector('.extraction-error').style.display = 'none';
        modal.querySelector('.extraction-data').style.display = 'none';
    }

    function showError(message) {
        const modal = document.getElementById('extraction-modal');
        const errorDiv = modal.querySelector('.extraction-error');
        errorDiv.textContent = 'Error: ' + message;
        errorDiv.style.display = 'block';
        modal.querySelector('.extraction-loading').style.display = 'none';
        modal.querySelector('.extraction-data').style.display = 'none';
    }

    function showData(extraction) {
        const modal = document.getElementById('extraction-modal');
        
        // Hide loading and error
        modal.querySelector('.extraction-loading').style.display = 'none';
        modal.querySelector('.extraction-error').style.display = 'none';
        modal.querySelector('.extraction-data').style.display = 'block';
        
        // Populate metadata
        document.getElementById('extraction-id').textContent = extraction.extraction_id || 'N/A';
        document.getElementById('extraction-service').textContent = extraction.prompt_service || 'N/A';
        document.getElementById('extraction-prompt-id').textContent = extraction.prompt_id || 'N/A';
        document.getElementById('extraction-created').textContent = extraction.created_at || 'N/A';
        document.getElementById('extraction-updated').textContent = extraction.updated_at || 'N/A';
        
        // Show error message if present
        const textDiv = document.getElementById('extraction-text');
        if (extraction.error_message) {
            textDiv.innerHTML = '<div style="color: #721c24; background-color: #f8d7da; padding: 10px; border-radius: 4px;">' +
                '<strong>Error:</strong> ' + escapeHtml(extraction.error_message) + '</div>';
        } else if (extraction.extracted_text) {
            // Try to format JSON nicely, otherwise show as text
            try {
                const parsed = JSON.parse(extraction.extracted_text);
                textDiv.innerHTML = '<pre style="background-color: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto;">' +
                    escapeHtml(JSON.stringify(parsed, null, 2)) + '</pre>';
            } catch (e) {
                textDiv.innerHTML = '<pre style="background-color: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap;">' +
                    escapeHtml(extraction.extracted_text) + '</pre>';
            }
        } else {
            textDiv.innerHTML = '<em>No extracted text available</em>';
        }
        
        // Show prompt text if available
        const promptSection = modal.querySelector('.extraction-prompt');
        const promptTextDiv = document.getElementById('extraction-prompt-text');
        if (extraction.prompt_text) {
            promptSection.style.display = 'block';
            promptTextDiv.innerHTML = '<pre style="background-color: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap;">' +
                escapeHtml(extraction.prompt_text) + '</pre>';
        } else {
            promptSection.style.display = 'none';
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function fetchAndShowExtraction(extractionId) {
        showLoading();
        openModal();
        
        try {
            // Use URLSearchParams for safer URL construction
            const params = new URLSearchParams({ extraction_id: extractionId });
            const response = await fetch('/api/thread_email_extraction?' + params.toString());
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: 'Unknown error' }));
                throw new Error(errorData.error || 'Failed to fetch extraction');
            }
            
            const extraction = await response.json();
            showData(extraction);
        } catch (error) {
            showError(error.message);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createModal);
    } else {
        createModal();
    }

    // Export to global scope
    window.ExtractionDialog = {
        show: fetchAndShowExtraction,
        close: closeModal
    };
})();

/**
 * Content Dialog Handler
 * Generic modal dialog that shows the content of a <template> already
 * present on the page (server-rendered, already escaped) - used to replace
 * <details> "Show ..." toggles across the analysis UI.
 *
 * Trigger markup:
 *   <a href="#" class="content-dialog-link" data-dialog-title="…" data-dialog-template="<id>">Show state</a>
 *   <template id="<id>">…escaped content…</template>
 *
 * Reuses the extraction-modal* CSS classes from extractionDialog.css so it
 * looks and behaves exactly like ExtractionDialog.
 */
(function() {
    'use strict';

    // Track if document-level listeners are registered
    let listenersRegistered = false;

    function createModal() {
        const modalHTML = `
            <div id="content-modal" class="extraction-modal" style="display: none;">
                <div class="extraction-modal-overlay"></div>
                <div class="extraction-modal-content">
                    <div class="extraction-modal-header">
                        <h2 id="content-modal-title"></h2>
                        <button class="extraction-modal-close">&times;</button>
                    </div>
                    <div class="extraction-modal-body" id="content-modal-body"></div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = document.getElementById('content-modal');
        const closeBtn = modal.querySelector('.extraction-modal-close');
        const overlay = modal.querySelector('.extraction-modal-overlay');

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', closeModal);

        if (!listenersRegistered) {
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'block') {
                    closeModal();
                }
            });

            // Event delegation so any number of triggers work, including
            // ones added to the page after load.
            document.addEventListener('click', function(e) {
                const trigger = e.target.closest('.content-dialog-link');
                if (trigger) {
                    e.preventDefault();
                    showFromTrigger(trigger);
                }
            });

            listenersRegistered = true;
        }
    }

    function showFromTrigger(trigger) {
        const templateId = trigger.getAttribute('data-dialog-template');
        const title = trigger.getAttribute('data-dialog-title') || '';
        show(templateId, title);
    }

    function show(templateId, title) {
        const template = document.getElementById(templateId);
        if (!template) {
            return;
        }

        const body = document.getElementById('content-modal-body');
        body.textContent = '';
        body.appendChild(template.content.cloneNode(true));

        document.getElementById('content-modal-title').textContent = title;

        openModal();
    }

    function openModal() {
        const modal = document.getElementById('content-modal');
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const modal = document.getElementById('content-modal');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createModal);
    } else {
        createModal();
    }

    // Export to global scope
    window.ContentDialog = {
        show: show,
        close: closeModal
    };
})();

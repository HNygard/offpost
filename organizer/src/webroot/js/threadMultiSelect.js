/**
 * Thread Multi-Select Functionality
 * Handles the selection of multiple threads and bulk actions
 */

document.addEventListener('DOMContentLoaded', () => {
    // Get elements
    const selectAllCheckbox = document.getElementById('select-all-threads');
    const threadCheckboxes = document.querySelectorAll('.thread-checkbox');
    const bulkActionSelect = document.getElementById('bulk-action');
    const bulkActionButton = document.getElementById('bulk-action-button');
    const selectedCountSpan = document.getElementById('selected-count');
    
    // Initialize state
    updateSelectedCount();
    updateBulkActionButton();
    
    // Add event listeners
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', toggleAllThreads);
    }
    
    threadCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            updateSelectedCount();
            updateBulkActionButton();
            updateSelectAllCheckbox();
        });
    });
    
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', updateBulkActionButton);
    }
    
    // Listen for the tableFiltered event from tableSearch.js
    document.addEventListener('tableFiltered', () => {
        updateSelectAllCheckbox();
    });
    
    /**
     * Toggle all thread checkboxes based on the "Select All" checkbox
     * If a search is active, only toggle visible checkboxes
     */
    function toggleAllThreads() {
        const isChecked = selectAllCheckbox.checked;
        
        threadCheckboxes.forEach(checkbox => {
            // Get the parent row of the checkbox
            const row = checkbox.closest('tr');
            
            // Only toggle checkboxes of visible rows when a search is active
            if (!row || row.style.display !== 'none') {
                checkbox.checked = isChecked;
            }
        });
        
        updateSelectedCount();
        updateBulkActionButton();
    }
    
    /**
     * Update the "Select All" checkbox based on individual thread selections
     * Only considers visible checkboxes when a search is active
     */
    function updateSelectAllCheckbox() {
        if (!selectAllCheckbox) return;
        
        // Get visible checkboxes (those whose parent row is not hidden)
        const visibleCheckboxes = Array.from(threadCheckboxes).filter(cb => {
            const row = cb.closest('tr');
            return !row || row.style.display !== 'none';
        });
        
        const totalVisibleCheckboxes = visibleCheckboxes.length;
        const checkedVisibleCheckboxes = visibleCheckboxes.filter(cb => cb.checked).length;
        
        if (checkedVisibleCheckboxes === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedVisibleCheckboxes === totalVisibleCheckboxes) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
    
    /**
     * Update the selected count display
     */
    function updateSelectedCount() {
        if (!selectedCountSpan) return;
        
        const selectedCount = Array.from(threadCheckboxes).filter(cb => cb.checked).length;
        selectedCountSpan.textContent = selectedCount;
        
        // Show/hide the count based on selection
        const countContainer = document.getElementById('selected-count-container');
        if (countContainer) {
            countContainer.style.display = selectedCount > 0 ? 'inline-block' : 'none';
        }
    }
    
    /**
     * Update the bulk action button state (enabled/disabled)
     */
    function updateBulkActionButton() {
        if (!bulkActionButton || !bulkActionSelect) return;
        
        const selectedCount = Array.from(threadCheckboxes).filter(cb => cb.checked).length;
        const actionSelected = bulkActionSelect.value !== '';
        
        bulkActionButton.disabled = selectedCount === 0 || !actionSelected;
    }
});

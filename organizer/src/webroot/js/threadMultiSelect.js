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
    
    /**
     * Toggle all thread checkboxes based on the "Select All" checkbox
     */
    function toggleAllThreads() {
        const isChecked = selectAllCheckbox.checked;
        
        threadCheckboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        
        updateSelectedCount();
        updateBulkActionButton();
    }
    
    /**
     * Update the "Select All" checkbox based on individual thread selections
     */
    function updateSelectAllCheckbox() {
        if (!selectAllCheckbox) return;
        
        const totalCheckboxes = threadCheckboxes.length;
        const checkedCheckboxes = Array.from(threadCheckboxes).filter(cb => cb.checked).length;
        
        if (checkedCheckboxes === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedCheckboxes === totalCheckboxes) {
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

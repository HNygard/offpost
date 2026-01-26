/**
 * Table Sorting Functionality
 * Handles sorting of the thread list table by last email timestamp
 */

document.addEventListener('DOMContentLoaded', () => {
    const lastEmailHeader = document.getElementById('last-email-header');
    const sortIndicator = document.getElementById('sort-indicator');
    
    if (!lastEmailHeader || !sortIndicator) {
        return;
    }
    
    let sortDirection = 'desc'; // Start with descending (most recent first)
    
    // Initialize sort indicator
    updateSortIndicator();
    
    // Add click event listener to the header
    lastEmailHeader.addEventListener('click', () => {
        sortTable();
    });
    
    /**
     * Sort the table by last email timestamp
     */
    function sortTable() {
        const table = lastEmailHeader.closest('table');
        if (!table) return;
        
        // Get all data rows (skip the header row)
        const tbody = table.querySelector('tbody') || table;
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => {
            // Skip the header row (it has input fields)
            return !row.querySelector('input[type="text"]');
        });
        
        // Toggle sort direction
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
        
        // Sort rows by the data-last-email-timestamp attribute
        rows.sort((a, b) => {
            const aTimestamp = parseInt(a.getAttribute('data-last-email-timestamp') || '0');
            const bTimestamp = parseInt(b.getAttribute('data-last-email-timestamp') || '0');
            
            if (sortDirection === 'asc') {
                return aTimestamp - bTimestamp;
            } else {
                return bTimestamp - aTimestamp;
            }
        });
        
        // Find the header row
        const headerRow = Array.from(tbody.querySelectorAll('tr')).find(row => {
            return row.querySelector('input[type="text"]');
        });
        
        // Re-append rows in sorted order
        if (headerRow) {
            // Keep the header row at the top
            tbody.appendChild(headerRow);
        }
        
        rows.forEach(row => {
            tbody.appendChild(row);
        });
        
        // Update the sort indicator
        updateSortIndicator();
    }
    
    /**
     * Update the sort direction indicator
     */
    function updateSortIndicator() {
        if (sortDirection === 'asc') {
            sortIndicator.textContent = '▲';
            sortIndicator.title = 'Sorted ascending (oldest first)';
        } else {
            sortIndicator.textContent = '▼';
            sortIndicator.title = 'Sorted descending (most recent first)';
        }
    }
});

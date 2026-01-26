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
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        
        // Separate header row from data rows
        const headerRow = allRows.find(row => {
            // Check if this is a header row (has input fields or is the first row with th elements)
            return row.querySelector('input[type="text"]') || row.querySelector('th');
        });
        
        // Get only data rows (excluding header)
        const rows = allRows.filter(row => row !== headerRow);
        
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
        
        // Remove all rows from the table
        allRows.forEach(row => row.remove());
        
        // Re-append header row first, then data rows
        if (headerRow) {
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

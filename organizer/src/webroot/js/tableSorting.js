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
        
        // Get the table body or fall back to the table element itself
        // Note: Some HTML tables don't have an explicit <tbody> element,
        // in which case rows are direct children of <table>
        const tbody = table.querySelector('tbody') || table;
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        
        // Separate header row from data rows
        // Priority: Check for explicit ID first, then fall back to heuristics
        // (input fields or th elements) for compatibility with different table structures
        const headerRow = allRows.find(row => {
            return row.id === 'thread-list-header' || 
                   row.querySelector('input[type="text"]') || 
                   row.querySelector('th');
        });
        
        // Get only data rows (excluding header)
        const rows = allRows.filter(row => row !== headerRow);
        
        // Toggle sort direction
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
        
        // Separate visible and hidden rows to preserve filter state
        const visibleRows = rows.filter(row => row.style.display !== 'none');
        const hiddenRows = rows.filter(row => row.style.display === 'none');
        
        // Sort only the visible rows by the data-last-email-timestamp attribute
        visibleRows.sort((a, b) => {
            const aTimestamp = parseInt(a.getAttribute('data-last-email-timestamp') || '0', 10);
            const bTimestamp = parseInt(b.getAttribute('data-last-email-timestamp') || '0', 10);
            
            if (sortDirection === 'asc') {
                return aTimestamp - bTimestamp;
            } else {
                return bTimestamp - aTimestamp;
            }
        });
        
        // Remove all rows from the table
        allRows.forEach(row => row.remove());
        
        // Re-append header row first, then visible rows, then hidden rows
        if (headerRow) {
            tbody.appendChild(headerRow);
        }
        
        visibleRows.forEach(row => {
            tbody.appendChild(row);
        });
        
        hiddenRows.forEach(row => {
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

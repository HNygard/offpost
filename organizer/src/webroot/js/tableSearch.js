document.addEventListener('DOMContentLoaded', function() {
    // Get references to the search inputs
    const entitySearchInput = document.getElementById('entity-search');
    const titleSearchInput = document.getElementById('title-search');
    const statusSearchInput = document.getElementById('status-search');
    const labelSearchInput = document.getElementById('label-search');
    
    // Add event listeners to the search inputs
    entitySearchInput.addEventListener('input', filterTable);
    titleSearchInput.addEventListener('input', filterTable);
    statusSearchInput.addEventListener('input', filterTable);
    labelSearchInput.addEventListener('input', filterTable);
    
    function filterTable() {
        // Get the search values
        const entitySearchValue = entitySearchInput.value.toLowerCase();
        const titleSearchValue = titleSearchInput.value.toLowerCase();
        const statusSearchValue = statusSearchInput.value.toLowerCase();
        const labelSearchValue = labelSearchInput.value.toLowerCase();
        
        // Get all table rows except the header row
        const tableRows = document.querySelectorAll('table tr:not(:first-child)');
        
        // Loop through all table rows
        tableRows.forEach(function(row) {
            // Get the entity, title, status, and label cells (2nd, 3rd, 4th, and 5th columns)
            const entityCell = row.querySelector('td:nth-child(2)');
            const titleCell = row.querySelector('td:nth-child(3)');
            const statusCell = row.querySelector('td:nth-child(4)');
            const labelCell = row.querySelector('td:nth-child(5)');
            
            if (!entityCell || !titleCell || !statusCell || !labelCell) return;
            
            // Get the text content of the cells
            const entityText = entityCell.textContent.toLowerCase();
            const titleText = titleCell.textContent.toLowerCase();
            const statusText = statusCell.textContent.toLowerCase();
            const labelText = labelCell.textContent.toLowerCase();
            
            // Check if the search values match the cell content
            const entityMatch = entitySearchValue === '' || entityText.includes(entitySearchValue);
            const titleMatch = titleSearchValue === '' || titleText.includes(titleSearchValue);
            const statusMatch = statusSearchValue === '' || statusText.includes(statusSearchValue);
            const labelMatch = labelSearchValue === '' || labelText.includes(labelSearchValue);
            
            // Show or hide the row based on the search matches
            if (entityMatch && titleMatch && statusMatch && labelMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Dispatch a custom event to notify that the table has been filtered
        document.dispatchEvent(new CustomEvent('tableFiltered'));
    }
});

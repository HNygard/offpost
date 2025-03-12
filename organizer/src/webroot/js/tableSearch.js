document.addEventListener('DOMContentLoaded', function() {
    // Get references to the search inputs
    const entitySearchInput = document.getElementById('entity-search');
    const titleSearchInput = document.getElementById('title-search');
    
    // Add event listeners to the search inputs
    entitySearchInput.addEventListener('input', filterTable);
    titleSearchInput.addEventListener('input', filterTable);
    
    function filterTable() {
        // Get the search values
        const entitySearchValue = entitySearchInput.value.toLowerCase();
        const titleSearchValue = titleSearchInput.value.toLowerCase();
        
        // Get all table rows except the header row
        const tableRows = document.querySelectorAll('table tr:not(:first-child)');
        
        // Loop through all table rows
        tableRows.forEach(function(row) {
            // Get the entity and title cells (2nd and 3rd columns)
            const entityCell = row.querySelector('td:nth-child(2)');
            const titleCell = row.querySelector('td:nth-child(3)');
            
            if (!entityCell || !titleCell) return;
            
            // Get the text content of the cells
            const entityText = entityCell.textContent.toLowerCase();
            const titleText = titleCell.textContent.toLowerCase();
            
            // Check if the search values match the cell content
            const entityMatch = entitySearchValue === '' || entityText.includes(entitySearchValue);
            const titleMatch = titleSearchValue === '' || titleText.includes(titleSearchValue);
            
            // Show or hide the row based on the search matches
            if (entityMatch && titleMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
});

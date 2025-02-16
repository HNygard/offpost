// Function to collect all unique labels and their counts from threads
function summarizeLabels() {
    const labelCounts = {};
    const rows = document.querySelectorAll('table tr');
    
    // Skip header row
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const labelElements = row.querySelectorAll('.label a');
        
        labelElements.forEach(label => {
            const labelText = label.textContent;
            labelCounts[labelText] = (labelCounts[labelText] || 0) + 1;
        });
    }
    
    return labelCounts;
}

// Function to display label summary
function displayLabelSummary() {
    const labelCounts = summarizeLabels();
    const summaryDiv = document.getElementById('label-summary');
    if (!summaryDiv) return;
    
    let html = '<h3>Labels:</h3>';
    for (const [label, count] of Object.entries(labelCounts)) {
        html += `<span class="label" onclick="filterByLabel('${label}')">${label} (${count})</span> `;
    }
    
    summaryDiv.innerHTML = html;
}

// Function to filter threads by label
function filterByLabel(label) {
    const rows = document.querySelectorAll('table tr');
    
    // Skip header row
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const labelElements = row.querySelectorAll('.label a');
        let matches = false;
        
        // Check if any label in the row matches the filter
        labelElements.forEach(labelEl => {
            if (labelEl.textContent === label) {
                matches = true;
            }
        });
        
        // Special handling for system labels
        switch (label) {
            case 'sent':
                matches = row.querySelector('.label_ok a[href="?label_filter=sent"]') !== null;
                break;
            case 'not_sent':
                matches = row.querySelector('.label_warn a[href="?label_filter=not_sent"]') !== null;
                break;
            case 'archived':
                matches = row.querySelector('.label_ok a[href="?label_filter=archived"]') !== null;
                break;
            case 'not_archived':
                matches = row.querySelector('.label_warn a[href="?label_filter=not_archived"]') !== null;
                break;
        }
        
        row.style.display = matches ? '' : 'none';
    }
    
    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('label_filter', label);
    window.history.pushState({}, '', url);
    
    // Update filter indicator
    const filterIndicator = document.getElementById('current-filter');
    if (filterIndicator) {
        filterIndicator.innerHTML = `Filtered on label: ${label} <button onclick="clearFilter()">Clear filter</button>`;
    }
}

// Function to clear the filter
function clearFilter() {
    const rows = document.querySelectorAll('table tr');
    rows.forEach(row => row.style.display = '');
    
    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.delete('label_filter');
    window.history.pushState({}, '', url);
    
    // Clear filter indicator
    const filterIndicator = document.getElementById('current-filter');
    if (filterIndicator) {
        filterIndicator.innerHTML = '';
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    displayLabelSummary();
    
    // Convert existing label links to use JavaScript filtering
    document.querySelectorAll('.label a').forEach(link => {
        const label = link.textContent;
        link.href = 'javascript:void(0)';
        link.onclick = () => filterByLabel(label);
    });
    
    // Apply filter from URL if present
    const urlParams = new URLSearchParams(window.location.search);
    const filterLabel = urlParams.get('label_filter');
    if (filterLabel) {
        filterByLabel(filterLabel);
    }
});

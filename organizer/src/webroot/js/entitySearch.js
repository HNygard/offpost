/**
 * Entity Search and Selection Functionality
 * Handles searching for entities and selecting them for thread creation
 */

document.addEventListener('DOMContentLoaded', () => {
    // Get elements
    const entitySearchInput = document.getElementById('entity-search');
    const entityList = document.getElementById('entity-list');
    const selectedEntitiesList = document.getElementById('selected-entities-list');
    const selectedEntitiesInput = document.getElementById('selected-entities-input');
    const selectAllMunicipalitiesBtn = document.getElementById('select-all-municipalities');
    const selectedCountSpan = document.getElementById('selected-entities-count');
    
    // Store all entities and selected entities
    let allEntities = [];
    let selectedEntities = [];
    
    // Initialize
    if (window.entityData) {
        allEntities = window.entityData;
        renderEntityList();
        updateSelectedCount();
    }
    
    // Add event listeners
    if (entitySearchInput) {
        entitySearchInput.addEventListener('input', renderEntityList);
    }
    
    if (selectAllMunicipalitiesBtn) {
        selectAllMunicipalitiesBtn.addEventListener('click', selectAllMunicipalities);
    }
    
    /**
     * Render the entity list based on search input
     */
    function renderEntityList() {
        if (!entityList) return;
        
        const searchValue = entitySearchInput ? entitySearchInput.value.toLowerCase() : '';
        
        // Clear the current list
        entityList.innerHTML = '';
        
        // Filter entities based on search value
        const filteredEntities = allEntities.filter(entity => {
            // Skip entities that are already selected
            if (selectedEntities.some(selected => selected.entity_id === entity.entity_id)) {
                return false;
            }
            
            // Skip entities that have existed_to_and_including date
            if (entity.entity_existed_to_and_including) {
                return false;
            }
            
            // Filter by search value
            return entity.name.toLowerCase().includes(searchValue);
        });
        
        // Limit to first 10 results for performance
        const limitedEntities = filteredEntities.slice(0, 10);
        
        // Create list items for each entity
        limitedEntities.forEach(entity => {
            const listItem = document.createElement('li');
            listItem.className = 'entity-item';
            listItem.textContent = entity.name;
            listItem.dataset.entityId = entity.entity_id;
            
            // Add click event to select the entity
            listItem.addEventListener('click', () => {
                selectEntity(entity);
            });
            
            entityList.appendChild(listItem);
        });
        
        // Show/hide the list based on search value and results
        entityList.style.display = searchValue && limitedEntities.length > 0 ? 'block' : 'none';
    }
    
    /**
     * Select an entity and add it to the selected list
     */
    function selectEntity(entity) {
        // Add to selected entities if not already selected
        if (!selectedEntities.some(selected => selected.entity_id === entity.entity_id)) {
            selectedEntities.push(entity);
            
            // Update the hidden input with selected entity IDs
            updateSelectedEntitiesInput();
            
            // Render the selected entities list
            renderSelectedEntities();
            
            // Clear the search input
            if (entitySearchInput) {
                entitySearchInput.value = '';
            }
            
            // Update the entity list
            renderEntityList();
            
            // Update the selected count
            updateSelectedCount();
        }
    }
    
    /**
     * Remove an entity from the selected list
     */
    function removeEntity(entityId) {
        selectedEntities = selectedEntities.filter(entity => entity.entity_id !== entityId);
        
        // Update the hidden input with selected entity IDs
        updateSelectedEntitiesInput();
        
        // Render the selected entities list
        renderSelectedEntities();
        
        // Update the entity list
        renderEntityList();
        
        // Update the selected count
        updateSelectedCount();
    }
    
    /**
     * Render the selected entities list
     */
    function renderSelectedEntities() {
        if (!selectedEntitiesList) return;
        
        // Clear the current list
        selectedEntitiesList.innerHTML = '';
        
        // Create list items for each selected entity
        selectedEntities.forEach(entity => {
            const listItem = document.createElement('li');
            listItem.className = 'selected-entity-item';
            
            const entityName = document.createElement('span');
            entityName.textContent = entity.name;
            listItem.appendChild(entityName);
            
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'remove-entity-btn';
            removeButton.textContent = '×';
            removeButton.addEventListener('click', () => {
                removeEntity(entity.entity_id);
            });
            listItem.appendChild(removeButton);
            
            selectedEntitiesList.appendChild(listItem);
        });
    }
    
    /**
     * Update the hidden input with selected entity IDs
     */
    function updateSelectedEntitiesInput() {
        if (!selectedEntitiesInput) return;
        
        const entityIds = selectedEntities.map(entity => entity.entity_id);
        selectedEntitiesInput.value = JSON.stringify(entityIds);
    }
    
    /**
     * Select all municipality entities
     */
    function selectAllMunicipalities() {
        // Find all municipality entities that are not already selected and don't have an end date
        const municipalities = allEntities.filter(entity => 
            entity.type === 'municipality' && 
            !entity.entity_existed_to_and_including &&
            !selectedEntities.some(selected => selected.entity_id === entity.entity_id)
        );
        
        // Add each municipality to selected entities
        municipalities.forEach(entity => {
            selectedEntities.push(entity);
        });
        
        // Update the hidden input with selected entity IDs
        updateSelectedEntitiesInput();
        
        // Render the selected entities list
        renderSelectedEntities();
        
        // Update the entity list
        renderEntityList();
        
        // Update the selected count
        updateSelectedCount();
    }
    
    /**
     * Update the selected count display
     */
    function updateSelectedCount() {
        if (!selectedCountSpan) return;
        
        const selectedCount = selectedEntities.length;
        selectedCountSpan.textContent = selectedCount;
    }
});

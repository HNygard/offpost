<?php

// Script to read entities from entities_np5.json and add them to entities.json
// with the new ID format (orgNumber-name)

// Function to convert entity name to slug format
function slugify($text) {
    // Replace spaces with hyphens
    $text = str_replace(' ', '-', $text);
    // Convert to lowercase
    $text = strtolower($text);
    // Remove any non-alphanumeric characters except hyphens
    $text = preg_replace('/[^a-z0-9\-]/', '', $text);
    // Remove multiple consecutive hyphens
    $text = preg_replace('/-+/', '-', $text);
    // Remove leading and trailing hyphens
    $text = trim($text, '-');
    
    return $text;
}

// Read the source file
$sourceFile = __DIR__ . '/entities_np5.json';
$sourceData = json_decode(file_get_contents($sourceFile), true);

if ($sourceData === null) {
    die("Error: Could not parse entities_np5.json\n");
}

// Read the destination file
$destFile = __DIR__ . '/entities.json';
$destData = json_decode(file_get_contents($destFile), true);

if ($destData === null) {
    die("Error: Could not parse entities.json\n");
}

// Create a mapping from old ID to new ID
$idMapping = [];

// Process each entity from the source file
$addedCount = 0;
$skippedCount = 0;

foreach ($sourceData as $entity) {
    // Skip entities without an org number
    if (empty($entity['orgNumber'])) {
        echo "Skipping entity without org number: {$entity['name']}\n";
        $skippedCount++;
        continue;
    }
    
    // Create the new entity ID format: orgNumber-slugified-name
    $nameSlug = slugify($entity['name']);
    $newEntityId = $entity['orgNumber'] . '-' . $nameSlug;
    
    // Add entity even if it already exists
    if (isset($destData[$newEntityId])) {
        echo "Updating existing entity: $newEntityId\n";
    }
    
    // Determine entity type (default to municipality)
    $type = "municipality";
    if (strpos($entity['entityId'], 'fylkeskommune') !== false) {
        $type = "county";
    } elseif (strpos($entity['entityId'], 'kommune') === false) {
        $type = "agency";
    }
    
    // Create the new entity entry
    $newEntity = [
        'entity_id' => $newEntityId,
        'name' => $entity['name'],
        'email' => $entity['entityEmail'] ?? '',
        'type' => $type,
        'org_num' => $entity['orgNumber']
    ];
    
    // Add to destination data
    $destData[$newEntityId] = $newEntity;
    
    // Add to mapping
    $idMapping[$entity['entityId']] = $newEntityId;
    
    $addedCount++;
    echo "Added: {$entity['name']} (ID: $newEntityId)\n";
}

// Save the updated destination file
file_put_contents($destFile, json_encode($destData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// Save the ID mapping to a file
$mappingFile = __DIR__ . '/entity_id_mapping.json';
file_put_contents($mappingFile, json_encode($idMapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\nSummary:\n";
echo "Added $addedCount entities\n";
echo "Skipped $skippedCount entities\n";
echo "ID mapping saved to $mappingFile\n";
echo "Updated entities saved to $destFile\n";

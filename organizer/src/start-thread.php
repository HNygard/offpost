<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/Entity.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';

// Require authentication
requireAuth();
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/random-profile.php';

$entity_ids = array();
if (isset($_GET['thread_id'])) {
    $entity = Entity::getById($_GET['entity_id']);
    $entity_ids[] = $entity->entity_id;
}
elseif (isset($_POST['thread_id']) && !empty($_POST['thread_id'])) {
    $entity = Entity::getById($_POST['entity_id']);
    $entity_ids[] = $entity->entity_id;
}

if (!isset($_POST['entity_ids']) || empty($_POST['entity_ids'])) {
    // Load entities for dropdown
    $entities = Entity::getAll();

    if (!isset($_GET['body'])) {
        $starterMessages = array(
            "Søker innsyn.",
            "Søker innsyn i:",
            "Ønsker innsyn:",
            "Jeg ønsker innsyn:",
            "Jeg ønsker innsyn i:",
            "Kunne jeg fått innsyn i følgende?",
            "Kunne jeg etter Offentleglova få innsyn i følgende?",
            "Etter Offl:",
            "Etter Offentleglova",
            "Etter Offentleglova ønsker jeg",
            "Etter Offentleglova søker jeg innsyn i:",
            "Vil ha innsyn i",
            "Jfr Offentleglova:",
            "Jfr Offentleglova søker jeg innsyn i:",
            "Jfr Offentleglova søker jeg innsyn i følgende:",
            "Jfr. Offl. søker jeg innsyn i:",
        );
        $rand = mt_rand(0, count($starterMessages) - 1);
        $starterMessage = $starterMessages[$rand];
        
        $_GET['body'] = $starterMessage . "\n\n";
    }


    ?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Start Email Thread - Email Engine Organizer';
    include 'head.php';
    ?>
    <script src="/js/entitySearch.js"></script>
    <script>
        // Initialize entity data for the entity search
        window.entityData = <?php echo json_encode(array_values((array)$entities)); ?>;
    </script>
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #34495e;
            font-weight: bold;
        }
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group textarea {
            height: 300px;
            resize: vertical;
        }
        .form-group input[type="submit"] {
            background-color: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.2s;
        }
        .form-group input[type="submit"]:hover {
            background-color: #2980b9;
        }
        .continue-thread {
            background-color: #66CC66;
            color: white;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 1.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>
        
        <h1>Start Email Thread</h1>
        
        <form method="POST" id="startthreadform-<?=date('Y-m-d')?>">

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="<?= htmlescape(isset($_GET['title']) ? $_GET['title'] : '') ?>">
            </div>

            <div class="form-group">
                <label for="labels">Labels (space separated)</label>
                <input type="text" id="labels" name="labels" value="<?= htmlescape(isset($_GET['labels']) ? $_GET['labels'] : '') ?>">
            </div>

            <div class="form-group">
                <label for="entity-search">Entities</label>
                <div class="entity-search-container">
                    <input type="text" id="entity-search" class="entity-search-input" placeholder="Search for entities...">
                    <ul id="entity-list" class="entity-list"></ul>
                </div>
                
                <div class="selected-entities-container">
                    <div class="selected-entities-header">
                        <h4>Selected Entities <span id="selected-entities-count" class="selected-entities-count">0</span></h4>
                        <button type="button" id="select-all-municipalities" class="select-all-btn">Select All Municipalities</button>
                    </div>
                    <ul id="selected-entities-list" class="selected-entities-list"></ul>
                </div>
                
                <input type="hidden" id="selected-entities-input" name="entity_ids[]" value="[]">
                
                <small style="color: #666; display: block; margin-top: 5px;">Select multiple entities to create one thread for each entity</small>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="public" name="public" value="1" <?= isset($_GET['public']) && $_GET['public'] ? 'checked' : '' ?>>
                    Make thread public (anyone can view)
                </label>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="send_now" name="send_now" value="1" <?= isset($_GET['send_now']) && $_GET['send_now'] ? 'checked' : '' ?>>
                    Send immediately (otherwise save as draft)
                </label>
            </div>

            <div class="form-group">
                <label>Request Law Basis</label>
                <div>
                    <label style="display: inline-block; margin-right: 15px;">
                        <input type="radio" name="request_law_basis" value="offentleglova" checked>
                        Offentlegova
                    </label>
                    <label style="display: inline-block;">
                        <input type="radio" name="request_law_basis" value="other">
                        Other type of request
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label>Request Follow-up Plan</label>
                <div>
                    <label style="display: inline-block; margin-right: 15px;">
                        <input type="radio" name="request_follow_up_plan" value="">
                        No follow up
                    </label>
                    <label style="display: inline-block; margin-right: 15px;">
                        <input type="radio" name="request_follow_up_plan" value="speedy" checked>
                        Simple request, expecting speedy follow up
                    </label>
                    <label style="display: inline-block;">
                        <input type="radio" name="request_follow_up_plan" value="slow">
                        Complex request, expecting slow follow up
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="body">Message Body</label>
                <textarea id="body" name="body"><?= htmlescape(isset($_GET['body']) ? $_GET['body'] : '') ?></textarea>
                <small style="color: #666; display: block; margin-top: 5px;">A signature will be added to the email.</small>
            </div>

            <div class="form-group">
                <input type="submit" value="Create Thread">
            </div>
    </form>
    </body>
    <?php
    exit;
}

// E: entity_ids is set. Form was submitted

// Validate entity IDs
$entityIds = $_POST['entity_ids'];

// Check if the first element is a JSON string (from the new entity search)
if (isset($entityIds[0]) && $entityIds[0] !== '' && $entityIds[0][0] === '[') {
    $entityIds = json_decode($entityIds[0], true);
    
    // If JSON decoding failed or returned an empty array
    if ($entityIds === null || empty($entityIds)) {
        http_response_code(400);
        die("No entities selected. Please select at least one entity. <a href='javascript:history.back()'>Go back</a>");
    }
}

// Validate each entity ID
foreach ($entityIds as $entityId) {
    if (!Entity::exists($entityId)) {
        http_response_code(400);
        die("Invalid entity ID: $entityId. Please select valid entities. <a href='javascript:history.back()'>Go back</a>");
    }
}
$createdThreads = [];
$groupLabel = 'group-' . time() . '-' . substr(md5(rand()), 0, 6);

// Add the group label to the labels
$labelsString = $_POST['labels'];
if (!empty($labelsString)) {
    $labelsString .= ' ' . $groupLabel;
} else {
    $labelsString = $groupLabel;
}
$_POST['labels'] = $labelsString;


Database::beginTransaction();

// Create a thread for each entity
foreach ($entityIds as $entityId) {
    $signDelim = mt_rand(0, 10) > 5 ? '---' : '--';
    $obj = getRandomNameAndEmail();
    $my_name = $obj->firstName . $obj->middleName . ' ' . $obj->lastName;
    $my_email = $obj->email;

    $thread = new Thread();
    $thread->title = $_POST['title'];
    $thread->my_name = $my_name;
    $thread->my_email = $my_email;
    $thread->labels = array();
    $thread->initial_request = $_POST['body']; // Store the initial request
    $thread->sending_status = isset($_POST['send_now']) && $_POST['send_now'] === '1' 
        ? Thread::SENDING_STATUS_READY_FOR_SENDING 
        : Thread::SENDING_STATUS_STAGING;
    $thread->sent = false; // Will be updated if email is sent
    $thread->archived = false;
    $thread->public = isset($_POST['public']) && $_POST['public'] === '1';
    $thread->request_law_basis = isset($_POST['request_law_basis']) ? $_POST['request_law_basis'] : Thread::REQUEST_LAW_BASIS_OTHER;
    $thread->request_follow_up_plan = isset($_POST['request_follow_up_plan']) && !empty($_POST['request_follow_up_plan'])
        ? $_POST['request_follow_up_plan']
        : null;
    $thread->emails = array();

    $labels = explode(' ', $_POST['labels']);
    foreach ($labels as $label) {
        $thread->labels[] = trim($label);
    }
    
    $newThread = ThreadStorageManager::getInstance()->createThread(
        $entityId,
        $thread,
        $_SESSION['user']['sub']
    );
    $threadId = $newThread->id;

    // Set creator as owner using OpenID Connect subject identifier
    $userId = $_SESSION['user']['sub']; // From OpenID Connect session
    $newThread->addUser($userId, true);
    
    // Create a ThreadEmailSending record for this thread
    $entity = Entity::getById($entityId);
    ThreadEmailSending::create(
        $threadId,
        $_POST['body'] . "\n\n$signDelim\n" . $obj->firstName . $obj->middleName . ' ' . $obj->lastName,
        $_POST['title'],
        $entity->email,
        $my_email,
        $my_name,
        isset($_POST['send_now']) && $_POST['send_now'] === '1' 
            ? ThreadEmailSending::STATUS_READY_FOR_SENDING 
            : ThreadEmailSending::STATUS_STAGING
    );
    
    $createdThreads[] = $newThread;
}
Database::commit();

if (count($createdThreads) > 1) {
    // Redirect to the index page with the group label filter
    header('Location: /?label_filter=' . urlencode($groupLabel));
    exit;
} 
else {
    // Redirect to the thread view page
    $thread = $createdThreads[0];
    header('Location: /thread-view?entityId=' . urlencode($thread->entity_id) . '&threadId=' . urlencode($thread->id));
    exit;
}

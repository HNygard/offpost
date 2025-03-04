<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/Entity.php';

// Require authentication
requireAuth();
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/random-profile.php';

$entityId = null;
$threadId = null;
if (isset($_GET['thread_id'])) {
    $entityId = Entity::getById($_GET['entity_id'])->entity_id;
    $threadId = $_GET['thread_id'];
}
if (isset($_POST['thread_id']) && !empty($_POST['thread_id'])) {
    $entityId = Entity::getById($_POST['entity_id'])->entity_id;
    $threadId = $_POST['thread_id'];
}
$thread = null;
if ($threadId != null) {
    $threads = ThreadStorageManager::getInstance()->getThreadsForEntity($entityId);

    foreach ($threads->threads as $thread1) {
        if ($thread1->id == $threadId) {
            $thread = $thread1;
        }
    }
    if ($thread == null) {
        throw new Exception('Unknown thread_id');
    }

    // Check if user has access to continue this thread
    $userId = $_SESSION['user']['sub'];
    if (!ThreadAuthorizationManager::canUserAccessThread($threadId, $userId)) {
        throw new Exception('You do not have access to continue this thread');
    }

    $_GET['my_name'] = $thread->my_name;
    $_GET['my_email'] = $thread->my_email;

    $_GET['body'] = str_replace('MY_EMAIL', $thread->my_email, $_GET['body']);
    $_GET['body'] = str_replace('MY_NAME', $thread->my_name, $_GET['body']);
}

if (!isset($_POST['entity_id'])) {
    // Load entities for dropdown
    $entities = Entity::getAll();

    if (isset($_GET['my_profile']) && $_GET['my_profile'] == 'RANDOM') {
        $obj = getRandomNameAndEmail();
        $_GET['my_name'] = $obj->firstName . $obj->middleName . ' ' . $obj->lastName;
        $_GET['my_email'] = $obj->email;
        if (isset($_GET['body'])) {
            $_GET['body'] .= "\n\n--\n" . $obj->firstName . $obj->middleName . ' ' . $obj->lastName;
        }
    }


    ?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Start Email Thread - Email Engine Organizer';
    include 'head.php';
    ?>
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
<body onload="document.getElementById('startthreadform-2023-09-17').submit();">
    <div class="container">
        <?php include 'header.php'; ?>
        
        <h1>Start Email Thread</h1>
        
        <form method="POST" id="startthreadform-<?=date('Y-m-d')?>">
            <?php if ($thread != null): ?>
                <div class="continue-thread">Continue existing thread...</div>
            <?php endif; ?>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="<?= htmlescape(isset($_GET['title']) ? $_GET['title'] : '') ?>">
            </div>

            <div class="form-group">
                <label for="my_name">My Name</label>
                <input type="text" id="my_name" name="my_name" value="<?= htmlescape(isset($_GET['my_name']) ? $_GET['my_name'] : '') ?>">
            </div>

            <div class="form-group">
                <label for="my_email">My Email</label>
                <input type="text" id="my_email" name="my_email" value="<?= htmlescape(isset($_GET['my_email']) ? $_GET['my_email'] : '') ?>">
            </div>

            <div class="form-group">
                <label for="labels">Labels (space separated)</label>
                <input type="text" id="labels" name="labels" value="<?= htmlescape(isset($_GET['labels']) ? $_GET['labels'] : '') ?>">
            </div>

            <div class="form-group">
                <label for="entity_id">Entity</label>
                <select id="entity_id" name="entity_id" onchange="updateEntityEmail()">
                    <option value="">Select an entity</option>
                    <?php
                    foreach ($entities as $id => $entity) {
                        $selected = (isset($_GET['entity_id']) && $_GET['entity_id'] === $id) ? 'selected' : '';
                        echo "<option value=\"" . htmlescape($id) . "\" $selected>" . htmlescape($entity->name) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="entity_title_prefix">Entity Title Prefix</label>
                <input type="text" id="entity_title_prefix" name="entity_title_prefix" value="<?= htmlescape(isset($_GET['entity_title_prefix']) ? $_GET['entity_title_prefix'] : '') ?>">
                <small style="color: #666; display: block; margin-top: 5px;">Only used if first thread for this entity</small>
            </div>

            <div class="form-group">
                <label for="entity_email">Entity Email</label>
                <input type="text" id="entity_email" name="entity_email" value="<?= htmlescape(isset($_GET['entity_email']) ? $_GET['entity_email'] : '') ?>">
            </div>

            <script>
            function updateEntityEmail() {
                var entityId = document.getElementById('entity_id').value;
                var entities = <?= json_encode($entities) ?>;
                
                if (entities[entityId]) {
                    document.getElementById('entity_email').value = entities[entityId].email;
                }
            }
            </script>

            <div class="form-group">
                <label for="thread_id">Thread ID</label>
                <input type="text" id="thread_id" name="thread_id" value="<?= htmlescape(isset($_GET['thread_id']) ? $_GET['thread_id'] : '') ?>">
                <small style="color: #666; display: block; margin-top: 5px;">Used to continue an existing thread</small>
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
                <label for="body">Message Body</label>
                <textarea id="body" name="body"><?= htmlescape(isset($_GET['body']) ? $_GET['body'] : '') ?></textarea>
            </div>

            <div class="form-group">
                <input type="submit" value="Create Thread">
            </div>
    </form>
    </body>
    <?php
    exit;
}

// Validate entity ID
if (isset($_POST['entity_id']) && !empty($_POST['entity_id'])) {
    $entityId = $_POST['entity_id'];
    
    // Validate entity ID
    if (!Entity::exists($entityId)) {
        http_response_code(400);
        die("Invalid entity ID. Please select a valid entity. <a href='javascript:history.back()'>Go back</a>");
    }
    
    // If valid, pre-fill entity email if not provided
    $_POST['entity_email'] = Entity::getById($entityId)->email;
}

if ($thread == null) {
    $thread = new Thread();
    $thread->title = $_POST['title'];
    $thread->my_name = $_POST['my_name'];
    $thread->my_email = $_POST['my_email'];
    $thread->labels = array();
    $thread->initial_request = $_POST['body']; // Store the initial request
    $thread->sending_status = isset($_POST['send_now']) && $_POST['send_now'] === '1' 
        ? Thread::SENDING_STATUS_READY_FOR_SENDING 
        : Thread::SENDING_STATUS_STAGING;
    $thread->sent = false; // Will be updated if email is sent
    $thread->archived = false;
    $thread->public = isset($_POST['public']) && $_POST['public'] === '1';
    $thread->emails = array();

    $labels = explode(' ', $_POST['labels']);
    foreach ($labels as $label) {
        $thread->labels[] = trim($label);
    }
    $newThread = ThreadStorageManager::getInstance()->createThread(
            $_POST['entity_id'],
            $_POST['entity_title_prefix'],
            $thread,
            $_SESSION['user']['sub']
        );
    $threadId = $newThread->id;

    // Set creator as owner using OpenID Connect subject identifier
    $userId = $_SESSION['user']['sub']; // From OpenID Connect session
    $newThread->addUser($userId, true);

    $threads = ThreadStorageManager::getInstance()->getThreadsForEntity($_POST['entity_id']);

    $thread = null;
    foreach ($threads->threads as $thread1) {
        if ($thread1->id == $threadId) {
            $thread = $thread1;
        }
    }

    if ($thread == null) {
        throw new Exception('Error. Missing thread.');
    }
}


if (isset($_POST['body']) && !empty($_POST['body'])) {
    // -> Send email
    $result = sendThreadEmail($thread, 
        $_POST['entity_email'], 
        $_POST['title'], 
        $_POST['body'], 
        $entityId,
        $_SESSION['user']['sub']);
    
    echo '<div style="height: 400px; overflow-y: scroll; font-family: monospace;">';
    echo $result['debug'];
    echo '</div>';
    
    if ($result['success']) {
        echo '<pre style="color: green; font-size: 2em;">Message has been sent</pre>';
    } else {
        echo '<pre style="color: red; font-size: 2em;">Message could not be sent. Mailer Error: ' . $result['error'] . '</pre>';
    }
}


echo '<br>Created. <a href="./">Back</a>';

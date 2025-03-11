<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Entity.php';
require_once __DIR__ . '/class/Database.php';

// Require authentication
requireAuth();

// Get all entities
$entities = Entity::getAll();

// Get thread counts for each entity
$threadCounts = [];
$query = "SELECT entity_id, COUNT(*) as thread_count FROM threads GROUP BY entity_id";
$results = Database::query($query);

foreach ($results as $row) {
    $threadCounts[$row['entity_id']] = $row['thread_count'];
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Entities - Offpost';
    include 'head.php';
    ?>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Entities</h1>

        <table>
            <tr>
                <th>Name</th>
                <th>Norske-postlister.no</th>
                <th>Number of Threads</th>
                <th>Status</th>
            </tr>
            <?php
            foreach ($entities as $entity_id => $entity) {
                $threadCount = isset($threadCounts[$entity_id]) ? $threadCounts[$entity_id] : 0;
                $hasEmail = isset($entity->email) && !empty($entity->email);
                $hasOrgNum = isset($entity->org_num) && !empty($entity->org_num);
                $hasNorskePostlister = isset($entity->entity_id_norske_postlister) && !empty($entity->entity_id_norske_postlister);
                ?>
                <tr>
                    <td>
                        <b><?= Entity::getNameHtml($entity) ?></b><br>
                        <span style="font-size: 0.8em;"><?= htmlspecialchars($entity_id) ?></span>
                    </td>
                    <td>
                        <?php if ($hasNorskePostlister): ?>
                            <a href="https://norske-postlister.no/myndighet/<?= htmlspecialchars($entity->entity_id_norske_postlister) ?>/" target="_blank">
                                View on Norske-postlister.no
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= $threadCount ?></td>
                    <td>
                        <?php if (!$hasEmail): ?>
                            <span class="label label_warn">Missing Email</span><br>
                        <?php endif; ?>
                        <?php if (!$hasOrgNum): ?>
                            <span class="label label_warn">Missing Org Number</span>
                        <?php endif; ?>
                        <?php if ($hasEmail && $hasOrgNum) { ?>
                            <span class="label label_ok">Complete</span>
                        <?php } else { ?>
                            <div><a href="https://github.com/HNygard/offpost/blob/main/data/entities.json">Add at Github</a></div>
                        <?php } ?>
                    </td>
                </tr>
                <?php
            }
            ?>
        </table>
    </div>
</body>
</html>

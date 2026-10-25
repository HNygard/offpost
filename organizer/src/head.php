<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Offpost';
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?></title>
<link rel="icon" type="image/png" href="/images/offpost-favicon.png">
<?php // Cache-bust on every change to the file, instead of a hand-bumped ?N. ?>
<link href="/css/style.css?<?= filemtime(__DIR__ . '/webroot/css/style.css') ?>" rel="stylesheet">

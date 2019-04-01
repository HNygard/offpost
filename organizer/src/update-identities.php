<?php

function getThreads($path) {
    /* @var Threads $threads */
    $threads = json_decode(file_get_contents($path));

    return $threads;
}

require_once __DIR__ . '/class/Identity.php';


$connection = new PDO('mysql:dbname=roundcubemail;host=mysql;port=3306;charset=UTF8', 'root', 'root', array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
));
$repo = new IdentityRepository($connection);
$identities = $repo->getIdentities();


$threads = getThreads('/organizer-data/threads/threads-1129-forsand-kommune.json');
foreach ($threads->threads as $thread) {
    $id_found = false;
    foreach ($identities as $identity) {
        if ($identity->name == $thread->my_name && $identity->email == $thread->my_email) {
            $id_found = true;
        }

    }
    echo ($thread->my_name . ' ---- ' . $thread->my_email . ' --- [' . ($id_found ? 'found' : 'not found') . '].') . '<br>';

    if (!$id_found) {
        echo '-- [DB IDENTITY CREATED]' . chr(10);
        $repo->createIdentity($thread->my_name, $thread->my_email);
    }
}
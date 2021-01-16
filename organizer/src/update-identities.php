<?php

require_once __DIR__ . '/class/Identity.php';
require_once __DIR__ . '/class/Threads.php';


$connection = new PDO('mysql:dbname=roundcubemail;host=mysql;port=3306;charset=UTF8', 'root', 'root', array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
));
$repo = new IdentityRepository($connection);
$identities = $repo->getIdentities();


$allThreads = getThreads();
foreach ($allThreads as $threads) {
    foreach ($threads->threads as $thread) {
        $id_found = false;
        foreach ($identities as $identity) {
            if ($identity->name == $thread->my_name && $identity->email == $thread->my_email) {
                $id_found = true;
            }

        }
        echo ($thread->my_name . ' ---- ' . $thread->my_email . ' --- [' . ($id_found ? 'found' : 'not found') . '].') . '<br>';

        if (!$id_found) {
            echo '-- [DB IDENTITY CREATED]<br>' . chr(10);
            $repo->createIdentity($thread->my_name, $thread->my_email);
        }
    }
}
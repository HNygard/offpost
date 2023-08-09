<?php

require_once __DIR__ . '/class/random-profile.php';


$obj = getRandomNameAndEmail();
echo 'First name .... : ' . $obj->firstName . chr(10);
echo 'Middle name ... : ' . trim($obj->middleName) . chr(10);
echo 'Last name ..... : ' . $obj->lastName . chr(10);
echo chr(10);
echo 'E-mail ........ : ' . $obj->email . chr(10);

echo chr(10);
echo 'http://localhost:25081/start-thread.php'
    . '?my_email=' . urlencode($obj->email)
    . '&my_name=' . urlencode($obj->firstName . $obj->middleName . ' ' . $obj->lastName)
    . chr(10);

echo '<pre>';

$data = array();
for ($i = 0; $i < 400; $i++) {
    $data[] = getRandomNameAndEmail();
}
$obj = new stdClass();
$obj->comment = 'Created by Email Engine: http://localhost:25081/generate-profile.php';
$obj->profiles = $data;
echo json_encode($obj, JSON_UNESCAPED_SLASHES ^ JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE) . '</pre>';
echo json_last_error_msg() . chr(10);
<?php

require_once __DIR__ . '/../class/common.php';

if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst($string, $encoding) {
        $firstChar = mb_substr($string, 0, 1, $encoding);
        $then = mb_substr($string, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }
}

function profileRandom($percentage, $string1, $string2) {
    return rand(0, 100) > $percentage ? $string1 : $string2;
}

function getNamesFromCsv($file1) {
    $file = explode("\n", str_replace("\r", "\n", file_get_contents($file1)));
    $names = array();
    foreach ($file as $line) {
        $name = trim(explode(';', trim($line), 2)[0]);
        if (!empty($name)) {
            $name = strtoupper(preg_replace('/^[0-9]* /', '', $name));
            $names[] = $name;
            $names[] = mb_strtolower($name, 'UTF-8');
        }
    }

    return $names;
}

$first_names_to_clean = array_merge(
// Source: SSB
    getNamesFromCsv(__DIR__ . '/../copy-name-and-email-cleaner/guttenavn.csv'),
    getNamesFromCsv(__DIR__ . '/../copy-name-and-email-cleaner/jentenavn.csv')
// Source: Motorvognregisteret (motorvognregisteret-extract-names.php)
//getNamesFromCsv(__DIR__ . '/motorvognregisteret-first-name.csv')
);
$last_names_to_clean = array_merge(
// Source: SSB
    getNamesFromCsv(__DIR__ . '/../copy-name-and-email-cleaner/etternavn.csv')
// Source: Motorvognregisteret (motorvognregisteret-extract-names.php)
//getNamesFromCsv(__DIR__ . '/motorvognregisteret-last-name.csv')
);

function getRandomNameAndEmail() {
    global $first_names_to_clean, $last_names_to_clean;
    $first = $first_names_to_clean[array_rand($first_names_to_clean)];
    $last = $last_names_to_clean[array_rand($last_names_to_clean)];

    // Random: Å ha mellomnavn
    $middle = profileRandom(70, $last_names_to_clean[array_rand($last_names_to_clean)], '');

    $first = trim(mb_ucfirst(mb_strtolower($first, 'UTF-8'), 'UTF-8'),  " \n\r\t\v\x00");
    $middle = trim(mb_ucfirst(mb_strtolower($middle, 'UTF-8'), 'UTF-8'),  " \n\r\t\v\x00");
    $last = trim(mb_ucfirst(mb_strtolower($last, 'UTF-8'), 'UTF-8'),  " \n\r\t\v\x00");

    $middleShort = '';
    if ($middle != '') {
        // Random: Forkort mellomnavn
        $middleShort = ' ' . profileRandom(70, $middle, substr($middle, 0, 1) . '.');
    }

    $email = mb_strtolower($first, 'UTF-8');

    if ($middle != '') {
        // Random: Short or full in email
        $emailMiddle = profileRandom(20, $middleShort, $middle);
        $emailMiddle = str_replace('.', '', $emailMiddle);
        // Random: To include middle name in email
        $email .= profileRandom(70, '.' . mb_strtolower($emailMiddle, 'UTF-8'), '');
    }

    $email .= '.' . mb_strtolower($last, 'UTF-8');
    $email .= '@offpost.no';

    // Random: Replace å with aa or a
    $email = str_replace('å', profileRandom(50, 'aa', 'a'), $email);
    $email = str_replace('æ', 'ae', $email);
    $email = str_replace('ø', profileRandom(50, 'oe', 'o'), $email);

    // Clean out all whitespace characters
    $email = preg_replace('/\s+/', '', $email);
    
    // Ensure email doesn't start with a period
    $email = ltrim($email, '.');

    $obj = new stdClass();
    $obj->firstName = $first;
    $obj->middleName = $middleShort;
    $obj->lastName = $last;
    $obj->email = $email;
    return $obj;
}

<?php /** @noinspection ALL */

set_time_limit(0);
ini_set('memory_limit', '-1');

/**
 * Support multiple utf8 string in a row.
 *
 * Test data:
 * echo imap_utf8_improved_by_hallvard('=?utf-8?B?VmVkcsO4cmVuZGUgYmVnasOmcmluZyBvbSBpbm5zeW4gaSBwb3N0bGlz?==?utf-8?B?dGUgc2lzdGUgMTAgw6VyICgyMDExLTIwMjEpIC0gdGlsYmFrZW1lbGRp?==?utf-8?B?bmcucGRm?=').chr(10);
 * echo imap_utf8_improved_by_hallvard('=?utf-8?B?VmVkcsO4cmVuZGUgYmVnasOmcmluZyBvbSBpbm5zeW4gaSBwb3N0bGlz?==?utf-8?B?dGUgc2lzdGUgMTAgw6VyICgyMDExLTIwMjEpLnBkZg==?=').chr(10);
 * echo imap_utf8_improved_by_hallvard('=?utf-8?B?VmVkcsO4cmVuZGUgYmVnasOmcmluZyBvbSBpbm5zeW4gaSBwb3N0bGlz?==?utf-8?B?dGUgc2lzdGUgMTAgw6VyICgyMDExLTIwMjEpIC0gdGlsYmFrZW1lbGRp?==?utf-8?B?bmcucGRm?=').chr(10);
 */
function imap_utf8_improved_by_hallvard($string) {
    if (str_starts_with($string, "iso-8859-1''")
        || str_starts_with($string, "ISO-8859-1''")) {
        $string = str_replace("iso-8859-1''", '', $string);
        $string = str_replace("ISO-8859-1''", '', $string);

        if (
            str_contains($string, '%20')
            || str_contains($string, '%E6')
            || str_contains($string, '%F8')

        ) {
            foreach (array(
                         '%E6' => 'æ',
                         '%C0' => 'À',
                         '%C1' => 'Á',
                         '%C2' => 'Â',
                         '%C3' => 'Ã',
                         '%C4' => 'Ä',
                         '%C5' => 'Å',
                         '%C6' => 'Æ',
                         '%C7' => 'Ç',
                         '%C8' => 'È',
                         '%C9' => 'É',
                         '%CA' => 'Ê',
                         '%CB' => 'Ë',
                         '%CC' => 'Ì',
                         '%CD' => 'Í',
                         '%CE' => 'Î',
                         '%CF' => 'Ï',
                         '%D0' => 'Ð',
                         '%D1' => 'Ñ',
                         '%D2' => 'Ò',
                         '%D3' => 'Ó',
                         '%D4' => 'Ô',
                         '%D5' => 'Õ',
                         '%D6' => 'Ö',
                         '%D8' => 'Ø',
                         '%D9' => 'Ù',
                         '%DA' => 'Ú',
                         '%DB' => 'Û',
                         '%DC' => 'Ü',
                         '%DD' => 'Ý',
                         '%DE' => 'Þ',
                         '%DF' => 'ß',
                         '%E0' => 'à',
                         '%E1' => 'á',
                         '%E2' => 'â',
                         '%E3' => 'ã',
                         '%E4' => 'ä',
                         '%E5' => 'å',
                         '%E6' => 'æ',
                         '%E7' => 'ç',
                         '%E8' => 'è',
                         '%E9' => 'é',
                         '%EA' => 'ê',
                         '%EB' => 'ë',
                         '%EC' => 'ì',
                         '%ED' => 'í',
                         '%EE' => 'î',
                         '%EF' => 'ï',
                         '%F0' => 'ð',
                         '%F1' => 'ñ',
                         '%F2' => 'ò',
                         '%F3' => 'ó',
                         '%F4' => 'ô',
                         '%F5' => 'õ',
                         '%F6' => 'ö',
                         '%F8' => 'ø',
                         '%F9' => 'ù',
                         '%FA' => 'ú',
                         '%FB' => 'û',
                         '%FC' => 'ü',
                    ) as $a => $b) {
                $string = str_replace($a, $b, $string);

            }
            $string = urldecode($string);
        }

    }
    preg_match_all('/(\=\?utf\-8\?B\?[A-Za-z0-9=]*\?=)/', $string, $matches);
    foreach ($matches[0] as $match) {
        $string = str_replace($match, imap_utf8($match), $string);
    }
    return imap_utf8($string);
}

// sudo apt-get install php5-imap
// + enable imap in PHP

echo '<pre>';

require_once __DIR__ . '/imap-connection.php';
require_once __DIR__ . '/class/Threads.php';
$mailbox = openConnection();


logDebug('---- EXISTING FOLDERS ----');
$list = imap_list($mailbox, $server, "*");
$list_subscribed = imap_lsub($mailbox, $server, '*');

asort($list);
$folders = array();
foreach ($list as $folder) {
    logDebug('-- ' . $folder);
    $folders[$folder] = $folder;
}

$folders_subscribed = array();
foreach ($list_subscribed as $folder) {
    $folders_subscribed[$folder] = $folder;
}
logDebug('');
logDebug('---- CREATING FOLDERS ----');
$threads = getThreads();
$folder_that_should_exist = array('INBOX.Archive');
foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $folder_that_should_exist[] = getThreadEmailFolder($entity_threads, $thread);
    }
}
foreach ($folder_that_should_exist as $title) {
    $outputPrefix = '-- ' . $title . '        ';
    if (str_contains($title, 'INBOX.Archive.')) {
        //echo $outputPrefix . '[ARCHIVED]' . chr(10);
    }
    else {
        if (isset($folders[$server . $title])) {
            //echo $outputPrefix . '[OK]' . chr(10);
        }
        else {
            logDebug($outputPrefix);
            imap_createmailbox($mailbox, imap_utf7_encode($server . $title));
            checkForImapError();
            logDebug('[CREATED]');
        }
    }
}

logDebug('');
logDebug('---- ARCHIVING FOLDERS ----');
$email_to_folder = array();
function getThreadEmailFolder($entity_threads, $thread) {
    $title = $entity_threads->title_prefix . ' - ' . str_replace('/', '-', $thread->title);
    $title = str_replace('Æ', 'AE', $title);
    $title = str_replace('Ø', 'OE', $title);
    $title = str_replace('Å', 'AA', $title);
    $title = str_replace('æ', 'ae', $title);
    $title = str_replace('ø', 'oe', $title);
    $title = str_replace('å', 'aa', $title);
    return $thread->archived
        ? 'INBOX.Archive.' . $title
        : 'INBOX.' . $title;
}

foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $title = str_replace('INBOX.Archive.', '', getThreadEmailFolder($entity_threads, $thread));
        if (!$thread->archived && $thread->my_email != 'dmarc@offpost.no') {
            // -> Only move from inbox/sent email into thread folders that are not archived
            $email_to_folder[$thread->my_email] = getThreadEmailFolder($entity_threads, $thread);
        }
        if ($thread->archived) {
            $outputPrefix = '-- ' . $title . '        ';
            if (isset($folders[$server . 'INBOX.' . str_replace('INBOX.Archive.', '', $title)])) {
                // -> Exists and should be moved
                logDebug($outputPrefix);
                imap_renamemailbox(
                    $mailbox,
                    imap_utf7_encode($server . 'INBOX.' . $title),
                    imap_utf7_encode($server . 'INBOX.Archive.' . $title)
                );
                checkForImapError();
                logDebug('[ARCHIVED]');
            }
            elseif (isset($folders[$server . 'INBOX.Archive.' . $title])) {
                //echo $outputPrefix . '[OK]' . chr(10);
            }
            else {
                // -> Don't exist
                //echo $outputPrefix . '[N/A]' . chr(10);
            }
        }
    }
}
logDebug('');
logDebug('---- SUBSCRIBE TO FOLDERS ----');
logDebug('(only listing new subscriptions)');
foreach ($folder_that_should_exist as $title) {
    if (!isset($folders_subscribed[$server . $title])) {
        logDebug('-- ' . $title . '        ');
        imap_subscribe($mailbox, imap_utf7_encode($server . $title));
        checkForImapError();
        logDebug('[SUBSCRIBED]');
    }
}

logDebug('');
logDebug('---- SEARCH EMAILS ----');

logDebug('-- INBOX');
moveEmails($mailbox);
imap_close($mailbox, CL_EXPUNGE);

logDebug('-- INBOX.Sent');
$mailboxSent = openConnection('INBOX.Sent');
moveEmails($mailboxSent);
imap_close($mailboxSent, CL_EXPUNGE);
function moveEmails($mailbox) {
    global $email_to_folder;
    $mails = imap_search($mailbox, "ALL", SE_UID);
    checkForImapError();
    if (!$mails) {
        logDebug('No email.');
        return;
    }
    $new_emails_saved = false;
    foreach ($mails as $mail) {
        logDebug('[' . $mail . '] Begin');
        $mail_headers = imap_headerinfo($mailbox, imap_msgno($mailbox, $mail));
        checkForImapError();
        $subject = $mail_headers->subject;

        $to_from = array();
        if (isset($mail_headers->to)) {
            foreach ($mail_headers->to as $email) {
                $to_from[] = $email->mailbox . '@' . $email->host;
            }
        }
        foreach ($mail_headers->from as $email) {
            $to_from[] = $email->mailbox . '@' . $email->host;
        }
        foreach ($mail_headers->reply_to as $email) {
            $to_from[] = $email->mailbox . '@' . $email->host;
        }
        foreach ($mail_headers->sender as $email) {
            $to_from[] = $email->mailbox . '@' . $email->host;
        }


        $should_be_moved_to = 'INBOX';
        foreach ($to_from as $email) {
            if (isset($email_to_folder[$email])) {
                logDebug('FOUND : ' . $email_to_folder[$email]);
                $should_be_moved_to = $email_to_folder[$email];
            }
        }

        $from = $mail_headers->from[0]->mailbox . '@' . $mail_headers->from[0]->host;

        logDebug('FROM ........... : ' . $from);
        logDebug('SUBJECT ........ : ' . $subject);
        logDebug('MOVE TO ........ : ' . $should_be_moved_to);
        if ($should_be_moved_to == 'INBOX') {
            foreach ($to_from as $email) {
                echo '- <a href="start-thread.php?my_email=' . urlencode($email) . '">Start thread with ' . htmlescape($email) . '</a>' . chr(10);
            }
        }
        logDebug('');

        imap_mail_move($mailbox, $mail, $should_be_moved_to, CP_UID);
        checkForImapError();

        if (!isset($customers[$from])) {
        }
        else {
            $account_id = $customers[$from];
            $structure = imap_fetchstructure($mailbox, $mail, FT_UID);
            checkForImapError();

            /*
            $attachments = array();
            if(isset($structure->parts) && count($structure->parts)) {

                for($i = 0; $i < count($structure->parts); $i++) {

                    $attachments[$i] = array(
                        'is_attachment' => false,
                        'filename' => '',
                        'name' => '',
                        'attachment' => ''
                    );

                    if($structure->parts[$i]->ifdparameters) {
                        foreach($structure->parts[$i]->dparameters as $object) {
                            if(strtolower($object->attribute) == 'filename') {
                                $attachments[$i]['is_attachment'] = true;
                                $attachments[$i]['filename'] = $object->value;
                            }
                        }
                    }

                    if($structure->parts[$i]->ifparameters) {
                        foreach($structure->parts[$i]->parameters as $object) {
                            if(strtolower($object->attribute) == 'name') {
                                $attachments[$i]['is_attachment'] = true;
                                $attachments[$i]['name'] = $object->value;
                            }
                        }
                    }

                    if($attachments[$i]['is_attachment']) {
                        $attachments[$i]['attachment'] = imap_fetchbody($mailbox, $mail, $i+1, FT_UID);
                        checkForImapError();
                        if($structure->parts[$i]->encoding == 3) { // 3 = BASE64
                            $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                        }
                        elseif($structure->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
                            $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                        }
                    }
                }
            }

            foreach ($attachments as $key => $attachment) {
                if (!$attachment['is_attachment']) {
                    logDebug('Not attachment. '.print_r($attachment, true));
                    continue;
                }
                $name = $attachment['name'];
                $contents = $attachment['attachment'];
                $recondo_mail_folder = __DIR__ . '/../../recondo-data/accounts/'.$account_id.'/email-attachments/';
                if (!file_exists($recondo_mail_folder)) {
                    mkdir($recondo_mail_folder, 0777, true);
                }
                $path = $recondo_mail_folder.time().'-'.$name;
                logDebug('Saving attachment to ['.$path.']. '.print_r($attachment, true));
                file_put_contents($path, $contents);
            }
            */

            /*
            imap_setflag_full($mailbox, $mail, "\\Seen \\Flagged");
            checkForImapError();
            imap_mail_move($mailbox, $mail, "[Gmail]/All Mail", CP_UID);
            checkForImapError();
    */
            $new_emails_saved = true;
        }
    }
}


$cache_file = '/organizer-data/cache-threads.json';
$cache = null;
if (file_exists($cache_file)) {
    $cache = json_decode(file_get_contents($cache_file));
}
if ($cache == null) {
    $cache = new stdClass();
    $cache->thread_modified = array();
}
function writeCache() {
    global $cache_file, $cache;
    file_put_contents($cache_file, json_encode($cache, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE));
}

logDebug('');
logDebug('---- SAVE EMAILS ----');

foreach ($threads as $thread_file => $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $folder = getThreadEmailFolder($entity_threads, $thread);
        logDebug('-- ' . $folder);
        $folderJson = '/organizer-data/threads/' . $entity_threads->entity_id . '/' . getThreadId($thread);
        if (!file_exists($folderJson)) {
            mkdir($folderJson, 0777, true);
        }
        logDebug('   Folder ... : ' . $folderJson);

        if (file_exists($folderJson . '/archiving_finished.json')) {
            if (!$thread->archived) {
                // Folder have been unarchived.
                unlink($folderJson . '/archiving_finished.json');
            }
            else {
                // Not checking this folder again.
                continue;
            }
        }

        if (isset($_GET['update-only-before'])) {
            $last_modified = 0;
            if (isset($cache->{$folderJson})) {
                $last_modified = $cache->{$folderJson};
            }
            if ($last_modified > strtotime($_GET['update-only-before'])) {
                logDebug('   Skipping. Last modified: ' . date('Y-m-d H:i:s', $last_modified));
                continue;
            }
            logDebug('   Updating. Last modified: ' . date('Y-m-d H:i:s', $last_modified));
        }


        try {
            $mailboxThread = openConnection($folder);
            saveEmails($mailboxThread, $folderJson, $thread);

            if ($thread->archived) {
                logDebug('Archiving finished.');
                file_put_contents($folderJson . '/archiving_finished.json', '{"date": "' . date('Y-m-d H:i:s') . '"}');
            }

            $cache->{$folderJson} = time();
            writeCache();
        }
        catch(Exception $e) {
            logDebug('ERROR during saveEmails().');
            logDebug($e->getMessage());
            logDebug($e->getTraceAsString());
            //sleep(30);
            throw $e;
        }

        logDebug('');
    }
    saveEntityThreads($entity_threads->entity_id, $entity_threads);
}

/**
 * @param $mailbox
 * @param $folderJson
 * @param Thread $thread
 * @throws Exception
 */
function saveEmails($mailbox, $folderJson, &$thread) {
    global $email_to_folder;
    $mails = imap_search($mailbox, "ALL", SE_UID);
    checkForImapError();
    if (!$mails) {
        logDebug('   No email.');
        return;
    }


    $email_datetime = array();
    foreach ($mails as $mail) {
        logDebug('   [' . $mail . '] Begin');
        $mail_headers = imap_headerinfo($mailbox, imap_msgno($mailbox, $mail));
        checkForImapError();

        // :: Duplicates
        unset($mail_headers->Date);
        unset($mail_headers->Subject);

        // Recent, changes all the time
        unset($mail_headers->Recent);

        $in_or_out = $mail_headers->from[0]->mailbox . '@' . $mail_headers->from[0]->host == $thread->my_email
            ? 'OUT'
            : 'IN';
        $datetime = date('Y-m-d_His', strtotime($mail_headers->date));


        if (isset($email_datetime[$datetime])) {
            // Gaaah. Same sending time on two emails. Doing the hack one more level.
            if (!isset($email_datetime[$datetime . '_2'])) {
                $datetime .= '_2';
            }
            if (!isset($email_datetime[$datetime . '_3'])) {
                $datetime .= '_3';
            }
            if (!isset($email_datetime[$datetime . '_4'])) {
                $datetime .= '_4';
            }
            if (!isset($email_datetime[$datetime . '_5'])) {
                $datetime .= '_5';
            }

            if (isset($email_datetime[$datetime])) {
                throw new Exception('Many.');
            }
        }
        $file_name = $datetime . ' - ' . $in_or_out;
        $email_datetime[$datetime] = $datetime;

        $email_raw_file = $folderJson . '/' . $file_name . '.eml';
        if (!file_exists($email_raw_file)) {
            file_put_contents($email_raw_file, imap_fetchbody($mailbox, $mail, "", FT_UID));
        }

        $email_json_file = $folderJson . '/' . $file_name . '.json';

        if (file_exists($email_json_file)) {
            continue;
        }

        $obj = new stdClass();

        $obj->subject = $mail_headers->subject;
        unset($mail_headers->subject);

        $obj->timestamp = strtotime($mail_headers->date);
        $obj->date = $mail_headers->date;
        unset($mail_headers->date);

        $mail_headers->toaddress = isset($mail_headers->toaddress) ? imap_utf8($mail_headers->toaddress) : null;
        $mail_headers->fromaddress = imap_utf8($mail_headers->fromaddress);
        $mail_headers->senderaddress = imap_utf8($mail_headers->senderaddress);
        $mail_headers->reply_toaddress = imap_utf8($mail_headers->reply_toaddress);
        if (isset($mail_headers->to[0]->personal)) {
            $mail_headers->to[0]->personal = imap_utf8($mail_headers->to[0]->personal);
        }
        if (isset($mail_headers->from[0]->personal)) {
            $mail_headers->from[0]->personal = imap_utf8($mail_headers->from[0]->personal);
        }
        if (isset($mail_headers->sender[0]->personal)) {
            $mail_headers->sender[0]->personal = imap_utf8($mail_headers->sender[0]->personal);
        }
        if (isset($mail_headers->reply_to[0]->personal)) {
            $mail_headers->reply_to[0]->personal = imap_utf8($mail_headers->reply_to[0]->personal);
        }

        $obj->attachments = array();

        $obj->body = imap_body($mailbox, $mail, FT_UID);
        checkForImapError();

        if (json_encode($obj->body, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES) === false) {
            $obj->body = mb_convert_encoding($obj->body, 'UTF-8', 'ISO-8859-1');
        }

        $obj->mailHeaders = $mail_headers;

        $structure = imap_fetchstructure($mailbox, $mail, FT_UID);
        checkForImapError();

        $attachments = array();
        if (isset($structure->parts) && count($structure->parts)) {
            for ($i = 0; $i < count($structure->parts); $i++) {
                $attachments[$i] = array(
                    'is_attachment' => false,
                    'filename' => '',
                    'name' => '',
                    'attachment' => ''
                );

                if ($structure->parts[$i]->ifdparameters) {
                    foreach ($structure->parts[$i]->dparameters as $object) {
                        if (strtolower($object->attribute) == 'filename'
                            || strtolower($object->attribute) == 'filename*') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['filename'] = imap_utf8_improved_by_hallvard($object->value);
                        }
                    }
                }

                if ($structure->parts[$i]->ifparameters) {
                    foreach ($structure->parts[$i]->parameters as $object) {
                        if (strtolower($object->attribute) == 'name'
                            || strtolower($object->attribute) == 'name*') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['name'] = imap_utf8_improved_by_hallvard($object->value);
                        }
                    }
                }

                if ($attachments[$i]['is_attachment']) {
                    $att = new stdClass();
                    $att->name = $attachments[$i]['name'];
                    $att->filename = $attachments[$i]['filename'];

                    $att->name = str_replace('=?UTF-8?Q?Stortingsvalg_=2D_Valgstyrets=5Fm=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epdf?=',
                        'Stortingsvalg - Valgstyrets-møtebok-1806-2021.pdf', $att->name);
                    $att->filename = str_replace('=?UTF-8?Q?Stortingsvalg_=2D_Valgstyrets=5Fm=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epdf?=',
                        'Stortingsvalg - Valgstyrets-møtebok-1806-2021.pdf', $att->filename);


                    $att->name = str_replace('=?UTF-8?Q?Samtingsvalg_=2D_Samevalgstyrets_m=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epd?=	f',
                        'Samtingsvalg.pdf', $att->name);
                    $att->filename = str_replace('=?UTF-8?Q?Samtingsvalg_=2D_Samevalgstyrets_m=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epd?=	f',
                        'Samtingsvalg.pdf', $att->filename);

                    if (
                        str_ends_with(strtolower($att->name), '.pdf')
                        || str_ends_with(strtolower($att->name), '.pd f')
                        || str_ends_with(strtolower($att->name), '.p df')
                        || str_ends_with(strtolower($att->name), '. pdf')
                    ) {
                        $att->filetype = 'pdf';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.pdf')) {
                        $att->filetype = 'pdf';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.jpg')) {
                        $att->filetype = 'jpg';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.png')) {
                        $att->filetype = 'png';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.docx')) {
                        $att->filetype = 'docx';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.doc')) {
                        $att->filetype = 'doc';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.xlsx')) {
                        $att->filetype = 'xlsx';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.xlsm')) {
                        $att->filetype = 'xlsm';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.pptx')) {
                        $att->filetype = 'pptx';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.zip')) {
                        $att->filetype = 'zip';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.gz')) {
                        $att->filetype = 'gz';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.gif')) {
                        $att->filetype = 'gif';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.eml')) {
                        $att->filetype = 'eml';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.csv')) {
                        $att->filetype = 'csv';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.txt')) {
                        $att->filetype = 'txt';
                    }
                    elseif (str_ends_with(strtolower($att->name), '.rda')) {
                        $att->filetype = 'UNKNOWN';
                    }
                    elseif (empty($att->name)
                        || str_starts_with($att->name, 'Valgstyrets_møtebok_4649_2021-11-18')
                    || str_starts_with($att->name, 'Outlook-kvafjord k')) {
                        $att->filetype = 'UNKNOWN';
                    }
                    else {
                        throw new Exception("Unknown file type:\n" .
                            "Name ......... : " . $att->name . "\n" .
                            "File name .... : " . $att->filename);
                    }

                    // Don't include the name, since we have no control over it.
                    $att->location = $file_name . ' - att ' . $i . '-' . md5($att->name) . '.' . $att->filetype;

                    json_encode($att, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE);
                    if (json_last_error() != JSON_ERROR_NONE) {
                        echo 'NAME NOT AVAILABLE - JSON ENCODE ERROR' . chr(10);
                        var_dump($att);
                        $name1 = $att->name;
                        $name2 = $att->filename;
                        $att->name = 'NAME NOT AVAILABLE - JSON ENCODE ERROR.';
                        $att->filename = 'NAME NOT AVAILABLE - JSON ENCODE ERROR.';
                        json_encode($att, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE);
                        if (json_last_error() != JSON_ERROR_NONE) {
                            var_dump($att);
                            var_dump($name1);
                            var_dump($name2);
                            throw new Exception('Unable to read JSON.');
                        }
                    }

                    $obj->attachments[] = $att;

                    $path = $folderJson . '/' . $att->location;
                    if (file_exists($path)) {
                        continue;
                    }

                    $attachment_file = imap_fetchbody($mailbox, $mail, $i + 1, FT_UID);
                    checkForImapError();
                    if ($structure->parts[$i]->encoding == 3) { // 3 = BASE64
                        $attachment_file = base64_decode($attachment_file);
                    }
                    elseif ($structure->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
                        $attachment_file = quoted_printable_decode($attachment_file);
                    }

                    logDebug($att->name . ' - Saving attachment to [' . $path . ']');
                    file_put_contents($path, $attachment_file);
                    chmod($path, 0777);
                }
                else {
                    //logDebug('Not attachment. ' . print_r($attachments[$i], true));
                }
            }
        }

        foreach ($attachments as $key => $attachment) {
            if (!$attachment['is_attachment']) {
                continue;
            }
        }

        if (!isset($thread->emails)) {
            $thread->emails = array();
        }

        $found = false;
        foreach ($thread->emails as $thead_email) {
            if (!isset($thead_email->id)) {
                // continue;
                var_dump($thead_email);
                throw new Exception('Thread email missing ID ^');
            }

            if ($thead_email->id == $file_name) {
                $found = true;
            }
        }
        if (!$found) {
            /* @var $new_email ThreadEmail */
            $new_email = new stdClass();
            $new_email->timestamp_received = $obj->timestamp;
            $new_email->datetime_received = date('Y-m-d H:i:s', $new_email->timestamp_received);
            $new_email->datetime_first_seen = date('Y-m-d H:i:s');
            $new_email->id = $file_name;
            $new_email->email_type = $in_or_out;
            $new_email->status_type = 'unknown';
            $new_email->status_text = 'Uklassifisert';

            // TODO: Handle auto reply
            $new_email->ignore = false;

            if (isset($obj->attachments) && count($obj->attachments)) {
                $new_email->attachments = array();
                foreach ($obj->attachments as $att) {
                    $att->status_type = 'unknown';
                    $att->status_text = 'uklassifisert-dok';
                    $new_email->attachments[] = $att;
                }
            }

            $thread->emails[] = $new_email;

            usort($thread->emails, function ($a, $b) {
                /* @var $a ThreadEmail */
                /* @var $b ThreadEmail */
                return strcmp($a->datetime_received, $b->datetime_received);
            });

            $found_label = false;
            foreach ($thread->labels as $label) {
                if ($label == 'uklassifisert-epost') {
                    $found_label = true;
                }
            }
            if (!$found_label) {
                $thread->labels[] = 'uklassifisert-epost';
            }
        }

        logDebug('Writing email to [' . $email_json_file . ']');

        $content = json_encode($obj, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE);
        if (json_last_error() != JSON_ERROR_NONE) {
            var_dump($obj);
            throw new Exception('Unable to read JSON.');
        }

        file_put_contents($email_json_file, $content);
    }
}

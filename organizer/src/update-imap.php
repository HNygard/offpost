<?php /** @noinspection ALL */

require_once __DIR__ . '/class/Threads.php';

// sudo apt-get install php5-imap
// + enable imap in PHP

echo '<pre>';

require_once __DIR__ . '/imap-connection.php';
$mailbox = openConnection();


echo '---- EXISTING FOLDERS ----' . chr(10);
$list = imap_list($mailbox, $server, "*");
$list_subscribed = imap_lsub($mailbox, $server, '*');

asort($list);
$folders = array();
foreach ($list as $folder) {
    echo '-- ' . $folder . chr(10);
    $folders[$folder] = $folder;
}

$folders_subscribed = array();
foreach ($list_subscribed as $folder) {
    $folders_subscribed[$folder] = $folder;
}

echo chr(10) . '---- CREATING FOLDERS ----' . chr(10);
$threads = getThreads();
$folder_that_should_exist = array('INBOX.Archive');
foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $folder_that_should_exist[] = getThreadEmailFolder($entity_threads, $thread);
    }
}
foreach ($folder_that_should_exist as $title) {
    echo '-- ' . $title . '        ';
    if (str_contains($title, 'INBOX.Archive.')) {
        echo '[ARCHIVED]' . chr(10);
    }
    else {
        if (isset($folders[$server . $title])) {
            echo '[OK]' . chr(10);
        }
        else {
            imap_createmailbox($mailbox, imap_utf7_encode($server . $title));
            checkForImapError();
            echo '[CREATED]' . chr(10);
        }
    }
}

echo chr(10) . '---- ARCHIVING FOLDERS ----' . chr(10);
$email_to_folder = array();
function getThreadEmailFolder($entity_threads, $thread) {
    $title = $entity_threads->title_prefix . ' - ' . $thread->title;
    return $thread->archived
        ? 'INBOX.Archive.' . $title
        : 'INBOX.' . $title;
}

foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $title = $entity_threads->title_prefix . ' - ' . $thread->title;
        $email_to_folder[$thread->my_email] = getThreadEmailFolder($entity_threads, $thread);
        if ($thread->archived) {
            echo '-- ' . $title . '        ';
            if (isset($folders[$server . 'INBOX.' . str_replace('INBOX.Archive.', '', $title)])) {
                // -> Exists and should be moved
                imap_renamemailbox(
                    $mailbox,
                    imap_utf7_encode($server . 'INBOX.' . $title),
                    imap_utf7_encode($server . 'INBOX.Archive.' . $title)
                );
                checkForImapError();
                echo '[ARCHIVED]' . chr(10);
            }
            elseif (isset($folders[$server . 'INBOX.Archive.' . $title])) {
                echo '[OK]' . chr(10);
            }
            else {
                // -> Don't exist
                echo '[N/A]' . chr(10);
            }
        }
    }
}

echo chr(10) . '---- SUBSCRIBE TO FOLDERS ----' . chr(10);
echo '(only listing new subscriptions)' . chr(10);
foreach ($folder_that_should_exist as $title) {
    if (!isset($folders_subscribed[$server . $title])) {
        echo '-- ' . $title . '        ';
        imap_subscribe($mailbox, imap_utf7_encode($server . $title));
        checkForImapError();
        echo '[SUBSCRIBED]' . chr(10);
    }
}

echo chr(10) . '---- SEARCH EMAILS ----' . chr(10);

echo '-- INBOX' . chr(10);
moveEmails($mailbox);
imap_close($mailbox, CL_EXPUNGE);

echo '-- INBOX.Sent' . chr(10);
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
        foreach ($mail_headers->to as $email) {
            $to_from[] = $email->mailbox . '@' . $email->host;
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
                echo 'FOUND : ' . $email_to_folder[$email] . chr(10);
                $should_be_moved_to = $email_to_folder[$email];
            }
        }

        $from = $mail_headers->from[0]->mailbox . '@' . $mail_headers->from[0]->host;

        echo 'FROM ........... : ' . $from . chr(10);
        echo 'SUBJECT ........ : ' . $subject . chr(10);
        echo 'MOVE TO ........ : ' . $should_be_moved_to . chr(10);
        if ($should_be_moved_to == 'INBOX') {
            foreach ($to_from as $email) {
                echo '- <a href="start-thread.php?my_email=' . urlencode($email) . '">Start thread with ' . htmlescape($email) .'</a>'. chr(10);
            }
        }
        echo chr(10);

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


echo chr(10) . '---- SAVE EMAILS ----' . chr(10);

foreach ($threads as $thread_file => $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $folder = getThreadEmailFolder($entity_threads, $thread);
        echo '-- ' . $folder . chr(10);
        $folderJson = '/organizer-data/threads/' . $entity_threads->entity_id . '/' . str_replace(' ', '_', strtolower($thread->title));
        if (!file_exists($folderJson)) {
            mkdir($folderJson, 0777, true);
        }
        echo '   Folder ... : ' . $folderJson . chr(10);

        $mailboxThread = openConnection($folder);
        saveEmails($mailboxThread, $folderJson, $thread);
        echo chr(10);
    }
    file_put_contents($thread_file, json_encode($entity_threads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));
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

        $in_or_out = $mail_headers->from[0]->mailbox . '@' . $mail_headers->from[0]->host == $thread->my_email
            ? 'OUT'
            : 'IN';
        $datetime = date('Y-m-d_His', strtotime($mail_headers->date));

        $file_name = $datetime . ' - ' . $in_or_out;

        if (isset($email_datetime[$datetime])) {
            throw new Exception('Double.');
        }
        $email_datetime[$datetime] = $datetime;

        $obj = new stdClass();

        $obj->subject = $mail_headers->subject;
        unset($mail_headers->subject);

        $obj->timestamp = strtotime($mail_headers->date);
        $obj->date = $mail_headers->date;
        unset($mail_headers->date);

        $mail_headers->toaddress = imap_utf8($mail_headers->toaddress);
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

        $obj->attachements = array();

        $obj->body = imap_body($mailbox, $mail, FT_UID);
        checkForImapError();

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
                        if (strtolower($object->attribute) == 'filename') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['filename'] = $object->value;
                        }
                    }
                }

                if ($structure->parts[$i]->ifparameters) {
                    foreach ($structure->parts[$i]->parameters as $object) {
                        if (strtolower($object->attribute) == 'name') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['name'] = $object->value;
                        }
                    }
                }

                if ($attachments[$i]['is_attachment']) {
                    $att = new stdClass();
                    $att->name = $attachments[$i]['name'];
                    $att->filename = $attachments[$i]['filename'];

                    if (str_ends_with($att->name, '.pdf')) {
                        $att->filetype = 'pdf';
                    }
                    else {
                        throw new Exception('Unknown file type: ' . $att->name);
                    }

                    // Don't include the name, since we have no control over it.
                    $att->location = $file_name . ' - att ' . $i . '-' . md5($att->name) . '.' . $att->filetype;
                    $obj->attachements[] = $att;

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


        $email_json_file = $folderJson . '/' . $file_name . '.json';
        file_put_contents($email_json_file, json_encode($obj, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE));
        chmod($email_json_file, 0777);

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
            $new_email->datetime_received = date('Y-m-d H:i:s');
            $new_email->id = $file_name;
            $new_email->email_type = $in_or_out;
            $new_email->status_type = 'unknown';
            $new_email->status_text = 'Uklassifisert';

            // TODO: Handle auto reply
            $new_email->ignore = false;

            $thread->emails[] = $new_email;

            usort($thread->emails, function($a, $b) {
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
    }
}

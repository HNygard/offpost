<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';

class ImapWrapperUtf7EncodeTest extends TestCase
{
    public function testUtf7EncodeHandlesAsciiFolderName(): void
    {
        // :: Setup
        $wrapper = new ImapWrapper(false);

        // :: Act
        $result = $wrapper->utf7Encode('INBOX.986965610-helfo');

        // :: Assert
        $this->assertEquals('INBOX.986965610-helfo', $result);
    }

    public function testUtf7EncodeProducesValidModifiedUtf7ForEnDash(): void
    {
        // :: Setup
        // "–" (U+2013 EN DASH) is what norske-postlister.no sends in thread
        // titles (e.g. "uke 38 2026 – Helfo"). imap_utf7_encode() treats its
        // input as ISO-8859-1 (one byte = one char), so the 3-byte UTF-8
        // sequence for this character used to be mangled into the invalid
        // mUTF-7 sequence "&4oCT-", which one.com's IMAP server rejected with
        // "Mailbox name is not valid mUTF-7".
        $wrapper = new ImapWrapper(false);
        $title = "uke 38 2026 \u{2013} Helfo";

        // :: Act
        $result = $wrapper->utf7Encode($title);

        // :: Assert
        $this->assertEquals('uke 38 2026 &IBM- Helfo', $result, $result);
    }

    public function testUtf7DecodeIsTheInverseOfUtf7Encode(): void
    {
        // :: Setup
        $wrapper = new ImapWrapper(false);
        $title = "uke 38 2026 \u{2013} Helfo";

        // :: Act
        $result = $wrapper->utf7Decode($wrapper->utf7Encode($title));

        // :: Assert
        $this->assertEquals($title, $result, $result);
    }
}

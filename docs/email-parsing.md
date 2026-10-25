# Email parsing

Offpost reads incoming email from the raw EML stored in `thread_emails.content`.
Email parsing has had many production bugs (malformed encoded-words, raw UTF-8 in
headers, Exchange quirks, DKIM headers, invalid addresses), so changes to it are
test-driven and checked against real mail before they reach production.

## What we read from an EML

`EmailParseSnapshot::fromEml()` (`organizer/src/class/Extraction/EmailParseSnapshot.php`)
collects everything Offpost reads through the mail parser library, by calling the
production code paths unchanged:

| Field | Production code | Used for |
|---|---|---|
| `subject` | `ImapEmail::getEmailSubject()` | `file.php` view; same decoding as `getEmailSubjectFromImapHeaders()`, which feeds lists, NP API, export and the classification rules |
| `x_forwarded_for` | `ImapEmail::getEmailAddresses($rawEmail)` (parser part only) | Matching BCC'ed email to its thread |
| `plain_text`, `html` | `ThreadEmailExtractorEmailBody::extractContentFromEmail()` | Email body extraction for AI summary and classification |
| `parse_error` | the above | `"Class: message"` when extraction failed; `"UNCAUGHT Class: message"` when the exception escaped extraction |

Strings that are not valid UTF-8 are stored as `{"invalid_utf8_base64": "..."}` so
the bytes survive JSON.

Not covered: attachments and the IMAP header objects. Those come from the PHP
`imap` extension, not from the parser library.

## Fixture snapshots

`organizer/src/tests/fixtures/email-parsing/` holds EML files with their recorded
snapshot (`.json`). `tests/Extraction/EmailParseSnapshotTest.php` fails when any
field changes, and the diff shows which field of which email changed.

Sources of the fixtures:
- `bug*`, `subject-*`, `simple-*`, `headers-only-*`, `imap-subject-*`: the exact
  inputs of the existing parsing tests, including one per historical bug fix
  (issue numbers in the name where known)
- `real-*`: the real emails from `data/test-emails/`
- `body-*`, `headers-*`, the rest of `subject-*`: hand-made cases for body
  extraction (charsets, transfer encodings, MIME structures) and header edge cases

Record a snapshot only for a new fixture, or after reviewing that a changed result
is intended:

    php bin/email-parse-snapshot.php tests/fixtures/email-parsing/foo.eml           # show
    php bin/email-parse-snapshot.php --record tests/fixtures/email-parsing/foo.eml  # write foo.json

## Replacement parser (not in production yet)

laminas-mail and laminas-mime are abandoned and cap PHP at 8.3. Their
replacement is `MimeEmailReader` (`class/Extraction/MimeEmailReader.php`), built
on `zbateson/mail-mime-parser`. It is not called by production code yet; it runs
side by side with the current parsing so both can be compared.

- zbateson is lenient, so the laminas strictness workarounds (sanitising raw
  bytes in headers, stripping DKIM/ARC headers, the address-header fallback and
  retries) are not used.
- The encoded-word repairs are kept (`fixMalformedEncodedWords()`: glued header
  names, Exchange lone-`?` terminators, nested words, UTF-8 bytes in iso-8859-1
  words). They fix input that is malformed for any parser.
- Body text: with no charset or a single-byte one (iso-8859-*, windows-125*,
  us-ascii, utf-8) declared, transfer-decoded bytes that are valid UTF-8 are kept
  as UTF-8 (mislabelled UTF-8 is common). Other declared charsets (UTF-16,
  ISO-2022-JP, ...) are always decoded, since their bytes can pass as UTF-8.
  Otherwise the declared charset is used, and Windows-1252 when none is declared.
- The first inline `text/plain` / `text/html` part is used, not the last part of
  that type, so text attachments are no longer taken as the body.

`EmailParseSnapshot::fromEml($eml, EmailParseSnapshot::PARSER_MIME)` gives the
same snapshot with the replacement parser. `MimeEmailReaderSnapshotTest` requires
it to equal the fixture's `.json`, or its `.new.json` where the difference is
deliberate. Every `.new.json` is a reviewed behaviour change:

| Difference | Fixtures |
|---|---|
| Single-part quoted-printable/base64 bodies decoded | `body-plain-iso88591-quoted-printable`, `body-plain-utf8-base64`, `body-plain-utf8-quoted-printable-soft-breaks` |
| Declared Windows-1252 kept (`–` no longer lost) | `body-plain-windows1252-8bit` |
| Declared multi-byte charsets decoded (UTF-16, ISO-2022-JP) | `body-plain-utf16le-base64`, `body-plain-iso2022jp-7bit` |
| Single-part HTML read as HTML, not raw markup in `plain_text` | `body-html-only-single-part` |
| Content types case-insensitive | `body-uppercase-content-type-and-encoding` |
| A `text/plain` attachment is not taken as the body | `body-mixed-text-attachment-after-body`, `real-bcc-with-x-forwarded-for-header` |
| Invalid To/Cc address no longer makes the email unreadable | `bug-invalid-address-header`, `headers-invalid-cc-address`, `bug152-malformed-header-body-separation` |
| No exception for header-only input or missing boundary | `headers-only-partial-eml`, `imap-subject-*`, `body-multipart-without-boundary` |
| All X-Forwarded-For headers, comma separated addresses split (not inside a quoted display name) | `headers-multiple-x-forwarded-for`, `headers-x-forwarded-for-two-in-one-header`, `headers-x-forwarded-for-quoted-comma-display-name` |
| Raw 8-bit subject decoded instead of a broken encoded-word | `subject-raw-latin1-no-encoding`, `bug145-raw-non-ascii-subject` |
| Missing subject is `""`, not an error text | `subject-missing-header`, `garbage-raw-text`, `not-an-email` |
| Text without any header is not taken as body | `garbage-raw-text`, `not-an-email` |
| HTML text ends with one newline less: the line break before a MIME boundary belongs to the boundary (RFC 2046), laminas kept it | `body-alternative-outlook-iso88591-qp`, `body-html-charset-only-in-meta`, `body-mixed-alternative-with-pdf-attachment`, `body-preamble-and-epilogue`, `body-related-html-inline-image`, `real-attachment-with-strange-characters`, `real-bcc-with-x-forwarded-for-header` |

To (re)record the differences after a reviewed change:

    php bin/email-parse-snapshot.php --parser=mime --record tests/fixtures/email-parsing/*.eml

This writes a `.new.json` only where the result differs from the `.json`, and
removes it where it no longer does.

## Comparing on production mail

The fixtures cannot hold every oddity real senders produce, so before the
replacement is switched on it is compared on all stored emails:

    php bin/email-parse-compare.php                       # all emails, summary
    php bin/email-parse-compare.php --since=2025-01-01 --limit=1000
    php bin/email-parse-compare.php --show=<email_id>     # old vs new for one email
    php bin/email-parse-compare.php --export-eml=<email_id> --out=/tmp/x.eml

Run it in the organizer container. It only reads (SELECT); nothing is written to
the database. The summary contains counts and email ids, no email content.
`--show` prints one email's parsed content to the console and `--export-eml`
writes its raw EML to a file - anonymise that before adding it as a fixture.
The current extractor logs whole EMLs on parse failures, so the script sends
`error_log()` output to `/dev/null` (its own errors still go to stderr).

Each field (`subject`, `x_forwarded_for`, `plain_text`, `html`, and
`imap_subject` = the subject stored in `imap_headers`, which feeds lists, export
and the classification rules) gets one category (`EmailParseComparison`):

| Category | Meaning |
|---|---|
| `equal` | Same value |
| `whitespace_only` | Same text when whitespace runs are collapsed |
| `changed` | Different value - inspect with `--show` |
| `old_failed_new_ok` | Current parser failed (error subject, X-Forwarded-For exception, parse error); replacement read it |
| `new_failed` | Replacement failed - a regression, listed first in the report |

Every `changed` and `new_failed` case is reviewed before the switch. A case
that shows a new kind of problem becomes a fixture.

## Known problems in the current (laminas) parsing

The snapshots pin today's behaviour, including these problems. They are listed so
that a parser change that fixes one is recognised as a fix, not a regression.

Body extraction:
- Single-part bodies with `quoted-printable` or `base64` transfer encoding are not
  decoded (`body-plain-iso88591-quoted-printable`, `body-plain-utf8-base64`,
  `body-plain-utf8-quoted-printable-soft-breaks`)
- A single-part `text/html` email puts the raw HTML in `plain_text`
  (`body-html-only-single-part`)
- Declared multi-byte charsets are not decoded: UTF-16 comes out as base64,
  ISO-2022-JP as escape-sequence text (`body-plain-utf16le-base64`,
  `body-plain-iso2022jp-7bit`)
- Content types are compared case-sensitively: `TEXT/PLAIN` gives empty text
  (`body-uppercase-content-type-and-encoding`)
- The last `text/plain` part wins, so a `.txt` attachment replaces the body
  (`body-mixed-text-attachment-after-body`)
- The declared charset is ignored; ISO-8859-1 is tried first, so Windows-1252
  characters such as `–` disappear (`body-plain-windows1252-8bit`)
- A multipart email without boundary, or EML with headers only, throws out of
  extraction (`body-multipart-without-boundary`, `headers-only-partial-eml`)

Headers:
- One invalid address in To/Cc makes the whole email unparseable: no body, the
  subject becomes an error text (`bug-invalid-address-header`, `headers-invalid-cc-address`)
- Two `X-Forwarded-For` headers throw (the `ArrayIterator` check in namespace
  `Imap` never matches) (`headers-multiple-x-forwarded-for`)
- Two addresses in one `X-Forwarded-For` header stay one string
  (`headers-x-forwarded-for-quoted-comma-display-name` likewise)
  (`headers-x-forwarded-for-two-in-one-header`)

Subjects:
- Raw non-ASCII bytes in a subject become a literal broken encoded-word such as
  `=?UTF-8?Q?S=F8knad?=` (`subject-raw-latin1-no-encoding`, `bug145-raw-non-ascii-subject`)
- A missing Subject header gives the text
  `Error getting subject - Header with Name subject or subject not found`
  (`subject-missing-header`)

## Fixed

- A base64 encoded-word ending in `=` padding lost the subject text after it
  (`=?UTF-8?B?U8O4a25hZA==?= om innsyn` gave `Søknad`). The padding plus the
  closing `?=` read as the start of a nested encoded-word in
  `fixMalformedEncodedWords()`. Seen in real mail from Kristiansand kommune
  (`real-attachment-with-strange-characters`).

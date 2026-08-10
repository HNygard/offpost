# Thread-view Classification Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the classification type — not just its free-text description — on every email and attachment row in thread-view and on the front page, and report the true classification source.

**Architecture:** A single rendering helper, `renderClassification()` in `organizer/src/class/ThreadUtils.php`, becomes the only place that turns a `status_type` + `status_text` pair into HTML. Four duplicated blocks across `view-thread.php` and `index.php` call it. Separately, three thread loaders start mapping the `auto_classification` column they already read but drop, and the `uklassifisert-dok` placeholder is cleared when an attachment gets a real status type.

**Tech Stack:** PHP 8.3, PHPUnit 9, PostgreSQL, plain CSS. No new dependencies.

## Global Constraints

- The current working directory is the worktree root: `/home/hallvard/git/offpost/.claude/worktrees/thread-view-classifications`
- Run tests with `./run-tests-worktree.sh <path>` — the host PHP has no `mbstring` extension and the worktree is not mounted into the dev compose stack. Example: `./run-tests-worktree.sh /php-frontend/tests/ThreadUtilsRenderClassificationTest.php`. Paths passed to this script are **container** paths: `organizer/src` is mounted at `/php-frontend`.
- Test rules from CLAUDE.md apply to every test written here: no `markTestSkipped`, no random or time-based values, `assertEquals` with the full expected string wherever output is deterministic, and array-size assertions must dump the array with `json_encode($x, JSON_PRETTY_PRINT)`.
- Structure every test body with the `// :: Setup`, `// :: Act`, `// :: Assert` comment markers.
- Avoid unnecessary modifications that complicate review — no reformatting, no renaming beyond what a task specifies.
- Commit after each task with a message explaining what and why. Do not push.
- `htmlescape()` is `htmlentities($html, ENT_QUOTES)` and returns `''` for `null`. It converts non-ASCII to entities, so keep expected strings in tests ASCII-only.
- `ThreadEmailStatusType::label()` includes a group prefix: `From us: `, `From entity: `, `Legacy: `, and no prefix for `UNKNOWN`.

## Baseline

Before starting, confirm the baseline still holds:

- `./run-tests-worktree.sh /php-frontend/tests/` → 483 tests, 0 failures, 2 skipped.
- `./run-tests-worktree.sh /php-frontend/e2e-tests/` → 112 tests, 9 errors, 7 failures. **These 16 are pre-existing** — NP API and extraction-overview cases failing because `secrets/np_api_token` and `secrets/openai_api_key` do not exist, so Docker mounts directories in their place and the endpoints return 500. The same 16, and no others, must fail at the end.

The e2e suite drives `http://localhost:25081`, which the dev stack serves from the **main checkout**, not this worktree. E2E runs do not exercise these changes. Verification rests on the unit tests.

---

### Task 1: Placeholder constant and clearing rule

`ThreadEmailDatabaseSaver` stamps `status_text = 'uklassifisert-dok'` on every ingested attachment. The classify form pre-fills that string into its Status Text input, so it survives even after a real type is chosen, and thread-view — which shows only the text — keeps reading as unclassified. Put the string and the rule that clears it on the attachment class, where they can be unit-tested; the rule cannot be reached inside the `classify-email.php` page script.

**Files:**
- Modify: `organizer/src/class/ThreadEmailAttachment.php`
- Test: `organizer/src/tests/ThreadEmailAttachmentNormalizeStatusTextTest.php` (create)

**Interfaces:**
- Consumes: `App\Enums\ThreadEmailStatusType`
- Produces:
  - `ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT` — the string `'uklassifisert-dok'`
  - `ThreadEmailAttachment::normalizeStatusText($statusType, $statusText): string` — returns `''` when `$statusText` is exactly the placeholder and `$statusType` is anything other than `UNKNOWN`; returns `$statusText` unchanged otherwise. `$statusType` may be a `ThreadEmailStatusType` case or a raw string.

- [ ] **Step 1: Write the failing test**

Create `organizer/src/tests/ThreadEmailAttachmentNormalizeStatusTextTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';

/**
 * Ingest stamps every attachment with 'uklassifisert-dok'. The classify form
 * pre-fills it, so it used to survive a real classification and make a
 * classified attachment still read as unclassified.
 */
class ThreadEmailAttachmentNormalizeStatusTextTest extends TestCase {

    public function testPlaceholderIsClearedWhenTypeIsReal(): void {
        // :: Setup
        $placeholder = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $placeholder);

        // :: Assert
        $this->assertEquals('', $result);
    }

    public function testPlaceholderIsClearedWhenTypeIsRealAsRawString(): void {
        // :: Setup
        $placeholder = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText('INFORMATION_RELEASE', $placeholder);

        // :: Assert
        $this->assertEquals('', $result);
    }

    public function testPlaceholderIsKeptWhenTypeIsUnknownEnum(): void {
        // :: Setup
        $placeholder = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText(
            ThreadEmailStatusType::UNKNOWN, $placeholder);

        // :: Assert
        $this->assertEquals('uklassifisert-dok', $result);
    }

    public function testPlaceholderIsKeptWhenTypeIsUnknownString(): void {
        // :: Setup
        $placeholder = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText('unknown', $placeholder);

        // :: Assert
        $this->assertEquals('uklassifisert-dok', $result);
    }

    public function testRealTextIsUntouched(): void {
        // :: Setup
        $text = 'Svar med vedlegg';

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $text);

        // :: Assert
        $this->assertEquals('Svar med vedlegg', $result);
    }

    public function testEmptyTextIsUntouched(): void {
        // :: Setup
        $text = '';

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $text);

        // :: Assert
        $this->assertEquals('', $result);
    }

    public function testNullTextBecomesEmptyString(): void {
        // :: Setup
        $text = null;

        // :: Act
        $result = ThreadEmailAttachment::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $text);

        // :: Assert
        $this->assertEquals('', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadEmailAttachmentNormalizeStatusTextTest.php`

Expected: FAIL — `Undefined constant ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT`

- [ ] **Step 3: Write minimal implementation**

In `organizer/src/class/ThreadEmailAttachment.php`, add the enum import at the top of the file, immediately after `<?php`:

```php
<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
use App\Enums\ThreadEmailStatusType;
```

Then inside `class ThreadEmailAttachment`, add the constant above the existing properties and the method below the existing `getIconClass()`:

```php
    /**
     * Placeholder status_text stamped on every attachment at ingest time by
     * ThreadEmailDatabaseSaver. Marks an attachment as still needing
     * classification; cleared once a real status type is chosen.
     */
    const UNCLASSIFIED_STATUS_TEXT = 'uklassifisert-dok';
```

```php
    /**
     * Drop the ingest placeholder once the attachment has a real status type.
     * The classify form pre-fills the placeholder, so without this it survives
     * a classification and the attachment keeps reading as unclassified.
     *
     * @param ThreadEmailStatusType|string|null $statusType
     * @param string|null $statusText
     * @return string
     */
    public static function normalizeStatusText($statusType, $statusText) {
        $typeValue = $statusType instanceof ThreadEmailStatusType ? $statusType->value : $statusType;

        if ($statusText === self::UNCLASSIFIED_STATUS_TEXT
            && $typeValue !== ThreadEmailStatusType::UNKNOWN->value) {
            return '';
        }

        return $statusText === null ? '' : $statusText;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadEmailAttachmentNormalizeStatusTextTest.php`

Expected: PASS — 7 tests, 7 assertions

- [ ] **Step 5: Run the full unit suite to check nothing regressed**

Run: `./run-tests-worktree.sh /php-frontend/tests/`

Expected: 490 tests, 0 failures, 2 skipped

- [ ] **Step 6: Commit**

```bash
git add organizer/src/class/ThreadEmailAttachment.php organizer/src/tests/ThreadEmailAttachmentNormalizeStatusTextTest.php
git commit -m "Add attachment placeholder constant and clearing rule

Ingest stamps every attachment with status_text 'uklassifisert-dok' and
the classify form pre-fills it, so the placeholder survived a real
classification. Since thread-view renders only status_text, a classified
attachment kept reading as unclassified.

The rule lives on ThreadEmailAttachment rather than inline in
classify-email.php so it can be unit-tested - a page script cannot be
exercised from PHPUnit."
```

---

### Task 2: Add the missing `error` case to `getLabelType()`

`getLabelType()` throws on any status value outside its switch. `ThreadEmailStatusType::ERROR` (`'error'`) is a real enum case with no branch, so rendering an email classified as `error` throws today. Task 3's helper calls `getLabelType()` for every enum value, so this gap must close first.

**Files:**
- Modify: `organizer/src/class/ThreadUtils.php`
- Test: `organizer/src/tests/ThreadUtilsGetLabelTypeTest.php` (create)

**Interfaces:**
- Consumes: `getLabelType($type, $status_type_input)` — existing, returns a CSS class string
- Produces: `getLabelType('email', 'error')` returns `'label label_error'` instead of throwing

- [ ] **Step 1: Write the failing test**

Create `organizer/src/tests/ThreadUtilsGetLabelTypeTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadUtils.php';

/**
 * getLabelType() throws on values outside its switch. ThreadEmailStatusType::ERROR
 * is a real enum case that had no branch, so classifying an email as 'error'
 * crashed the page that rendered it.
 */
class ThreadUtilsGetLabelTypeTest extends TestCase {

    public function testEveryEnumCaseHasALabelType(): void {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = getLabelType('email', $case);
        }

        // :: Assert
        $this->assertCount(
            count($cases),
            $results,
            'Every enum case must map to a CSS class: ' . json_encode($results, JSON_PRETTY_PRINT)
        );
        foreach ($results as $value => $class) {
            $this->assertStringStartsWith('label', $class, "status_type $value");
        }
    }

    public function testErrorMapsToLabelError(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::ERROR;

        // :: Act
        $result = getLabelType('email', $statusType);

        // :: Assert
        $this->assertEquals('label label_error', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadUtilsGetLabelTypeTest.php`

Expected: FAIL — `Exception: Unknown status_type[email]: error`

- [ ] **Step 3: Write minimal implementation**

In `organizer/src/class/ThreadUtils.php`, inside the `switch` in `getLabelType()`, add a branch next to the other legacy string values — directly above the `case ThreadEmailStatusType::SUCCESS->value:` line:

```php
        case ThreadEmailStatusType::ERROR->value:
        case 'error': // explicit string check
            return 'label label_error';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadUtilsGetLabelTypeTest.php`

Expected: PASS — 2 tests

- [ ] **Step 5: Commit**

```bash
git add organizer/src/class/ThreadUtils.php organizer/src/tests/ThreadUtilsGetLabelTypeTest.php
git commit -m "Map the error status type to a CSS class

getLabelType() throws on anything outside its switch, and
ThreadEmailStatusType::ERROR had no branch - rendering an email
classified as 'error' crashed the page. The label_error CSS class
already existed."
```

---

### Task 3: The `renderClassification()` helper

The core of the change. Both thread-view and the front page print only `status_text`, so the classification itself — the thing the status type enum encodes — is never shown. One helper renders it, and becomes the only place that decides how a classification looks.

**Files:**
- Modify: `organizer/src/class/ThreadUtils.php`
- Test: `organizer/src/tests/ThreadUtilsRenderClassificationTest.php` (create)

**Interfaces:**
- Consumes: `getLabelType($type, $status_type_input)` from Task 2; `ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT` from Task 1; `htmlescape()` from `class/common.php`
- Produces: `renderClassification($status_type, $status_text): string` — returns the HTML for one classification. Used by Task 4.

Rules the implementation must satisfy:

1. `null` or `''` `status_type` is normalised to `'unknown'` before anything else, so it renders as `Unknown` rather than hitting `getLabelType()`'s throwing `default:` branch.
2. The chip's classes are `classification` followed by whatever `getLabelType()` returns.
3. The chip's text is `ThreadEmailStatusType::tryFrom($value)->label()`, falling back to the raw `$value` when `tryFrom` returns null (the legacy `disabled`, `danger` and caps-`UNKNOWN` values that `getLabelType()` accepts but the enum does not).
4. `status_text` is appended in a `status-text` span, and omitted when empty, when equal to the chip's text, or when equal to `ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT`.
5. Both parts are escaped with `htmlescape()`. The comparison in rule 4 happens on the **unescaped** text.

- [ ] **Step 1: Write the failing test**

Create `organizer/src/tests/ThreadUtilsRenderClassificationTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadUtils.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';

/**
 * thread-view and the front page used to print only the free-text status_text,
 * so the classification itself was never visible. renderClassification() is now
 * the single place that turns a status type + text pair into HTML.
 */
class ThreadUtilsRenderClassificationTest extends TestCase {

    public function testInformationReleaseWithText(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = 'Svar med vedlegg';

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>'
            . ' <span class="status-text">Svar med vedlegg</span>',
            $result
        );
    }

    public function testOurRequestWithoutText(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::OUR_REQUEST;

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_our_request">From us: Our Request</span>',
            $result
        );
    }

    public function testRequestReceipt(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::REQUEST_RECEIPT;

        // :: Act
        $result = renderClassification($statusType, null);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_request_receipt">'
            . 'From entity: Receipt of Request</span>',
            $result
        );
    }

    public function testRequestRejected(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::REQUEST_REJECTED;

        // :: Act
        $result = renderClassification($statusType, 'Avslag');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_request_rejected label_warn">'
            . 'From entity: Request Rejected</span>'
            . ' <span class="status-text">Avslag</span>',
            $result
        );
    }

    public function testEnumCaseAndRawStringRenderIdentically(): void {
        // :: Setup
        $statusText = 'Svar med vedlegg';

        // :: Act
        $fromCase = renderClassification(ThreadEmailStatusType::RESPONSE_TO_REQUEST, $statusText);
        $fromString = renderClassification('RESPONSE_TO_REQUEST', $statusText);

        // :: Assert
        $this->assertEquals($fromCase, $fromString);
        $this->assertEquals(
            '<span class="classification label label_response_to_request">'
            . 'From entity: Response to Request</span>'
            . ' <span class="status-text">Svar med vedlegg</span>',
            $fromCase
        );
    }

    public function testUnknownStatusType(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::UNKNOWN;

        // :: Act
        $result = renderClassification($statusType, 'Uklassifisert');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label">Unknown</span>'
            . ' <span class="status-text">Uklassifisert</span>',
            $result
        );
    }

    public function testNullStatusTypeRendersAsUnknown(): void {
        // :: Setup
        $statusType = null;

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals('<span class="classification label">Unknown</span>', $result);
    }

    public function testEmptyStatusTypeRendersAsUnknown(): void {
        // :: Setup
        $statusType = '';

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals('<span class="classification label">Unknown</span>', $result);
    }

    public function testLegacyDisabledValueRendersVerbatim(): void {
        // :: Setup
        $statusType = 'disabled';

        // :: Act
        $result = renderClassification($statusType, 'Autosvar');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_disabled">disabled</span>'
            . ' <span class="status-text">Autosvar</span>',
            $result
        );
    }

    public function testLegacyDangerValueRendersVerbatim(): void {
        // :: Setup
        $statusType = 'danger';

        // :: Act
        $result = renderClassification($statusType, 'Avslag');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_warn">danger</span>'
            . ' <span class="status-text">Avslag</span>',
            $result
        );
    }

    public function testLegacyUppercaseUnknownRendersVerbatim(): void {
        // :: Setup
        $statusType = 'UNKNOWN';

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals('<span class="classification label">UNKNOWN</span>', $result);
    }

    public function testPlaceholderStatusTextIsSuppressed(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>',
            $result
        );
    }

    public function testStatusTextEqualToLabelIsSuppressed(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = 'From entity: Information Release';

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>',
            $result
        );
    }

    public function testStatusTextIsEscaped(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = '<script>alert("x")</script> & more';

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>'
            . ' <span class="status-text">'
            . '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; more'
            . '</span>',
            $result
        );
    }

    public function testEveryEnumCaseRenders(): void {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act
        $rendered = [];
        foreach ($cases as $case) {
            $rendered[$case->value] = renderClassification($case, '');
        }

        // :: Assert
        $this->assertCount(
            count($cases),
            $rendered,
            'Every enum case must render: ' . json_encode($rendered, JSON_PRETTY_PRINT)
        );
        foreach ($rendered as $value => $html) {
            $this->assertStringContainsString(
                htmlescape(ThreadEmailStatusType::from($value)->label()),
                $html,
                "status_type $value must show its label"
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadUtilsRenderClassificationTest.php`

Expected: FAIL — `Error: Call to undefined function renderClassification()`

- [ ] **Step 3: Write minimal implementation**

In `organizer/src/class/ThreadUtils.php`, add the require and use for the attachment class at the top, alongside the existing ones:

```php
require_once __DIR__ . '/ThreadEmailAttachment.php';
```

Then add the function directly below the closing brace of `getLabelType()`:

```php
/**
 * Render one classification - the status type and, when it adds anything, its
 * free-text description.
 *
 * The status type is the classification; status_text is only a human note
 * beside it. Pages used to print the text alone, which left the classification
 * invisible and made attachments still carrying the ingest placeholder look
 * unclassified after they had been classified.
 *
 * @param ThreadEmailStatusType|string|null $status_type
 * @param string|null $status_text
 * @return string HTML
 */
function renderClassification($status_type, $status_text) {
    $type_value = $status_type instanceof ThreadEmailStatusType ? $status_type->value : $status_type;

    // Normalise before getLabelType(), whose default branch throws.
    if ($type_value === null || $type_value === '') {
        $type_value = ThreadEmailStatusType::UNKNOWN->value;
    }

    $label_type = getLabelType('classification', $type_value);

    // Legacy values such as 'disabled' and 'danger' are accepted by
    // getLabelType() but are not enum cases - show them rather than swallow them.
    $case = ThreadEmailStatusType::tryFrom($type_value);
    $label_text = $case !== null ? $case->label() : $type_value;

    $html = '<span class="classification ' . $label_type . '">' . htmlescape($label_text) . '</span>';

    if ($status_text !== null
        && $status_text !== ''
        && $status_text !== $label_text
        && $status_text !== ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT) {
        $html .= ' <span class="status-text">' . htmlescape($status_text) . '</span>';
    }

    return $html;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadUtilsRenderClassificationTest.php`

Expected: PASS — 15 tests

- [ ] **Step 5: Run the full unit suite**

Run: `./run-tests-worktree.sh /php-frontend/tests/`

Expected: 507 tests, 0 failures, 2 skipped

- [ ] **Step 6: Commit**

```bash
git add organizer/src/class/ThreadUtils.php organizer/src/tests/ThreadUtilsRenderClassificationTest.php
git commit -m "Add renderClassification() helper

The status type is the classification; status_text is only a note beside
it. thread-view and the front page printed the text alone, so the
classification was never visible and an attachment still carrying the
ingest placeholder read as unclassified after being classified.

One helper now owns how a classification looks, so the two pages cannot
drift apart again."
```

---

### Task 4: Style the classification chip

`style.css` puts the chip box on `span.label a` — background, border, padding and radius all live on the anchor. These classification labels contain no link, so they render as unstyled plain text. On top of that, none of `label_our_request`, `label_request_receipt`, `label_asking_for_*`, `label_clarification_sent`, `label_copy_sent`, `label_response_to_request`, `label_request_rejected` or `label_information_release` has a rule anywhere in the stylesheet. Without this task the classification is present in the markup but reads as bare text.

**Files:**
- Modify: `organizer/src/webroot/css/style.css`

**Interfaces:**
- Consumes: the `classification`, `status-text` and `label_*` classes emitted by `renderClassification()` in Task 3
- Produces: no PHP interface

There is no CSS test harness in this repo, so this task is verified by reading the rendered page in Task 5's manual check.

- [ ] **Step 1: Add the chip styles**

In `organizer/src/webroot/css/style.css`, append after the `span.label.label_disabled a` rule (the last of the `span.label ... a` colour rules, around line 196):

```css
/*
 * Classification chips. Unlike the label chips above, these contain no link -
 * the box has to live on the span itself. Emitted by renderClassification().
 */
span.label.classification {
    background-color: #e9ecef;
    border: 1px solid #adb5bd;
    border-radius: 4px;
    padding: 4px 10px;
    cursor: default;
}

span.label.classification:hover {
    transform: none;
}

span.label.classification.label_our_request,
span.label.classification.label_clarification_sent,
span.label.classification.label_copy_sent {
    background-color: #cfe0ff;
    border-color: #3f4b65;
}

span.label.classification.label_request_receipt {
    background-color: #e2e8f5;
    border-color: #6b7a99;
}

span.label.classification.label_asking_for_more_time,
span.label.classification.label_asking_for_copy,
span.label.classification.label_asking_for_clarification {
    background-color: #ffe1b8;
    border-color: #b3792a;
}

span.label.classification.label_response_to_request {
    background-color: #bfe9d8;
    border-color: #2f7d5f;
}

span.label.classification.label_information_release {
    background-color: #83f883;
    border-color: #4cae4c;
}

span.label.classification.label_request_rejected {
    background-color: #f8ab69;
    border-color: #724f30;
}

span.label.classification.label_error {
    background-color: #f88383;
    border-color: #c0392b;
}

.status-text {
    color: #6c757d;
    font-size: 0.9em;
}
```

- [ ] **Step 2: Verify no existing rule was changed**

Run: `git --no-pager diff organizer/src/webroot/css/style.css`

Expected: additions only — no deletions, no modified lines. If any existing line shows as changed, revert it.

- [ ] **Step 3: Commit**

```bash
git add organizer/src/webroot/css/style.css
git commit -m "Style the classification chip

style.css hangs the chip box off 'span.label a', so a label with no link
inside it renders as unstyled text - and none of the per-status label_*
classes had any rule at all. The classification chip has never actually
looked like a chip.

Colours follow the status groups: blue for what we sent, amber while the
entity is asking for something, green for a release, red for a rejection."
```

---

### Task 5: Wire the helper into both pages

Four duplicated blocks print `status_text` alone. Replace all four with the helper. `index.php` also echoes both `status_text` values without escaping — routing them through the helper closes that.

**Files:**
- Modify: `organizer/src/view-thread.php` (email row ~line 434, attachment row ~line 500)
- Modify: `organizer/src/index.php` (email row ~line 369, attachment row ~line 388)

**Interfaces:**
- Consumes: `renderClassification($status_type, $status_text)` from Task 3
- Produces: no PHP interface

- [ ] **Step 1: Replace the email row in view-thread.php**

Find, inside the `foreach ($thread->emails as $email)` loop:

```php
                $label_type = getLabelType('email', $email->status_type);

                $extractions = $extraction_service->getExtractionsForEmail($email->id);
```

Replace with:

```php
                $extractions = $extraction_service->getExtractionsForEmail($email->id);
```

Then find:

```php
                        <span class="<?= $label_type ?>"><?= htmlescape($email->status_text) ?></span>
```

Replace with:

```php
                        <?= renderClassification($email->status_type, $email->status_text) ?>
```

- [ ] **Step 2: Replace the attachment row in view-thread.php**

Find, inside the `foreach ($email->attachments as $att)` loop:

```php
                                    $label_type = getLabelType('attachement', $att->status_type);
                                    $iconClass = getIconClass($att->filetype);
```

Replace with:

```php
                                    $iconClass = getIconClass($att->filetype);
```

Then find:

```php
                                    <span class="<?= $label_type ?>"><?= htmlescape($att->status_text) ?></span>
```

Replace with:

```php
                                    <?= renderClassification($att->status_type, $att->status_text) ?>
```

- [ ] **Step 3: Replace the email row in index.php**

Find:

```php
                                $label_type = getLabelType('email', $email->status_type);
                                ?>
```

Replace with:

```php
                                ?>
```

Then find:

```php
                                    <span class="<?= $label_type ?>"><?= $email->status_text ?></span>
```

Replace with:

```php
                                    <?= renderClassification($email->status_type, $email->status_text) ?>
```

- [ ] **Step 4: Replace the attachment row in index.php**

Find:

```php
                                            $label_type = getLabelType('attachement', $att->status_type);
                                            echo chr(10);
```

Replace with:

```php
                                            echo chr(10);
```

Then find:

```php
                                                <span class="<?= $label_type ?>"><?= $att->status_text ?></span>
```

Replace with:

```php
                                                <?= renderClassification($att->status_type, $att->status_text) ?>
```

- [ ] **Step 5: Check for syntax errors and leftover references**

Run:

```bash
docker run --rm -v "$PWD/organizer/src:/php-frontend" --entrypoint php offpost-organizer -l /php-frontend/view-thread.php
docker run --rm -v "$PWD/organizer/src:/php-frontend" --entrypoint php offpost-organizer -l /php-frontend/index.php
grep -n 'label_type' organizer/src/view-thread.php organizer/src/index.php
```

Expected: `No syntax errors detected` twice, and the grep prints nothing. If the grep prints a line, an assignment or use was missed.

- [ ] **Step 6: Verify in the browser**

The dev stack serves the main checkout, so start a container on this worktree's sources on a spare port:

```bash
docker run -d --rm --name offpost-worktree-view --network host \
    -v "$PWD/organizer/src:/php-frontend" \
    -v "$PWD/organizer/src/username-password-override-dev.php:/username-password-override.php" \
    -v "$PWD/data:/organizer-data" \
    -v "$PWD/secrets/postgres_password:/run/secrets/postgres_password:ro" \
    -e ENVIRONMENT=development -e DB_HOST=127.0.0.1 -e DB_PORT=25432 \
    -e DB_NAME=offpost -e DB_USER=offpost \
    -e DB_PASSWORD_FILE=/run/secrets/postgres_password \
    offpost-organizer
```

Note: the image serves on port 80 and `--network host` binds it directly, so this conflicts with anything already on port 80. If that port is taken, drop `--network host`, add `-p 25082:80 --network offpost_app-network`, and set `DB_HOST=postgres DB_PORT=5432`.

Fetch a thread page and confirm a classification chip is present:

```bash
curl -s 'http://localhost/?test-authenticate' -c /tmp/offpost-cookies.txt -o /dev/null
curl -s -b /tmp/offpost-cookies.txt 'http://localhost/' | grep -o 'class="classification[^"]*"[^<]*<' | head
```

Expected: at least one `class="classification label ..."` match with a label such as `From entity: Information Release` after it.

Stop the container when done:

```bash
docker stop offpost-worktree-view
```

- [ ] **Step 7: Run the full unit suite**

Run: `./run-tests-worktree.sh /php-frontend/tests/`

Expected: 507 tests, 0 failures, 2 skipped

- [ ] **Step 8: Commit**

```bash
git add organizer/src/view-thread.php organizer/src/index.php
git commit -m "Show the classification on thread-view and the front page

Four duplicated blocks printed status_text alone, so an email or
attachment showed a free-text note and no indication of what it had
actually been classified as. All four now call renderClassification().

index.php also echoed both status_text values unescaped; routing them
through the helper escapes them."
```

---

### Task 6: Load `auto_classification`

`ThreadEmailClassifier::getClassificationLabel()` distinguishes human, code (`algo`) and AI (`prompt`) classification through the `auto_classification` column. No loader maps it onto the `ThreadEmail` object, even though `ThreadEmail` declares the property and `Thread::mapFromDatabase()` already selects it with `SELECT *`. `isset()` is therefore always false and every classified email claims "Classified by Human" — on thread-view, on the front page and on the classify page.

**Files:**
- Modify: `organizer/src/class/ThreadDatabaseOperations.php` (`getThreads()` SELECT ~line 51 and mapping ~line 150; `getThreadsForEntity()` SELECT ~line 205 and mapping ~line 281)
- Modify: `organizer/src/class/Thread.php` (`mapFromDatabase()` email mapping ~line 203)
- Test: `organizer/src/tests/ThreadAutoClassificationLoadingTest.php` (create)

**Interfaces:**
- Consumes: `NpApiService::createThread()`, `Database`, `ThreadStorageManager` — all existing
- Produces: `ThreadEmail::$auto_classification` populated by `Thread::loadFromDatabase()`, `ThreadDatabaseOperations::getThreads()` and `ThreadDatabaseOperations::getThreadsForEntity()`

- [ ] **Step 1: Write the failing test**

Create `organizer/src/tests/ThreadAutoClassificationLoadingTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';
require_once __DIR__ . '/../class/ThreadDatabaseOperations.php';
require_once __DIR__ . '/../class/ThreadEmailClassifier.php';

/**
 * Regression: no loader mapped the auto_classification column onto ThreadEmail,
 * so getClassificationLabel() saw an unset property and reported every
 * classified email as "Classified by Human", whatever had classified it.
 */
class ThreadAutoClassificationLoadingTest extends TestCase {
    private $threadId;
    private $emailId;

    protected function setUp(): void {
        Database::beginTransaction();

        $created = NpApiService::createThread('9999-test-entity-development', 'T', 'B',
            ['norske_postlister_no', 'document', 'document_id:2030-1-2']);
        $this->threadId = $created['thread_id'];

        $this->emailId = Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type,
                 status_type, status_text, auto_classification, content, imap_headers)
             VALUES (?, now(), now(), 'IN', 'INFORMATION_RELEASE', 'Svar', 'prompt', ?::bytea, NULL)
             RETURNING id",
            [$this->threadId, 'content']
        );
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function findEmail($thread) {
        foreach ($thread->emails as $email) {
            if ($email->id === $this->emailId) {
                return $email;
            }
        }
        $this->fail('Email ' . $this->emailId . ' not found in loaded thread');
    }

    public function testLoadFromDatabaseMapsAutoClassification(): void {
        // :: Setup
        $threadId = $this->threadId;

        // :: Act
        $thread = Thread::loadFromDatabase($threadId);
        $email = $this->findEmail($thread);

        // :: Assert
        $this->assertEquals('prompt', $email->auto_classification);
    }

    public function testGetThreadsForEntityMapsAutoClassification(): void {
        // :: Setup
        $operations = new ThreadDatabaseOperations();

        // :: Act
        $threadsByFile = $operations->getThreadsForEntity('9999-test-entity-development');
        $email = null;
        foreach ($threadsByFile as $threads) {
            foreach ($threads->threads as $thread) {
                if ($thread->id === $this->threadId) {
                    $email = $this->findEmail($thread);
                }
            }
        }

        // :: Assert
        $this->assertNotNull($email, 'Thread ' . $this->threadId . ' not found for entity');
        $this->assertEquals('prompt', $email->auto_classification);
    }

    public function testGetThreadsMapsAutoClassification(): void {
        // :: Setup
        $operations = new ThreadDatabaseOperations();

        // :: Act
        $threadsByFile = $operations->getThreads(null);
        $email = null;
        foreach ($threadsByFile as $threads) {
            foreach ($threads->threads as $thread) {
                if ($thread->id === $this->threadId) {
                    $email = $this->findEmail($thread);
                }
            }
        }

        // :: Assert
        $this->assertNotNull($email, 'Thread ' . $this->threadId . ' not found in getThreads()');
        $this->assertEquals('prompt', $email->auto_classification);
    }

    public function testClassificationLabelReportsAiNotHuman(): void {
        // :: Setup
        $thread = Thread::loadFromDatabase($this->threadId);
        $email = $this->findEmail($thread);

        // :: Act
        $label = ThreadEmailClassifier::getClassificationLabel($email);

        // :: Assert
        $this->assertEquals('AI', $label);
    }

    public function testHumanClassifiedEmailStillReportsHuman(): void {
        // :: Setup
        Database::execute(
            "UPDATE thread_emails SET auto_classification = NULL WHERE id = ?", [$this->emailId]);
        $thread = Thread::loadFromDatabase($this->threadId);
        $email = $this->findEmail($thread);

        // :: Act
        $label = ThreadEmailClassifier::getClassificationLabel($email);

        // :: Assert
        $this->assertEquals('Human', $label);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadAutoClassificationLoadingTest.php`

Expected: FAIL — `testLoadFromDatabaseMapsAutoClassification`, `testGetThreadsForEntityMapsAutoClassification` and `testGetThreadsMapsAutoClassification` fail with `null` instead of `'prompt'`, and `testClassificationLabelReportsAiNotHuman` fails with `'Human'` instead of `'AI'`.

- [ ] **Step 3: Map the column in Thread::mapFromDatabase()**

In `organizer/src/class/Thread.php`, find:

```php
            $email->description = $emailData['description'];
            $email->answer = $emailData['answer'];
```

Replace with:

```php
            $email->description = $emailData['description'];
            $email->answer = $emailData['answer'];
            $email->auto_classification = $emailData['auto_classification'];
```

- [ ] **Step 4: Map the column in getThreads()**

In `organizer/src/class/ThreadDatabaseOperations.php`, in `getThreads()`, find:

```php
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.description,
                    e.ignore,
```

Replace with:

```php
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.auto_classification as email_auto_classification,
                    e.description,
                    e.ignore,
```

Then find, in the same method:

```php
                $currentEmail->status_type = $row['email_status_type'];
                $currentEmail->status_text = $row['email_status_text'];
                $currentEmail->description = $row['description'];
                $currentEmail->ignore = (bool)$row['ignore'];
                $currentEmail->attachments = array();
```

Replace with:

```php
                $currentEmail->status_type = $row['email_status_type'];
                $currentEmail->status_text = $row['email_status_text'];
                $currentEmail->auto_classification = $row['email_auto_classification'];
                $currentEmail->description = $row['description'];
                $currentEmail->ignore = (bool)$row['ignore'];
                $currentEmail->attachments = array();
```

- [ ] **Step 5: Map the column in getThreadsForEntity()**

In the same file, in `getThreadsForEntity()` further down, find the second occurrence of:

```php
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.description,
                    e.ignore,
```

Replace with:

```php
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.auto_classification as email_auto_classification,
                    e.description,
                    e.ignore,
```

Then find the second occurrence of:

```php
                $currentEmail->status_type = $row['email_status_type'];
                $currentEmail->status_text = $row['email_status_text'];
                $currentEmail->description = $row['description'];
                $currentEmail->ignore = (bool)$row['ignore'];
                $currentEmail->attachments = array();
```

Replace with:

```php
                $currentEmail->status_type = $row['email_status_type'];
                $currentEmail->status_text = $row['email_status_text'];
                $currentEmail->auto_classification = $row['email_auto_classification'];
                $currentEmail->description = $row['description'];
                $currentEmail->ignore = (bool)$row['ignore'];
                $currentEmail->attachments = array();
```

After editing, confirm both methods were changed:

```bash
grep -c 'email_auto_classification' organizer/src/class/ThreadDatabaseOperations.php
```

Expected: `4` — two SELECT aliases and two mappings.

- [ ] **Step 6: Run test to verify it passes**

Run: `./run-tests-worktree.sh /php-frontend/tests/ThreadAutoClassificationLoadingTest.php`

Expected: PASS — 5 tests

- [ ] **Step 7: Run the full unit suite**

Run: `./run-tests-worktree.sh /php-frontend/tests/`

Expected: 512 tests, 0 failures, 2 skipped

- [ ] **Step 8: Commit**

```bash
git add organizer/src/class/Thread.php organizer/src/class/ThreadDatabaseOperations.php organizer/src/tests/ThreadAutoClassificationLoadingTest.php
git commit -m "Load auto_classification so 'Classified by' tells the truth

getClassificationLabel() branches on the auto_classification column, but
no loader mapped it onto ThreadEmail - Thread::mapFromDatabase() already
selected it with SELECT * and then dropped it, and the two
ThreadDatabaseOperations queries never selected it at all.

isset() was therefore always false, so every classified email claimed
'Classified by Human' whether code, AI or a person had classified it."
```

---

### Task 7: Clear the placeholder on save

Wire Task 1's rule into the classify page, and replace the two literal `'uklassifisert-dok'` strings in the saver with the constant so the string is defined once.

**Files:**
- Modify: `organizer/src/classify-email.php` (attachment loop, ~lines 145-158)
- Modify: `organizer/src/class/ThreadEmailDatabaseSaver.php` (~line 196 and ~line 341)

**Interfaces:**
- Consumes: `ThreadEmailAttachment::normalizeStatusText()` and `ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT` from Task 1
- Produces: no new interface

- [ ] **Step 1: Apply the rule in classify-email.php**

In `organizer/src/classify-email.php`, find:

```php
                $attNewStatusTypeString = $_POST[$emailId . '-att-' . $attId . '-status_type'];
                $att->status_text = $_POST[$emailId . '-att-' . $attId . '-status_text'];
                $att->status_type = ThreadEmailStatusType::tryFrom($attNewStatusTypeString) ?? ThreadEmailStatusType::UNKNOWN;
```

Replace with:

```php
                $attNewStatusTypeString = $_POST[$emailId . '-att-' . $attId . '-status_type'];
                $att->status_type = ThreadEmailStatusType::tryFrom($attNewStatusTypeString) ?? ThreadEmailStatusType::UNKNOWN;
                // The form pre-fills the ingest placeholder, so it survives a real
                // classification unless it is dropped here.
                $att->status_text = ThreadEmailAttachment::normalizeStatusText(
                    $att->status_type,
                    $_POST[$emailId . '-att-' . $attId . '-status_text']
                );
```

Note the reordering: `status_type` is now assigned before `status_text`, because the rule needs the type.

Add the require near the other requires at the top of the file, after `require_once __DIR__ . '/class/ThreadStorageManager.php';`:

```php
require_once __DIR__ . '/class/ThreadEmailAttachment.php';
```

- [ ] **Step 2: Use the constant in ThreadEmailDatabaseSaver**

In `organizer/src/class/ThreadEmailDatabaseSaver.php`, find:

```php
                                $att->status_text = 'uklassifisert-dok';
```

Replace with:

```php
                                $att->status_text = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;
```

Then find:

```php
            ':status_text' => 'uklassifisert-dok'
```

Replace with:

```php
            ':status_text' => ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT
```

Confirm the class is already required by this file:

```bash
grep -n 'ThreadEmailAttachment' organizer/src/class/ThreadEmailDatabaseSaver.php
```

If no `require_once` for it appears, add `require_once __DIR__ . '/ThreadEmailAttachment.php';` alongside the file's other requires.

- [ ] **Step 3: Check for syntax errors**

Run:

```bash
docker run --rm -v "$PWD/organizer/src:/php-frontend" --entrypoint php offpost-organizer -l /php-frontend/classify-email.php
docker run --rm -v "$PWD/organizer/src:/php-frontend" --entrypoint php offpost-organizer -l /php-frontend/class/ThreadEmailDatabaseSaver.php
grep -rn "'uklassifisert-dok'" organizer/src/class organizer/src/classify-email.php
```

Expected: `No syntax errors detected` twice, and the grep prints nothing.

- [ ] **Step 4: Run the full unit suite**

Run: `./run-tests-worktree.sh /php-frontend/tests/`

Expected: 512 tests, 0 failures, 2 skipped.

`ThreadEmailReceiveIntegrationTest` asserts `'uklassifisert-dok'` on a freshly received attachment — that is ingest, which still stamps the placeholder, so it must keep passing. If it fails, the saver change went further than intended.

- [ ] **Step 5: Commit**

```bash
git add organizer/src/classify-email.php organizer/src/class/ThreadEmailDatabaseSaver.php
git commit -m "Clear the attachment placeholder when a real type is chosen

The classify form pre-fills the ingest placeholder into its Status Text
input, so choosing a real status type left 'uklassifisert-dok' in the
database. Ingest still stamps it - it is what marks an attachment as
needing classification - but it is dropped once someone classifies.

Also replaced the two literal copies of the string in the saver with the
constant."
```

---

### Task 8: Final verification

**Files:** none modified

- [ ] **Step 1: Run the full unit suite**

Run: `./run-tests-worktree.sh /php-frontend/tests/`

Expected: 512 tests, 0 failures, 2 skipped

- [ ] **Step 2: Run the e2e suite**

Run: `./run-tests-worktree.sh /php-frontend/e2e-tests/`

Expected: 112 tests, 9 errors, 7 failures — **the same 16 as the baseline**. Compare the failing test names against the baseline list; any new name is a regression that must be fixed before finishing.

- [ ] **Step 3: Review the whole diff**

Run: `git --no-pager diff main...HEAD`

Check: no debugging leftovers, no reformatting of untouched lines, no `label_type` variables left unused, no literal `'uklassifisert-dok'` outside `ThreadEmailAttachment`.

- [ ] **Step 4: Remove the test runner scaffold**

`run-tests-worktree.sh` is untracked scaffolding for running tests against a worktree. Ask the user whether to keep it — if it stays, it needs its own commit and a line in CLAUDE.md's Commands section; if not:

```bash
rm run-tests-worktree.sh
```

- [ ] **Step 5: Confirm documentation needs no change**

`docs/` contains only `superpowers/` — there is no codebase documentation describing thread-view or classifications, and `README.md` does not mention classification. This change alters the presentation of an existing capability rather than adding one, so neither needs an edit. Confirm with:

```bash
grep -rn -i 'classif' README.md docs/ --include='*.md' | grep -v superpowers/
```

Expected: no output.

---

## Verification Summary

| Behaviour | Verified by |
|---|---|
| Classification type shown on email rows | `ThreadUtilsRenderClassificationTest` + Task 5 Step 6 browser check |
| Classification type shown on attachment rows | `ThreadUtilsRenderClassificationTest` + Task 5 Step 6 browser check |
| Placeholder hidden on display | `testPlaceholderStatusTextIsSuppressed` |
| Placeholder cleared on save | `ThreadEmailAttachmentNormalizeStatusTextTest` + Task 7 Step 3 grep |
| "Classified by" reports AI / Code / Human correctly | `ThreadAutoClassificationLoadingTest` |
| No XSS via `status_text` on the front page | `testStatusTextIsEscaped` |
| Every enum case renders without throwing | `testEveryEnumCaseRenders`, `testEveryEnumCaseHasALabelType` |
| Chip is visually a chip | Task 5 Step 6 browser check |
| No regressions | Full unit suite; e2e failure set unchanged from baseline |

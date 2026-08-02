# Clarification Status Types Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add ASKING_FOR_CLARIFICATION / CLARIFICATION_SENT status types, per-status guidance text (what it means, whether to mark Ignore), and AI keyword detection for clarification requests.

**Architecture:** All status types live in the `ThreadEmailStatusType` PHP enum; a new `description()` method becomes the single source of guidance text, rendered in the classify UI. `getLabelType()` (throws on unknown values) and the AI keyword classifier learn the new type. No DB migration: `status_type` columns are plain `varchar(50)`.

**Tech Stack:** PHP 8.1+ enums, PHPUnit, vanilla JS in `classify-email.php`.

**Spec:** `docs/superpowers/specs/2026-08-02-clarification-status-types-design.md`

## Global Constraints

- NEVER run `git add` or `git commit` — staging/committing is done by the human user (CLAUDE.md rule). Task "commit" steps only *suggest* a message.
- Tests: `./organizer/src/vendor/bin/phpunit organizer/src/tests/` from repo root. `ThreadEmailStatusUpdaterTest` needs the dev database: `docker-compose -f docker-compose.dev.yaml up -d` first if not running.
- Tests must be deterministic, must not skip, and use `// :: Setup / // :: Act / // :: Assert` section comments (CLAUDE.md).
- Avoid unrelated reformatting; match surrounding code style.

---

### Task 1: Enum cases + `description()` method

**Files:**
- Modify: `organizer/src/class/Enums/ThreadEmailStatusType.php`
- Test (create): `organizer/src/tests/ThreadEmailStatusTypeTest.php`

**Interfaces:**
- Produces: `ThreadEmailStatusType::ASKING_FOR_CLARIFICATION` (value `'ASKING_FOR_CLARIFICATION'`, label `'Asking for Clarification'`), `ThreadEmailStatusType::CLARIFICATION_SENT` (value `'CLARIFICATION_SENT'`, label `'Clarification Sent'`), and `public function description(): string` on every case. Tasks 2–4 rely on these exact names.

- [ ] **Step 1: Write the failing test**

Create `organizer/src/tests/ThreadEmailStatusTypeTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

class ThreadEmailStatusTypeTest extends TestCase {
    public function testClarificationCases() {
        // :: Setup

        // :: Act & Assert
        $this->assertEquals('ASKING_FOR_CLARIFICATION', ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->value);
        $this->assertEquals('Asking for Clarification', ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->label());
        $this->assertEquals('CLARIFICATION_SENT', ThreadEmailStatusType::CLARIFICATION_SENT->value);
        $this->assertEquals('Clarification Sent', ThreadEmailStatusType::CLARIFICATION_SENT->label());
    }

    public function testAllCasesHaveLabelAndDescription() {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act & Assert
        // label() and description() use match without a default arm, so a
        // missing case throws UnhandledMatchError and fails this test.
        foreach ($cases as $case) {
            $this->assertNotEmpty($case->label(), 'Missing label for ' . $case->value);
            $this->assertNotEmpty($case->description(), 'Missing description for ' . $case->value);
        }
    }

    public function testIgnoreGuidanceMentionsNpIntegration() {
        // :: Setup
        $case = ThreadEmailStatusType::ASKING_FOR_CLARIFICATION;

        // :: Act
        $description = $case->description();

        // :: Assert
        // The consequence of Ignore (exclusion from the NP integration) must be
        // stated so the classifying user understands what the flag does.
        $this->assertStringContainsString('NP integration', $description);
        $this->assertStringContainsString('Ignore', $description);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadEmailStatusTypeTest.php`
Expected: FAIL — `Error: Undefined constant ThreadEmailStatusType::ASKING_FOR_CLARIFICATION`

- [ ] **Step 3: Implement enum changes**

In `organizer/src/class/Enums/ThreadEmailStatusType.php`:

Add after `case COPY_SENT = 'COPY_SENT';`:

```php
    case ASKING_FOR_CLARIFICATION = 'ASKING_FOR_CLARIFICATION';
    case CLARIFICATION_SENT = 'CLARIFICATION_SENT';
```

Add to `label()` match, after `self::COPY_SENT => 'Copy Sent',`:

```php
            self::ASKING_FOR_CLARIFICATION => 'Asking for Clarification',
            self::CLARIFICATION_SENT => 'Clarification Sent',
```

Add new method after `label()`:

```php
    // Guidance shown in the classify UI: what the status means and whether
    // such emails should typically be marked Ignore. Ignored emails are
    // grayed out in listings and excluded from the NP integration.
    public function description(): string
    {
        return match ($this) {
            self::OUR_REQUEST => 'The request we sent to the entity. Never ignore.',
            self::ASKING_FOR_MORE_TIME => 'The entity says it needs more time before answering. Not an answer — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::ASKING_FOR_COPY => 'The entity asks us to send a copy of something (e.g. earlier correspondence). Administrative back-and-forth — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::COPY_SENT => 'We sent the requested copy. Administrative — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::ASKING_FOR_CLARIFICATION => 'The entity asks us to clarify or narrow the request (e.g. which journal posts we want). Not a response — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::CLARIFICATION_SENT => 'Our reply clarifying or narrowing the request. Generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::REQUEST_REJECTED => 'The entity rejected the request. A real response. Never ignore.',
            self::INFORMATION_RELEASE => 'The entity released the requested information or documents. A real response. Never ignore.',
            self::INFO => 'Legacy value. Do not use for new classifications.',
            self::ERROR => 'Legacy value. Do not use for new classifications.',
            self::SUCCESS => 'Legacy value. Do not use for new classifications.',
            self::UNKNOWN => 'Not yet classified.',
        };
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadEmailStatusTypeTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Suggest commit (do NOT stage or commit)**

Suggested message: `Add ASKING_FOR_CLARIFICATION/CLARIFICATION_SENT status types with per-status ignore guidance`

---

### Task 2: `getLabelType()` support

**Files:**
- Modify: `organizer/src/class/ThreadUtils.php` (the `getLabelType()` switch, around lines 25–27)
- Test: `organizer/src/tests/ThreadUtilsTest.php`

**Interfaces:**
- Consumes: enum cases from Task 1.
- Produces: `getLabelType($type, 'ASKING_FOR_CLARIFICATION')` → `'label label_asking_for_clarification'`; `getLabelType($type, 'CLARIFICATION_SENT')` → `'label label_clarification_sent'`.

- [ ] **Step 1: Write the failing tests**

Add to `organizer/src/tests/ThreadUtilsTest.php` after `testGetLabelTypeUnknown()`:

```php
    public function testGetLabelTypeAskingForClarification() {
        $this->assertEquals('label label_asking_for_clarification', getLabelType('any', 'ASKING_FOR_CLARIFICATION'));
    }

    public function testGetLabelTypeClarificationSent() {
        $this->assertEquals('label label_clarification_sent', getLabelType('any', 'CLARIFICATION_SENT'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadUtilsTest.php`
Expected: FAIL — exception `Unknown status_type[any]: ASKING_FOR_CLARIFICATION`

- [ ] **Step 3: Implement**

In `organizer/src/class/ThreadUtils.php`, add after the `COPY_SENT` case (line ~26):

```php
        case ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->value:
            return 'label label_asking_for_clarification';
        case ThreadEmailStatusType::CLARIFICATION_SENT->value:
            return 'label label_clarification_sent';
```

(No CSS needed — existing status label classes like `label_asking_for_copy` have no CSS either; they render with the base `.label` style.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadUtilsTest.php`
Expected: PASS (all tests, including the 2 new ones)

- [ ] **Step 5: Suggest commit (do NOT stage or commit)**

Suggested message: `getLabelType: handle clarification status types`

---

### Task 3: AI keyword detection for clarification requests

**Files:**
- Modify: `organizer/src/class/Extraction/ThreadEmailStatusUpdater.php` (`determineStatusTypeFromSummary()`, lines ~106–133)
- Test: `organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php`

**Interfaces:**
- Consumes: `ThreadEmailStatusType::ASKING_FOR_CLARIFICATION` from Task 1.
- Produces: summaries asking which documents/journal posts we want now classify as `ASKING_FOR_CLARIFICATION`.

**Requires:** dev database running (`docker-compose -f docker-compose.dev.yaml up -d`).

- [ ] **Step 1: Write the failing test**

Add to `organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php`, after `testDetermineStatusTypeFromSummaryAskingForCopy()` (line ~137), following the existing test pattern in that file (`createTestEmail()` helper, DB assertion):

```php
    public function testDetermineStatusTypeFromSummaryAskingForClarification() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Ber om tilbakemelding på hvilke av journalpostene du ønsker innsyn i";

        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);

        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('ASKING_FOR_CLARIFICATION', $email['status_type']);
    }

    public function testDetermineStatusTypeFromSummaryClarificationAvklaring() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Kommunen ber om en avklaring av hva forespørselen gjelder";

        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);

        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('ASKING_FOR_CLARIFICATION', $email['status_type']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./organizer/src/vendor/bin/phpunit organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php`
Expected: the 2 new tests FAIL (first summary currently falls through to `INFORMATION_RELEASE` via the `informasjon`-less release pattern or `unknown`; exact wrong value doesn't matter — assertion on `ASKING_FOR_CLARIFICATION` fails). All pre-existing tests PASS.

- [ ] **Step 3: Implement**

In `determineStatusTypeFromSummary()`, insert between the `REQUEST_REJECTED` check and the `ASKING_FOR_COPY` check:

```php
        // Check for clarification requests - must come before the copy and
        // information release patterns, which would otherwise swallow
        // summaries like "ber om tilbakemelding på hvilke ... innsyn i"
        if (preg_match('/\b(presiser\w*|avklar\w*|konkretiser\w*|spesifiser\w*|tilbakemelding på hvilke|hvilke .{0,60}innsyn)/u', $summary_lower)) {
            return ThreadEmailStatusType::ASKING_FOR_CLARIFICATION;
        }
```

Note: no trailing `\b` after the alternation — `\w*` stems already end at word boundaries, and a trailing `\b` after `innsyn` would be fine but is unnecessary.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./organizer/src/vendor/bin/phpunit organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php`
Expected: PASS — both new tests and all pre-existing ones (the existing AskingForCopy/MoreTime/Rejected/InformationRelease/Unknown summaries must not match the new pattern).

- [ ] **Step 5: Suggest commit (do NOT stage or commit)**

Suggested message: `Email classification: detect clarification requests (ASKING_FOR_CLARIFICATION)`

---

### Task 4: Guidance text in the classify UI

**Files:**
- Modify: `organizer/src/classify-email.php` (`labelSelect()` at line ~181, `<script>` block at line ~318, `<style>` block at line ~226, `settForslag()` at line ~319)

**Interfaces:**
- Consumes: `ThreadEmailStatusType::description()` from Task 1.
- Produces: UI only; nothing downstream consumes this.

No automated test — this is a server-rendered page with no page-level test harness in `tests/`. Verification is `php -l` plus manual check in the dev environment.

- [ ] **Step 1: Extend `labelSelect()`**

Replace the existing `labelSelect()` function with:

```php
function labelSelect($currentTypeInput, $id) {
    $currentTypeValue = $currentTypeInput instanceof ThreadEmailStatusType ? $currentTypeInput->value : $currentTypeInput;
    $currentCase = $currentTypeValue !== null ? ThreadEmailStatusType::tryFrom($currentTypeValue) : null;
    ?>
    <select name="<?= $id ?>" onchange="visStatusBeskrivelse(this)">
        <?php foreach (ThreadEmailStatusType::cases() as $case): ?>
            <option value="<?= $case->value ?>" <?= $currentTypeValue == $case->value ? ' selected="selected"' : '' ?>>
                <?= htmlspecialchars($case->label()) ?> (<?= htmlspecialchars($case->value) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <div class="status-description" id="<?= $id ?>-description"><?= htmlspecialchars($currentCase ? $currentCase->description() : '') ?></div>
    <?php
}
```

(`tryFrom()` handles legacy DB strings: `'unknown'` maps to the UNKNOWN case; unmapped legacy values like `'disabled'` yield an empty description.)

- [ ] **Step 2: Add JS map + update function**

In the existing `<script>` block (line ~318), add above `function settForslag`:

```js
    var statusDescriptions = <?= json_encode(array_combine(
        array_map(fn($c) => $c->value, ThreadEmailStatusType::cases()),
        array_map(fn($c) => $c->description(), ThreadEmailStatusType::cases())
    )) ?>;
    function visStatusBeskrivelse(selectElement) {
        var div = document.getElementById(selectElement.name + '-description');
        if (div) {
            div.textContent = statusDescriptions[selectElement.value] || '';
        }
    }
```

And inside `settForslag()`, after `selectElement.value = forslagStatus;` add (programmatic `.value` assignment does not fire `onchange`):

```js
        visStatusBeskrivelse(selectElement);
```

- [ ] **Step 3: Add CSS**

In the `<style>` block, after the `.form-group textarea` rule:

```css
        .status-description {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 4px;
        }
```

- [ ] **Step 4: Verify**

Run: `php -l organizer/src/classify-email.php`
Expected: `No syntax errors detected`

Then run the full unit suite to confirm nothing else broke:
`./organizer/src/vendor/bin/phpunit organizer/src/tests/`
Expected: PASS

Manual check (optional, needs dev stack): open a thread's classify page, confirm the description shows under each Status Type dropdown and updates when the selection changes.

- [ ] **Step 5: Suggest commit (do NOT stage or commit)**

Suggested message: `Classify UI: show per-status guidance (meaning + when to mark Ignore)`

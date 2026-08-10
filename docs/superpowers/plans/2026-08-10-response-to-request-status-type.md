# RESPONSE_TO_REQUEST Status Type Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `RESPONSE_TO_REQUEST` email/attachment status type for the entity's formal reply letter to an innsynskrav ("Svar på innsynskrav"), so it is no longer conflated with the documents released in `INFORMATION_RELEASE`.

**Architecture:** One new case on the `App\Enums\ThreadEmailStatusType` backed enum, plus the matching arm in the `getLabelType()` switch that renders it. The classify UI needs no edit — it iterates `ThreadEmailStatusType::cases()` and builds its help-text map from the enum, so the new case appears in both the email dropdown and the attachment dropdown automatically. No database migration and no AI auto-classification change.

**Tech Stack:** PHP 8 backed enums, PHPUnit 9/10 (`./organizer/src/vendor/bin/phpunit`), PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-08-10-response-to-request-status-type-design.md`

## Global Constraints

- **Never stage or commit.** This repo's `CLAUDE.md` states staging is done by the human user. Every task ends by *printing a suggested commit command* for the human — do not run `git add` or `git commit`.
- Avoid unnecessary modifications that complicate code review: no reformatting, no renaming, no touching lines the task does not require.
- When viewing diffs use `git --no-pager diff`.
- Test structure: `// :: Setup`, `// :: Act`, `// :: Assert` comment sections.
- Tests must be deterministic: no `rand()`, no `time()`/`date()`. Fixed values only.
- Tests must fail, never skip. No `markTestSkipped()`.
- Use `assertEquals` (not `assertStringContainsString`) where output is deterministic — all assertions in this plan are deterministic.
- Enum case value string is exactly `RESPONSE_TO_REQUEST`. Label is exactly `Response to Request`. CSS class is exactly `label label_response_to_request`.

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `organizer/src/class/Enums/ThreadEmailStatusType.php` | The status type enum: case list, `label()`, `description()` | Modify — 3 lines added (case, label arm, description arm) |
| `organizer/src/class/ThreadUtils.php` | `getLabelType()` — maps a status type value to CSS classes; throws on unknown values | Modify — 2 lines added |
| `organizer/src/tests/ThreadEmailStatusTypeTest.php` | Unit tests for the enum | Modify — 1 test method added |
| `organizer/src/tests/ThreadUtilsTest.php` | Unit tests for `ThreadUtils.php` | Modify — 1 test method added |
| `organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php` | Unit tests for AI summary classification | Modify — 1 array entry added to an existing test |

Deliberately **not** touched: `organizer/src/classify-email.php` (enum-driven, no edit needed), `organizer/src/class/Extraction/ThreadEmailStatusUpdater.php` (manual-only decision), `organizer/src/migrations/sql/` (columns are `varchar(50)`), `README.md` (mentions `status_type` at line 116 but does not enumerate the types).

---

### Task 1: Add the `RESPONSE_TO_REQUEST` enum case

**Files:**
- Modify: `organizer/src/class/Enums/ThreadEmailStatusType.php`
- Test: `organizer/src/tests/ThreadEmailStatusTypeTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\Enums\ThreadEmailStatusType::RESPONSE_TO_REQUEST`, a case on the existing `string`-backed enum with value `'RESPONSE_TO_REQUEST'`. Its `label(): string` returns `'Response to Request'` and its `description(): string` returns the guidance text below. Task 2 consumes `ThreadEmailStatusType::RESPONSE_TO_REQUEST->value`.

**Background for the implementer:** `label()` and `description()` are `match ($this)` expressions with **no default arm**. PHP therefore throws `\UnhandledMatchError` the moment any code calls them on a case you forgot to add an arm for. The existing test `testAllCasesHaveLabelAndDescription` loops over `ThreadEmailStatusType::cases()` and calls both methods, so it turns that error into a test failure. This is why adding the case alone makes an existing test fail — that is expected and is the "red" of this task.

- [ ] **Step 1: Write the failing test**

Add this method to `organizer/src/tests/ThreadEmailStatusTypeTest.php`, immediately after the existing `testClarificationCases()` method (which ends on the line `}` after the `CLARIFICATION_SENT` label assertion):

```php
    public function testResponseToRequestCase() {
        // :: Setup

        // :: Act & Assert
        $this->assertEquals('RESPONSE_TO_REQUEST', ThreadEmailStatusType::RESPONSE_TO_REQUEST->value);
        $this->assertEquals('Response to Request', ThreadEmailStatusType::RESPONSE_TO_REQUEST->label());
    }

    public function testResponseToRequestDescriptionNamesInnsynskravAndIgnoreEffect() {
        // :: Setup
        $case = ThreadEmailStatusType::RESPONSE_TO_REQUEST;

        // :: Act
        $description = $case->description();

        // :: Assert
        // The Norwegian term is what the classifying user sees in the email,
        // and the Ignore consequence must be stated even though this status
        // carries no Ignore recommendation.
        $this->assertStringContainsString('innsynskrav', $description);
        $this->assertStringContainsString('NP integration', $description);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadEmailStatusTypeTest.php
```

Expected: FAIL. `testResponseToRequestCase` and `testResponseToRequestDescriptionNamesInnsynskravAndIgnoreEffect` fail with a PHP `Error`/parse-level failure along the lines of *"Undefined constant App\Enums\ThreadEmailStatusType::RESPONSE_TO_REQUEST"* — the case does not exist yet. `testAllCasesHaveLabelAndDescription` still passes at this point (it only iterates cases that exist).

- [ ] **Step 3: Add the enum case**

In `organizer/src/class/Enums/ThreadEmailStatusType.php`, add the case after `CLARIFICATION_SENT` and before `REQUEST_REJECTED`:

```php
    case CLARIFICATION_SENT = 'CLARIFICATION_SENT';
    case RESPONSE_TO_REQUEST = 'RESPONSE_TO_REQUEST';
    case REQUEST_REJECTED = 'REQUEST_REJECTED';
```

- [ ] **Step 4: Run the tests to verify the failure moved**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadEmailStatusTypeTest.php
```

Expected: FAIL, but differently — now with `UnhandledMatchError: Unhandled match case App\Enums\ThreadEmailStatusType::RESPONSE_TO_REQUEST`, thrown from `label()`. `testAllCasesHaveLabelAndDescription` now fails too. This confirms the enum case exists and the match arms are genuinely required.

- [ ] **Step 5: Add the `label()` arm**

In the same file, in the `label()` match, add the arm after the `CLARIFICATION_SENT` arm:

```php
            self::CLARIFICATION_SENT => 'Clarification Sent',
            self::RESPONSE_TO_REQUEST => 'Response to Request',
            self::REQUEST_REJECTED => 'Request Rejected',
```

- [ ] **Step 6: Add the `description()` arm**

In the same file, in the `description()` match, add the arm after the `CLARIFICATION_SENT` arm. Note this description gives **no** Ignore recommendation — unlike its neighbours — because whether a reply letter is worth keeping visible depends on what it says. It must still state what Ignore does:

```php
            self::CLARIFICATION_SENT => 'Our reply clarifying or narrowing the request. Generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::RESPONSE_TO_REQUEST => 'The entity\'s formal reply to the innsynskrav ("Svar på innsynskrav") — a covering or decision letter. May be the whole email, or one attachment alongside the released documents. No general rule on Ignore; decide per email (ignoring hides it from listings and excludes it from the NP integration).',
            self::REQUEST_REJECTED => 'The entity rejected the request. A real response. Never ignore.',
```

- [ ] **Step 7: Run the tests to verify they pass**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadEmailStatusTypeTest.php
```

Expected: PASS, all tests in the file (including `testAllCasesHaveLabelAndDescription`).

- [ ] **Step 8: Report the suggested commit — do NOT run it**

Print this for the human user. Do not run `git add` or `git commit`:

```bash
git add organizer/src/class/Enums/ThreadEmailStatusType.php organizer/src/tests/ThreadEmailStatusTypeTest.php && git commit -m "Add RESPONSE_TO_REQUEST status type to ThreadEmailStatusType

Entities answer an innsynskrav with two distinct things: a formal reply
letter (\"Svar på innsynskrav\") and the documents being released. Both
were classified INFORMATION_RELEASE, conflating the answer about the
request with the information released in response to it.

The description gives no Ignore recommendation - unlike the
administrative and real-response statuses, whether a reply letter is
worth keeping visible depends on what it says - but still states what
the Ignore flag does."
```

---

### Task 2: Render the new status in `getLabelType()`

**Files:**
- Modify: `organizer/src/class/ThreadUtils.php:24-36` (the `switch` in `getLabelType()`)
- Test: `organizer/src/tests/ThreadUtilsTest.php`

**Interfaces:**
- Consumes: `App\Enums\ThreadEmailStatusType::RESPONSE_TO_REQUEST` from Task 1.
- Produces: `getLabelType(string $type, string $status_type_input): string` returns `'label label_response_to_request'` for input `'RESPONSE_TO_REQUEST'`.

**Background for the implementer:** `getLabelType()` ends in a `default` arm that throws `Exception("Unknown status_type[$type]: $status_type_input")` — see the existing `testGetLabelTypeInvalid` test. So this arm is **not** optional polish: without it, every listing page that renders an email classified `RESPONSE_TO_REQUEST` throws. No CSS is added; none of the existing `label_*` classes are styled yet, so the class name is a placeholder for a later styling pass, consistent with the clarification statuses added on 2026-08-02.

- [ ] **Step 1: Write the failing test**

Add this method to `organizer/src/tests/ThreadUtilsTest.php`, immediately after the existing `testGetLabelTypeClarificationSent()` method and before `testGetLabelTypeInvalid()`:

```php
    public function testGetLabelTypeResponseToRequest() {
        $this->assertEquals('label label_response_to_request', getLabelType('any', 'RESPONSE_TO_REQUEST'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadUtilsTest.php --filter testGetLabelTypeResponseToRequest
```

Expected: FAIL with `Exception: Unknown status_type[any]: RESPONSE_TO_REQUEST` — the `default` arm throwing, which is exactly the production bug this task fixes.

- [ ] **Step 3: Add the switch case**

In `organizer/src/class/ThreadUtils.php`, add the case after the `CLARIFICATION_SENT` case and before the `REQUEST_REJECTED` case:

```php
        case ThreadEmailStatusType::CLARIFICATION_SENT->value:
            return 'label label_clarification_sent';
        case ThreadEmailStatusType::RESPONSE_TO_REQUEST->value:
            return 'label label_response_to_request';
        case ThreadEmailStatusType::REQUEST_REJECTED->value:
            return 'label label_request_rejected label_warn'; // Suggest new style, maybe warn
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ThreadUtilsTest.php
```

Expected: PASS, all tests in the file.

- [ ] **Step 5: Report the suggested commit — do NOT run it**

Print this for the human user. Do not run `git add` or `git commit`:

```bash
git add organizer/src/class/ThreadUtils.php organizer/src/tests/ThreadUtilsTest.php && git commit -m "getLabelType(): handle RESPONSE_TO_REQUEST

getLabelType() throws on unknown status types, so every listing page
rendering an email classified RESPONSE_TO_REQUEST would fail without
this arm.

No CSS added - none of the existing label_* classes are styled yet."
```

---

### Task 3: Pin the manual-only decision with a classifier regression test

**Files:**
- Test: `organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php:379-414` (the `testMultipleNorwegianKeywordPatterns` method)

**Interfaces:**
- Consumes: nothing — asserts on existing behaviour of `ThreadEmailStatusUpdater::determineStatusTypeFromSummary()`, reached through the public `updateFromAISummary()`.
- Produces: nothing consumed by later tasks.

**Background for the implementer:** This is a **characterization test, not TDD** — it pins behaviour that already works, so it passes the first time you run it. That is correct and expected here; do not "fix" anything to make it go red.

Why it exists: the spec decided `RESPONSE_TO_REQUEST` is chosen by hand only. `determineStatusTypeFromSummary()` is first-match-wins, so any future "svar på innsynskrav" pattern would have to sit *before* the `INFORMATION_RELEASE` check at `ThreadEmailStatusUpdater.php:134` to fire at all — and would then swallow genuine releases, whose summaries read exactly like the string below. This test makes that regression loud.

Trace of the string `'Svar på innsynskrav, dokumentene er vedlagt'` through the current patterns, so you can see why `INFORMATION_RELEASE` is the right expectation: no match on `mer tid|utsette|forlenge|frist`; no match on `avslag|avslår|avslå|avslås|kan ikke|avvise`; no match on the clarification pattern; no match on `kopi|kan vi få|send|videresend|ber om.*dokumenter` (the word is "vedlagt", not "sendt"); then `\bvedlagt\b` matches the information-release pattern.

**Note:** this test class hits the database (`Database::queryOne`), so the dev stack must be running: `docker-compose -f docker-compose.dev.yaml up -d`.

- [ ] **Step 1: Add the regression case**

In `organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php`, extend the `$testCases` array inside `testMultipleNorwegianKeywordPatterns()`. Replace the `INFORMATION_RELEASE` block:

```php
            // INFORMATION_RELEASE patterns
            ['Informasjon er vedlagt i e-posten', 'INFORMATION_RELEASE'],
            ['Dokumenter sendt som vedlegg', 'INFORMATION_RELEASE'],
```

with:

```php
            // INFORMATION_RELEASE patterns
            ['Informasjon er vedlagt i e-posten', 'INFORMATION_RELEASE'],
            ['Dokumenter sendt som vedlegg', 'INFORMATION_RELEASE'],

            // A reply letter that also releases documents stays an information
            // release. RESPONSE_TO_REQUEST is manual-only: an auto-pattern for
            // it would have to run before the information-release check and
            // would swallow real releases like this one.
            ['Svar på innsynskrav, dokumentene er vedlagt', 'INFORMATION_RELEASE'],
```

- [ ] **Step 2: Run the test to verify it passes**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php --filter testMultipleNorwegianKeywordPatterns
```

Expected: PASS. If it fails with `Wrong status for: Svar på innsynskrav, dokumentene er vedlagt`, someone has already changed the classifier — stop and report, do not adjust the expectation.

- [ ] **Step 3: Run the full unit suite**

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/
```

Expected: PASS, no failures, no errors.

- [ ] **Step 4: Run the e2e suite**

Requires the dev stack (`docker-compose -f docker-compose.dev.yaml up -d`).

Run:
```bash
./organizer/src/vendor/bin/phpunit organizer/src/e2e-tests/
```

Expected: PASS. `e2e-tests/pages/ThreadClassifyPersistsTest.php` exercises the classify form and is the check that the new dropdown entry did not break submission.

- [ ] **Step 5: Verify the classify UI shows the new option**

`classify-email.php` needs no code change — confirm that is actually true rather than assuming it. Open a thread's classify page in the dev stack and check:
1. "Response to Request (RESPONSE_TO_REQUEST)" appears in the **email** Status Type dropdown.
2. It also appears in each **attachment** Status Type dropdown.
3. Selecting it updates the grey help text underneath to the innsynskrav description.

If any of these is missing, stop and report — the enum-driven assumption in the spec is wrong and the plan needs revising.

- [ ] **Step 6: Report the suggested commit — do NOT run it**

Print this for the human user. Do not run `git add` or `git commit`:

```bash
git add organizer/src/tests/Extraction/ThreadEmailStatusUpdaterTest.php && git commit -m "Pin: reply letters with attachments stay INFORMATION_RELEASE

RESPONSE_TO_REQUEST is manual-only. determineStatusTypeFromSummary() is
first-match-wins, so any auto-pattern for it would have to run before the
information-release check and would swallow genuine releases, whose
summaries read exactly like this one."
```

---

## Verification Summary

After all three tasks:

```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ organizer/src/e2e-tests/
git --no-pager diff --stat
```

Expected diff: 5 files changed, roughly 30 insertions, **0 deletions**. Every change in this plan inserts lines between existing ones — if the diff shows deletions, something was reformatted or overwritten that should not have been. No changes under `migrations/`, no changes to `classify-email.php`, no changes to `ThreadEmailStatusUpdater.php`.

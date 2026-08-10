# Response to request status type

Date: 2026-08-10
Status: Approved (conversation with Hallvard)

## Background

Entities often answer an innsynskrav with two distinct things: a formal reply
letter ("Svar på innsynskrav") — a covering letter or a decision (vedtak) that
responds to the request itself — and the actual documents being released.

Today both end up as `INFORMATION_RELEASE`, which conflates the answer *about*
the request with the information released *in response to* it. There is no
status type for the reply letter.

This affects both levels of classification. `classify-email.php` uses the same
`ThreadEmailStatusType` dropdown for emails (`thread_emails.status_type`) and
for attachments (`thread_email_attachments.status_type`), and the reply letter
shows up in both shapes:

- as a whole email, when the letter arrives on its own and the documents come
  separately (or not at all);
- as one attachment among the released documents, when the entity bundles the
  letter with them.

### Terminology

*Innsynskrav* is the Norwegian term for the process — a demand for access to a
public body's documents, equivalent to a public records request (US) or an FOI
request (UK). The existing docs also use *innsynshenvendelse* and
*innsynsbegjæring* for the same thing, so there is no single Norwegian term for
the English name to mirror.

Within this enum the neighbouring cases are already `OUR_REQUEST`,
`REQUEST_REJECTED` and `ASKING_FOR_COPY` — "the request" unambiguously means the
innsynskrav. The enum name therefore omits "records"; the Norwegian term appears
in `description()`, where the classifying user actually reads it.

## Changes

### 1. New enum case — `organizer/src/class/Enums/ThreadEmailStatusType.php`

Placed after `CLARIFICATION_SENT` and before `REQUEST_REJECTED`, so the
"entity has answered" group reads: generic reply letter → rejected → released.
Placement affects only the order of the classify dropdown.

```php
case RESPONSE_TO_REQUEST = 'RESPONSE_TO_REQUEST';
```

- `label()` → `'Response to Request'`
- `description()` →

  > The entity's formal reply to the innsynskrav ("Svar på innsynskrav") — a
  > covering or decision letter. May be the whole email, or one attachment
  > alongside the released documents. No general rule on Ignore; decide per
  > email (ignoring hides it from listings and excludes it from the NP
  > integration).

The description deliberately gives **no** Ignore recommendation. Unlike the
administrative statuses (`ASKING_FOR_COPY`, `CLARIFICATION_SENT`, …) and unlike
the real-response statuses (`REQUEST_REJECTED`, `INFORMATION_RELEASE`), whether
a reply letter is worth keeping visible depends on what it says. It still
states what the Ignore flag does, so the classifying user can make that call.

No DB migration: `thread_emails.status_type` and
`thread_email_attachments.status_type` are plain `varchar(50)`.

### 2. `getLabelType()` — `organizer/src/class/ThreadUtils.php`

```php
case ThreadEmailStatusType::RESPONSE_TO_REQUEST->value:
    return 'label label_response_to_request';
```

Required rather than optional: the function throws on unknown values, so
omitting this breaks every listing that renders the new status.

No CSS is added. None of the existing `label_*` classes are styled yet — the
class name is a placeholder for a future styling pass, consistent with the
clarification statuses added on 2026-08-02.

### 3. No change to the classify UI — `organizer/src/classify-email.php`

`labelSelect()` iterates `ThreadEmailStatusType::cases()` and the JS
description map is generated from the enum, so the new case and its guidance
appear in both the email and the attachment dropdowns with no edit.

### 4. No change to AI auto-classification

`ThreadEmailStatusUpdater::determineStatusTypeFromSummary()` is left alone;
`RESPONSE_TO_REQUEST` is chosen by hand only.

The classifier is first-match-wins, so any new pattern would have to sit
*before* the `INFORMATION_RELEASE` check to fire at all. A genuine release
summary typically reads like "svar på innsynskrav … dokumenter vedlagt", so
such a pattern would systematically steal real releases and reclassify them as
covering letters. The catch rate is not worth that regression, particularly for
a status whose Ignore handling is a human judgement call anyway.

### 5. Tests

Unit tests only; no e2e needed.

- `ThreadEmailStatusTypeTest::testAllCasesHaveLabelAndDescription` already
  covers the new case implicitly — `label()` and `description()` use `match`
  with no default arm, so a missing arm throws `\UnhandledMatchError`.
- `ThreadEmailStatusTypeTest`: explicit assertions on the new case's `value`
  and `label()`, mirroring `testClarificationCases`.
- `ThreadUtilsTest`: `getLabelType('any', 'RESPONSE_TO_REQUEST')` returns
  `'label label_response_to_request'`.
- `ThreadEmailStatusUpdaterTest`: a regression case asserting that a
  "Svar på innsynskrav, dokumentene er vedlagt"-style summary still classifies
  as `INFORMATION_RELEASE`. This pins the manual-only decision so a future
  pattern cannot silently take over releases.

## Out of scope

- No automatic classification of this status.
- No CSS for `label_response_to_request`.
- No backfill or re-classification of existing emails.
- No changes to the NP API, Ignore defaults, or follow-up logic.

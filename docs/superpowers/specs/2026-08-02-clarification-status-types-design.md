# Clarification status types + status type guidance

Date: 2026-08-02
Status: Approved (conversation with Hallvard)

## Background

Fredrikstad kommune replied to an innsyn request ("Foreløpig svar på innsynshenvendelse")
asking which of 11 journal posts we want access to. This is a request for
clarification, not a response to the innsyn request itself. We have no status
type for this today, so it was misclassified (Information Release).

Such clarification exchanges are administrative back-and-forth: they should
generally be marked **Ignore**. The Ignore flag grays the email out in
listings (`index.php`, `view-thread.php`) **and excludes the email from the
NP integration** (`NpApiService::listNpThreads()` skips ignored emails), so
NP consumers never see it as part of the conversation.

There is currently no guidance anywhere describing what each status type
means or when an email should be marked Ignore.

## Changes

### 1. New enum cases — `organizer/src/class/Enums/ThreadEmailStatusType.php`

Placed after `COPY_SENT`, mirroring the asking-for-copy pair:

- `case ASKING_FOR_CLARIFICATION = 'ASKING_FOR_CLARIFICATION';`
  — label: "Asking for Clarification"
- `case CLARIFICATION_SENT = 'CLARIFICATION_SENT';`
  — label: "Clarification Sent"

No DB migration needed: `thread_emails.status_type` and
`thread_email_attachments.status_type` are plain `varchar(50)`.

### 2. New `description()` method on the enum

One or two sentences per status type: what it means, and whether such emails
should typically be marked Ignore (including that Ignore hides the email from
listings and removes it from the NP integration). Draft texts:

| Case | Description |
|------|-------------|
| OUR_REQUEST | The request we sent to the entity. Never ignore. |
| ASKING_FOR_MORE_TIME | The entity says it needs more time before answering. Not an answer — generally mark Ignore (hidden from listings and excluded from the NP integration). |
| ASKING_FOR_COPY | The entity asks us to send a copy of something (e.g. earlier correspondence). Administrative back-and-forth — generally mark Ignore (hidden from listings and excluded from the NP integration). |
| COPY_SENT | We sent the requested copy. Administrative — generally mark Ignore. |
| ASKING_FOR_CLARIFICATION | The entity asks us to clarify or narrow the request (e.g. which journal posts we want). Not a response — generally mark Ignore (hidden from listings and excluded from the NP integration). |
| CLARIFICATION_SENT | Our reply clarifying or narrowing the request. Generally mark Ignore. |
| REQUEST_REJECTED | The entity rejected the request. A real response. Never ignore. |
| INFORMATION_RELEASE | The entity released the requested information/documents. A real response. Never ignore. |
| INFO / ERROR / SUCCESS / UNKNOWN | Legacy values. UNKNOWN: not yet classified. |

### 3. Guidance in the classify UI — `organizer/src/classify-email.php`

Under each Status Type dropdown, show the description of the currently
selected status type. Implementation: render a `<div>` help block under the
select; a small JS map (value → description, generated from the enum) updates
the text on `change`. Initial render shows the description for the current
value server-side.

### 4. `getLabelType()` — `organizer/src/class/ThreadUtils.php`

Add cases (function throws on unknown values):

- `ASKING_FOR_CLARIFICATION` → `label label_asking_for_clarification`
- `CLARIFICATION_SENT` → `label label_clarification_sent`

### 5. AI auto-classification — `organizer/src/class/Extraction/ThreadEmailStatusUpdater.php`

In `determineStatusTypeFromSummary()`, add a Norwegian keyword pattern for
clarification requests, checked **before** the copy and information-release
patterns (order matters — those patterns would otherwise swallow summaries
like the Fredrikstad one):

- Keywords along the lines of: `presisere`, `presisering`, `avklare`,
  `avklaring`, `konkretisere`, `hvilke .* innsyn`, `tilbakemelding på hvilke`
- Returns `ASKING_FOR_CLARIFICATION`.

### 6. Tests

Unit tests (no e2e needed):

- `ThreadEmailStatusType`: every case has a non-empty `label()` and
  `description()` (match-based methods throw `\UnhandledMatchError` if a case
  is missed — the test makes that visible).
- `getLabelType()`: returns the expected label classes for the two new cases.
- `ThreadEmailStatusUpdater::determineStatusTypeFromSummary()`: a
  Fredrikstad-style summary ("Ber om tilbakemelding på hvilke av
  journalpostene du ønsker innsyn i") classifies as
  `ASKING_FOR_CLARIFICATION`; existing pattern tests keep passing.

## Out of scope

- No automatic checking of the Ignore checkbox (human decides per email).
- No changes to follow-up logic or NP API behavior.
- No migration of existing misclassified emails.

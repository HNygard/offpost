# Request receipt status type and sender grouping

## Problem

Two gaps in `ThreadEmailStatusType`.

**No type for acknowledgements.** Entities routinely reply with an automated
"Kvittering på mottatt innsynshenvendelse" — confirmation that our request
arrived. It is not an answer, and no existing status describes it. Classifiers
currently have to pick something misleading.

**The list gives no hint of direction.** Some statuses only ever apply to email
we send (`OUR_REQUEST`, `COPY_SENT`, `CLARIFICATION_SENT`); the rest only ever
apply to email the entity sends. The classify dropdown lists all thirteen in
declaration order with nothing to signal which is which, so picking the right
one means knowing the set by heart.

## Goals

Add a status type for receipts, and make the classify dropdown show at a glance
whether a status belongs to our side or theirs.

Non-goals: changing how emails are stored, altering the NP integration, or
touching AI classification (see "Deliberately out of scope").

## Design

### 1. `REQUEST_RECEIPT`

```php
case REQUEST_RECEIPT = 'REQUEST_RECEIPT';
```

| Aspect | Value |
|---|---|
| Label | `From entity: Receipt of Request` |
| Group | From entity |
| Description | The entity confirms it received our request ("Kvittering på mottatt innsynshenvendelse"). An acknowledgement, not an answer — generally mark Ignore (hidden from listings and excluded from the NP integration). |
| `getLabelType()` | `label label_request_receipt` |

No migration. `thread_emails.status_type` is `character varying(50)` with no
CHECK constraint, and `REQUEST_RECEIPT` is 15 characters.

The `getLabelType()` case is required, not decorative. That function ends in
`throw new Exception('Unknown status_type...')`, so omitting it would make the
thread view and the front page fail for any email classified as a receipt.

### 2. Sender grouping

A new method returns the group a status belongs to:

```php
public function group(): ?string   // 'From us' | 'From entity' | 'Legacy' | null
```

`label()` prepends `group() . ': '` when a group exists. `UNKNOWN` has no group
and keeps its bare label.

| Group | Statuses |
|---|---|
| From us | `OUR_REQUEST`, `CLARIFICATION_SENT`, `COPY_SENT` |
| From entity | `REQUEST_RECEIPT`, `ASKING_FOR_MORE_TIME`, `ASKING_FOR_COPY`, `ASKING_FOR_CLARIFICATION`, `RESPONSE_TO_REQUEST`, `REQUEST_REJECTED`, `INFORMATION_RELEASE` |
| Legacy | `INFO`, `ERROR`, `SUCCESS` |
| none | `UNKNOWN` |

### 3. Sorting

The `case` declarations are reordered to match the table above. `cases()`
returns declaration order, and `labelSelect()` in `classify-email.php` iterates
`cases()` directly, so the dropdown becomes grouped and sorted with no change to
`labelSelect()` itself.

Reordering declarations changes no stored data — the backing values are strings
and are untouched.

### 4. Blast radius of the label change

`label()` is called in exactly one non-test place: the status dropdown at
`classify-email.php:188`. Thread view and listing labels come from
`getLabelType()`, not `label()`, so the group prefix cannot leak into them.

The JavaScript description map at `classify-email.php:327` is built from
`cases()` and `description()`, so it follows the new order automatically.

## Deliberately out of scope

`ThreadEmailStatusUpdater::determineStatusTypeFromSummary()` gets no receipt
pattern. Adding one would mean placing it ahead of the `mer tid|frist` check,
because acknowledgements often read "vi har mottatt din henvendelse og vil svare
innen fristen" and currently match `frist`. That reordering trades one
mis-classification for another, and the keyword approach as a whole warrants
rethinking rather than another pattern layered on top.

Until that happens, receipt emails keep whatever the existing keyword rules
produce and a human reclassifies them in the UI. Nothing regresses: today there
is no correct answer for these emails at all.

## Testing

`ThreadEmailStatusTypeTest`:

- `REQUEST_RECEIPT` has the expected value, label and non-empty description
- every case has a group except `UNKNOWN`
- `label()` starts with its group prefix, and `UNKNOWN`'s does not
- `cases()` order is exactly the grouped order — from-us block, then
  from-entity, then legacy, then `UNKNOWN`
- existing label assertions updated for the prefix

`ThreadUtilsTest`:

- `getLabelType()` returns `label label_request_receipt` rather than throwing

Labels and groups are fixed strings, so assert them with `assertEquals`.

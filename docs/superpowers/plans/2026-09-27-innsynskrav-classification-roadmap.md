# Email classification for the innsynskrav flow — roadmap

Status: direction, 2026-09-27. Not an implementation plan: each step gets its
own concrete plan when we get there, based on what the step before it taught us.

## Goal

Support the normal flow of a request for information (innsynskrav) under
offentleglova, for all Offpost threads (not only norske-postlister):

- Every email, in and out, is classified.
- Some classifiers are simple rules, some use AI.
- Some classifications need context: the current email together with earlier
  emails in the thread.
- The classifications drive general thread handling: what the thread waits
  for, what we must do next, whether the entity is late, and whether the
  request is finished or rejected.

## Principles

- Decide from data, not guesses: measure against real threads with known answers.
- Improve step by step, and keep a change only when the measurement says it helps.
- Expensive models (e.g. Opus 5.5) run locally, to label and to analyse. What
  runs on the server is simple code or a cheap model.
- Never overwrite a manual classification.
- No classification from timing alone.

## Steps

### 1. API to get data down locally

- An admin-only API that exports threads with what we know about them:
  - the thread;
  - its emails: direction, time, sender, subject, headers and body;
  - attachments and their extracted text;
  - existing extractions and summaries;
  - current classifications, including whether each one was set manually or automatically;
  - the sending log and thread history.
- An admin page to download it, and a way to pull it from the command line.
- The data stays local, outside git (it contains email content).

Status: done (6657235b). See docs/thread-export-api.md.

### 2. Find out what data and context the classifiers need

The goal is the current state of a thread at every event (every email, in
or out). Two things are recorded per email:

- **The email itself:** its type (`status_type`) and a note describing it
  (`status_text`), as today.
- **The cumulative thread state after this email:**
  - a JSON blob: what we asked for and the state of each item, dates given,
    case numbers, who we are waiting for, and so on;
  - a thread status type derived from the blob by code.
  - The blob starts from a consistent "initial request" blob built from our
    request.
  - Each event is evaluated from the previous state plus the new email, so
    the blob must carry everything the next evaluation needs.

Sub-steps, one change at a time:

1. Expand the data model with the cumulative state: storage per email,
   the blob schema, the thread status types and the derivation from the blob.
   Item and thread statuses are settled by interviewing the owner.
2. Local analysis with Opus 5.5 through headless Claude Code, run in the
   background: one call per event, token usage stored for every call. This is
   for local evaluation only; prod will use code rules and OpenAI models.

   Status: script built (`tools/analyze-threads.php`, docs/thread-analysis.md);
   first real runs pending.
3. A script that generates an HTML dashboard of the analyses and their
   token usage.
4. From the results, write down:
   - which statuses the flow needs;
   - which data must be extracted per email;
   - how much of the thread each classification needs as context;
   - which classifications a simple rule can do and which need AI.

### 2b. Feedback on the analysis, reported to prod

- A small system where the owner marks the correct labelling of a thread:
  email types, and the state blob at each event.
- Corrections are reported to prod as manual classifications, so prod gets
  better data, and the next local pull brings them back. The next analysis
  run treats manual states as fixed anchors.
- Confirmed states can also become cases in the existing local test bench
  (`organizer/src/bin/ai-check-models.php --prompt-tester`, `data/test-prompts/`).

### 3. Make a plan for adjusting the classifiers

Written from the findings in step 2. It covers:

- the classifier chain (rules first, AI where needed, AI with thread context where needed);
- the extraction of the data they use;
- what replaces or keeps today's classifiers: the rules in
  `ThreadEmailResponseClassifier`, and the summary prompt with keyword matching.

### 4. Make a plan for improving quality

- A local eval that scores classifiers and prompts against the dataset.
  It reports accuracy per status and, above all, the errors that do harm (for example, a
  rejection taken for a release, which archives an unfinished thread).
- A repeatable loop: look at the mistakes, make one change to a prompt,
  context or rule, re-measure, keep it only if it helps.
- New prod data is pulled and reviewed regularly, so the dataset keeps up.
- Server changes go out in shadow mode first (stored, not applied), and are
  applied when the numbers say so.

### 5. Use the classifications for thread handling

- Derive a thread state from the classified emails. Examples: waiting for the entity,
  we must act, overdue, released, rejected (complaint possible).
- Build on it: the norske-postlister archiving (8f58238a) and follow-ups, then
  other handling such as suggesting a complaint.

# Step 2d: read the attachments we can't read today

Plan for step 2d of
`docs/superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md`.
It is split into six changes, one commit each. This file is concrete for
change 1; later changes get their detail when they start.

## What we know before starting

From the local export (110 threads, 258 attachments, nearly all from the
2021-2023 election threads):

| Type | Count | With text | What they are |
|---|---|---|---|
| pdf | 191 | 165 | The 26 without text are mostly signed, scanned valgprotokoller and møtebøker, often the requested document itself |
| png / jpg | 54 | 0 | 2-7 KB on average: email signature logos |
| UNKNOWN | 14 | 0 | 13 are PDFs (`application/pdf` with a filename in the raw email) stored with an empty name, so the PDF extractor never picks them. All from 2021 |
| docx | 3 | 0 | real documents |

Today only PDFs get text (`pdftotext`, `ThreadEmailExtractorAttachmentPdf`),
and a failed extraction is never retried. Nothing reads txt, docx, xlsx or
images. The Docker image has no OCR tool and no PHP zip extension.

The sample is old and does not represent current norske-postlister mail
(the `.TXT` attachments of analysis run 3 are not in it), so the survey
runs in prod.

## Decisions, agreed with the owner

- **OCR uses OpenAI**, for PDFs without a text layer. Offpost already sends
  email content to OpenAI for summaries, so no new kind of data leaves.
- **OCR text is not machine-readable.** A PDF that needed OCR is a scan. When
  the request asks for a machine-readable format, receiving it is a partial
  denial (`PARTLY_RELEASED` with `NOT_MACHINE_READABLE`), as the prompt
  already says for a PDF without a text layer. The analysis input must
  therefore label OCR text as coming from a scan, so the model can both read
  the content and apply the rule.

## Changes

1. **File-type survey in prod.** A read-only section on `/thread-analysis`:
   attachments per file type and year of `datetime_received`, with columns
   for count, with text, no text (extraction ran, empty text), failed, not
   extracted, and average size. UNKNOWN rows also counted by whether `name`
   is empty. This decides the order and the size threshold of changes 3-5.
   - `ThreadAnalysisStats::getAttachmentTypeStats()`: one SQL query over
     `thread_email_attachments` joined to `thread_emails` and the
     extractions of each attachment (any `prompt_service`/`prompt_text`).
   - Rendered as a table on `/thread-analysis`, admin only like the page.
   - Tests: stats query against fixed rows; page renders the section.
2. **Lost PDF filenames.** Check whether the current import still loses
   them (the 2021 emails fold the `filename=` parameter onto the next line).
   Detect the type from the content (`%PDF-` magic bytes) when the name is
   missing, and repair the existing rows so the PDF extractor picks them.
3. **txt, csv, eml:** read directly, converting the charset to UTF-8.
4. **docx, xlsx, pptx:** read the XML inside the file. Needs the zip
   extension in the image; no new library.
5. **OCR of scanned PDFs with OpenAI.** A new extraction for PDFs whose
   `attachment_pdf` text is empty. Request logged in `openai_request_log`
   linked to the extraction. `ThreadEventAnalysis` shows the text with a
   label like `(OCR of a scanned PDF with no text layer: not
   machine-readable)`, and the prompt's machine-readable rule names it.
   Images are left out for now (mostly logos).
6. **Measure the effect.** Rerun the threads whose attachments got text and
   compare the analysis before and after.

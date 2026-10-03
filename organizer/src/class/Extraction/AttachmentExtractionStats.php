<?php
// organizer/src/class/Extraction/AttachmentExtractionStats.php
// Read-only survey of what the extraction pipeline has made of attachments,
// shown on /extraction-overview. See docs/np-api.md, "Attachment extraction
// survey". No writes here.

require_once __DIR__ . '/../Database.php';

class AttachmentExtractionStats {
    /**
     * File-type survey of attachments (step 2d, change 1; read-only, for
     * /extraction-overview): per filetype and
     * year received, how many attachments have extracted text, an extraction
     * that gave no text, only failed extractions, or no extraction at all.
     * Each attachment lands in exactly one of those four categories.
     *
     * @return array<int, array{filetype:string, year:?int, count:int, with_text:int,
     *   no_text:int, failed:int, not_extracted:int, empty_name:int,
     *   avg_size_bytes:int}> ordered by year desc, count desc.
     */
    public static function getAttachmentTypeStats(): array {
        $rows = Database::query(
            "WITH per_attachment AS (
                SELECT tea.id, tea.filetype, tea.size,
                       EXTRACT(YEAR FROM te.datetime_received)::int AS year,
                       (tea.name IS NULL OR tea.name = '') AS empty_name,
                       COUNT(tee.extraction_id) AS extractions,
                       COALESCE(BOOL_OR(btrim(COALESCE(tee.extracted_text, '')) <> ''), false) AS has_text,
                       COALESCE(BOOL_OR(tee.error_message IS NOT NULL), false) AS has_error,
                       COALESCE(BOOL_OR(tee.extraction_id IS NOT NULL AND tee.error_message IS NULL AND btrim(COALESCE(tee.extracted_text, '')) = ''), false) AS has_blank_ok
                FROM thread_email_attachments tea
                JOIN thread_emails te ON te.id = tea.email_id
                LEFT JOIN thread_email_extractions tee ON tee.attachment_id = tea.id
                GROUP BY tea.id, tea.filetype, tea.size, te.datetime_received, tea.name
             )
             SELECT filetype, year,
                    COUNT(*) AS count,
                    COUNT(*) FILTER (WHERE has_text) AS with_text,
                    COUNT(*) FILTER (WHERE NOT has_text AND has_blank_ok) AS no_text,
                    COUNT(*) FILTER (WHERE NOT has_text AND NOT has_blank_ok AND has_error) AS failed,
                    COUNT(*) FILTER (WHERE extractions = 0) AS not_extracted,
                    COUNT(*) FILTER (WHERE empty_name) AS empty_name,
                    COALESCE(ROUND(AVG(size)), 0) AS avg_size_bytes
             FROM per_attachment
             GROUP BY filetype, year
             ORDER BY year DESC NULLS LAST, count DESC, filetype"
        );
        foreach ($rows as &$row) {
            $row['year'] = $row['year'] === null ? null : (int) $row['year'];
            foreach (['count', 'with_text', 'no_text', 'failed', 'not_extracted', 'empty_name', 'avg_size_bytes'] as $key) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $rows;
    }
}

<?php

/**
 * Pure logic for comparing two EmailParseSnapshot results (current parser vs
 * replacement) field by field, and summarising many such comparisons.
 *
 * Used by bin/email-parse-compare.php on production mail. See
 * docs/email-parsing.md.
 */
class EmailParseComparison {

    public const EQUAL = 'equal';
    /** Same text when all whitespace runs are collapsed */
    public const WHITESPACE_ONLY = 'whitespace_only';
    public const CHANGED = 'changed';
    /** Current parser failed, replacement read it */
    public const OLD_FAILED_NEW_OK = 'old_failed_new_ok';
    /** Replacement failed - a regression whatever the current parser did */
    public const NEW_FAILED = 'new_failed';

    public const CATEGORIES = [self::EQUAL, self::WHITESPACE_ONLY, self::CHANGED, self::OLD_FAILED_NEW_OK, self::NEW_FAILED];

    private const SUBJECT_ERROR_PREFIX = 'Error getting subject - ';
    private const XFF_ERROR_PREFIX = 'exception-during-getting-x-forwarded-for';

    private int $examplesPerBucket;
    private int $total = 0;
    /** @var array<string, array<string, int>> */
    private array $counts = [];
    /** @var array<string, array<string, string[]>> */
    private array $examples = [];

    public function __construct(int $examplesPerBucket = 20) {
        $this->examplesPerBucket = $examplesPerBucket;
    }

    /**
     * @return array<string, string> field => category, for subject, x_forwarded_for, plain_text, html
     */
    public static function compare(array $old, array $new): array {
        $oldBodyFailed = $old['parse_error'] !== null;
        $newBodyFailed = $new['parse_error'] !== null;

        return [
            'subject' => self::category(
                $old['subject'], $new['subject'],
                self::isSubjectError($old['subject']), self::isSubjectError($new['subject'])
            ),
            'x_forwarded_for' => self::category(
                $old['x_forwarded_for'], $new['x_forwarded_for'],
                self::isXffError($old['x_forwarded_for']), self::isXffError($new['x_forwarded_for'])
            ),
            'plain_text' => self::category($old['plain_text'], $new['plain_text'], $oldBodyFailed, $newBodyFailed),
            'html' => self::category($old['html'], $new['html'], $oldBodyFailed, $newBodyFailed),
        ];
    }

    /**
     * Single-field comparison, for values outside a full snapshot (the subject
     * stored in imap_headers).
     */
    public static function compareSubject($old, $new): string {
        return self::category($old, $new, self::isSubjectError($old), self::isSubjectError($new));
    }

    private static function category($old, $new, bool $oldFailed, bool $newFailed): string {
        if ($newFailed) {
            return self::NEW_FAILED;
        }
        if ($oldFailed) {
            return self::OLD_FAILED_NEW_OK;
        }
        if ($old === $new) {
            return self::EQUAL;
        }
        if (is_string($old) && is_string($new) && self::collapseWhitespace($old) === self::collapseWhitespace($new)) {
            return self::WHITESPACE_ONLY;
        }
        return self::CHANGED;
    }

    private static function collapseWhitespace(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function isSubjectError($subject): bool {
        return is_string($subject) && str_starts_with($subject, self::SUBJECT_ERROR_PREFIX);
    }

    private static function isXffError(array $addresses): bool {
        foreach ($addresses as $address) {
            if (is_string($address) && str_starts_with($address, self::XFF_ERROR_PREFIX)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, string> $categories field => category, from compare()
     */
    public function add(string $emailId, array $categories): void {
        $this->total++;
        foreach ($categories as $field => $category) {
            $this->counts[$field][$category] = ($this->counts[$field][$category] ?? 0) + 1;
            if ($category !== self::EQUAL && count($this->examples[$field][$category] ?? []) < $this->examplesPerBucket) {
                $this->examples[$field][$category][] = $emailId;
            }
        }
    }

    public function counts(): array {
        return $this->counts;
    }

    public function exampleIds(): array {
        return $this->examples;
    }

    /**
     * Plain text report: regressions first, then a count table, then example ids.
     * Contains only counts and email ids, no email content.
     */
    public function report(int $examplesShown = 10): string {
        $out = "Compared {$this->total} emails\n\n";

        $regressions = '';
        foreach ($this->counts as $field => $byCategory) {
            if (!empty($byCategory[self::NEW_FAILED])) {
                $ids = array_slice($this->examples[$field][self::NEW_FAILED] ?? [], 0, $examplesShown);
                $regressions .= "  $field: {$byCategory[self::NEW_FAILED]}  " . implode(' ', $ids) . "\n";
            }
        }
        $out .= $regressions === ''
            ? "No regressions (new parser never failed).\n\n"
            : "REGRESSIONS (new parser failed):\n$regressions\n";

        $out .= sprintf('%-15s', 'field');
        foreach (self::CATEGORIES as $category) {
            $out .= '  ' . str_pad($category, max(5, strlen($category)), ' ', STR_PAD_LEFT);
        }
        $out .= "\n";
        foreach ($this->counts as $field => $byCategory) {
            $out .= sprintf('%-15s', $field);
            foreach (self::CATEGORIES as $category) {
                $out .= '  ' . str_pad((string)($byCategory[$category] ?? 0), max(5, strlen($category)), ' ', STR_PAD_LEFT);
            }
            $out .= "\n";
        }

        $out .= "\nExample email ids (inspect with --show=<id>):\n";
        foreach ($this->examples as $field => $byCategory) {
            foreach (self::CATEGORIES as $category) {
                if (!empty($byCategory[$category])) {
                    $out .= "  $field $category: " . implode(' ', array_slice($byCategory[$category], 0, $examplesShown)) . "\n";
                }
            }
        }
        return $out;
    }
}

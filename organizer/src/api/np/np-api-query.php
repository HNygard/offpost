<?php
// organizer/src/api/np/np-api-query.php
// Query-string helpers for the norske-postlister.no API endpoints.

/**
 * Every value of a repeatable query parameter, in order. Accepts both
 * `name=a&name=b` and `name[]=a&name[]=b`. PHP's $_GET keeps only the last
 * of repeated plain keys, which is why this reads the raw query string.
 *
 * @return string[]
 */
function npApiQueryValues(string $queryString, string $name): array {
    $values = [];
    if ($queryString === '') {
        return $values;
    }
    foreach (explode('&', $queryString) as $pair) {
        if ($pair === '') {
            continue;
        }
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        if ($key === $name || $key === $name . '[]') {
            $values[] = isset($parts[1]) ? urldecode($parts[1]) : '';
        }
    }
    return $values;
}

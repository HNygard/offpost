<?php

require_once __DIR__ . '/../../../tests/bootstrap.php';

/** Shared helpers for the OAuth server e2e tests. */
class OAuthE2E {
    /**
     * Clears the registration rate-limit bookkeeping (30 per IP per hour), so repeated
     * e2e runs within an hour do not hit 429. Call from setUp().
     */
    public static function clearRegistrationRateLimit(): void {
        Database::execute('DELETE FROM oauth_registration_attempts');
    }
}

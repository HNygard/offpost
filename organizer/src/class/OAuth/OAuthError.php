<?php

/**
 * An OAuth error in RFC 6749 section 5.2 form: $error is the error code
 * (e.g. invalid_request), the message is the error_description.
 */
class OAuthError extends Exception {
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($description);
    }
}

/**
 * An authorization request error that is reported to the client by redirecting
 * to its (already validated) redirect URI with error and state (RFC 6749 section 4.1.2.1).
 */
class OAuthRedirectError extends OAuthError {
    public function __construct(
        string $error,
        string $description,
        public readonly string $redirectUri,
        public readonly ?string $state
    ) {
        parent::__construct($error, $description);
    }
}

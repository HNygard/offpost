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

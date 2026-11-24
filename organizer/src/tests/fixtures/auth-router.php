<?php

define('PHPUNIT_RUNNING', true);

// Keep token exchange local while exercising the real callback implementation.
class AuthTestProviderStream {
    public $context;
    private string $response;
    private int $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool {
        $http = stream_context_get_options($this->context)['http'];
        $this->response = ($http['method'] ?? 'GET') === 'POST'
            ? '{"access_token":"test-access-token"}'
            : '{"sub":"auth-test-user"}';
        return true;
    }

    public function stream_read($count): string {
        $result = substr($this->response, $this->position, $count);
        $this->position += strlen($result);
        return $result;
    }

    public function stream_eof(): bool {
        return $this->position >= strlen($this->response);
    }

    public function stream_stat(): array {
        return [];
    }
}

stream_wrapper_unregister('http');
stream_wrapper_register('http', AuthTestProviderStream::class);

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/thread-view') {
    require __DIR__ . '/../../auth.php';
    requireAuth();
    exit;
}

require __DIR__ . '/../../webroot/index.php';

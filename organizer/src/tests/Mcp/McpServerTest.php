<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Mcp/McpToolProvider.php';
require_once __DIR__ . '/../../class/Mcp/McpServer.php';

class FakeMcpToolProvider implements McpToolProvider {
    public ?Throwable $throw = null;

    public function definitions(): array {
        return [[
            'name' => 'echo',
            'description' => 'Echo x',
            'inputSchema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
        ]];
    }

    public function call(string $name, array $args, string $userId): array {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($args['x'] === 'missing') {
            throw new McpNotFoundException();
        }
        return [['type' => 'text', 'text' => $args['x']]];
    }
}

class McpServerTest extends TestCase {
    private FakeMcpToolProvider $provider;
    private McpServer $server;

    protected function setUp(): void {
        $this->provider = new FakeMcpToolProvider();
        $this->server = new McpServer($this->provider);
    }

    private function rpc(string $method, array $params = [], int $id = 1): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    private function callEcho(string $x): array {
        return $this->server->handle($this->rpc('tools/call', ['name' => 'echo', 'arguments' => ['x' => $x]]), 'u1');
    }

    public function testInitializeEchoesSupportedVersion(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('initialize', ['protocolVersion' => '2025-06-18']), 'u1');

        // :: Assert
        $this->assertEquals('2025-06-18', $response['result']['protocolVersion']);
        $this->assertEquals('{"tools":{}}', json_encode($response['result']['capabilities']));
        $this->assertEquals('offpost', $response['result']['serverInfo']['name']);
        $this->assertEquals(McpServer::INSTRUCTIONS, $response['result']['instructions']);
        $this->assertSame(1, $response['id']);
        $this->assertEquals('2.0', $response['jsonrpc']);
    }

    public function testInitializeUnknownVersionGetsLatest(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('initialize', ['protocolVersion' => '1999-01-01']), 'u1');

        // :: Assert
        $this->assertEquals('2025-11-25', $response['result']['protocolVersion']);
    }

    public function testPing(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('ping'), 'u1');

        // :: Assert
        $this->assertEquals('{}', json_encode($response['result']));
    }

    public function testToolsListReturnsDefinitions(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('tools/list'), 'u1');

        // :: Assert
        $this->assertEquals(['tools' => $this->provider->definitions()], $response['result']);
    }

    public function testToolsCallReturnsContent(): void {
        // :: Act
        $response = $this->callEcho('hi');

        // :: Assert
        $this->assertSame(['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false], $response['result']);
    }

    public function testToolExceptionBecomesIsError(): void {
        // :: Act
        $response = $this->callEcho('missing');

        // :: Assert
        $this->assertSame(
            ['content' => [['type' => 'text', 'text' => 'Not found, or no access']], 'isError' => true],
            $response['result']
        );
    }

    public function testUnknownToolIsInvalidParams(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('tools/call', ['name' => 'nope', 'arguments' => []]), 'u1');

        // :: Assert
        $this->assertEquals(-32602, $response['error']['code']);
    }

    public function testMissingToolNameIsInvalidParams(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('tools/call', ['arguments' => []]), 'u1');

        // :: Assert
        $this->assertEquals(-32602, $response['error']['code']);
    }

    public function testNonObjectArgumentsIsInvalidParams(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('tools/call', ['name' => 'echo', 'arguments' => 'str']), 'u1');

        // :: Assert
        $this->assertEquals(-32602, $response['error']['code']);
    }

    public function testUnknownMethod(): void {
        // :: Act
        $response = $this->server->handle($this->rpc('resources/list'), 'u1');

        // :: Assert
        $this->assertEquals(-32601, $response['error']['code']);
    }

    public function testNotificationReturnsNull(): void {
        // :: Act
        $response = $this->server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], 'u1');

        // :: Assert
        $this->assertNull($response);
    }

    public function testParseError(): void {
        // :: Act
        $response = $this->server->handle(McpServer::PARSE_ERROR, 'u1');

        // :: Assert
        $this->assertEquals(-32700, $response['error']['code']);
        $this->assertNull($response['id']);
    }

    public function testBatchArrayRejected(): void {
        // :: Act
        $response = $this->server->handle([$this->rpc('ping'), $this->rpc('ping', [], 2)], 'u1');

        // :: Assert
        $this->assertEquals(-32600, $response['error']['code']);
    }

    public function testMissingMethodRejected(): void {
        // :: Act
        $response = $this->server->handle(['jsonrpc' => '2.0', 'id' => 5], 'u1');

        // :: Assert
        $this->assertEquals(-32600, $response['error']['code']);
        $this->assertSame(5, $response['id']);
    }

    public function testUnexpectedThrowableIsInternalError(): void {
        // :: Setup
        $this->provider->throw = new RuntimeException('db down');

        // :: Act
        $previousLog = ini_set('error_log', '/dev/null');
        $response = $this->callEcho('hi');
        ini_set('error_log', $previousLog);

        // :: Assert
        $this->assertEquals(-32603, $response['error']['code']);
        $this->assertEquals('Internal error', $response['error']['message']);
    }
}

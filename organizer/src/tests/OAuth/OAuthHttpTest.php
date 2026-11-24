<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../class/OAuth/OAuthHttp.php';

class OAuthHttpTest extends TestCase {
    public function testClientIpUsesCfHeaderOnlyBehindPrivateProxy() {
        // :: Act / Assert
        $this->assertEquals('198.51.100.7', OAuthHttp::clientIp(['REMOTE_ADDR' => '172.30.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']));
        $this->assertEquals('203.0.113.5', OAuthHttp::clientIp(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']), 'Public peer: header is spoofable');
        $this->assertEquals('198.51.100.7', OAuthHttp::clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']));
        $this->assertEquals('172.30.0.1', OAuthHttp::clientIp(['REMOTE_ADDR' => '172.30.0.1']));
    }

    public function testClientIpPrivateRanges() {
        // :: Setup
        $cf = ['HTTP_CF_CONNECTING_IP' => '198.51.100.7'];
        $cases = [
            '10.1.2.3' => '198.51.100.7',
            '172.16.0.1' => '198.51.100.7',
            '172.31.255.255' => '198.51.100.7',
            '172.32.0.1' => '172.32.0.1',
            '192.168.1.1' => '198.51.100.7',
            '::1' => '198.51.100.7',
            'fd12:3456::1' => '198.51.100.7',
            'fc00::1' => '198.51.100.7',
            '2001:db8::1' => '2001:db8::1',
            'not-an-ip' => 'not-an-ip',
        ];

        // :: Act / Assert
        foreach ($cases as $remote => $expected) {
            $this->assertEquals($expected, OAuthHttp::clientIp(['REMOTE_ADDR' => $remote] + $cf), "REMOTE_ADDR $remote");
        }
    }
}

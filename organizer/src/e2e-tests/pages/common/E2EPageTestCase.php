<?php

require_once __DIR__ . '/../../../tests/bootstrap.php';

use PHPUnit\Framework\TestCase;
class E2EPageTestCase extends TestCase {

    private static $session_cookies = array();

    protected function renderPage($path, $user = 'dev-user-id', $method = 'GET', $expected_status = '200 OK', $post_data = null) {
        $url = 'http://localhost:25081' . $path;
        if ($user !== null) {
            if (!isset(self::$session_cookies[$user])) {
                $session_cookie = $this->authenticate($user);
                self::$session_cookies[$user] = $session_cookie;
            }
            $session_cookie = self::$session_cookies[$user];
            $response = $this->curl($url, $method, $session_cookie, post_data: $post_data);
        } else {
            $response = $this->curl($url, $method, post_data: $post_data);
        }

        if ($expected_status != null) {
            try {
                $this->assertEquals('HTTP/1.1 ' . $expected_status, trim(explode("\n", $response->headers, 2)[0]));
            }
            catch (Exception $e) {
                echo "\n\n";
                echo 'Failed asserting status code: ' . $expected_status . "\n";
                echo "\n";
                echo "Full response from failed request:\n";
                echo html_entity_decode(preg_replace('/^/m', '    ', $response->body )). "\n";
                echo "--- End of full response\n\n";
                throw $e;
            }
        }


        return $response;
    }

    private function authenticate($user) {
        if ($user != 'dev-user-id') {
            throw new Exception('Unknown user: ' . $user);
        }

        $response = $this->curl('http://localhost:25081?test-authenticate', 'GET');

        if (count($response->cookies) != 1) {
            echo "Full response :\n";
            var_dump($response);
            echo "--- End of full response\n";
            throw new Exception('No session cookie found');
        }
        return $response->cookies[0];
    }

    private function curl($url, $method = 'GET', $session_cookie = null, $headers = array(), $post_data = null) {
        //echo date('Y-m-d H:i:s') . " - $method $url      $session_cookie\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        if ($session_cookie !== null) {
            $headers[] = 'Cookie: ' . $session_cookie;
        }
        $headers[] = 'User-Agent: Offpost E2E Test';

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Add POST data if method is POST
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        }

        if (str_contains($url, 'test-authenticate')) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $cookieJar = tempnam(sys_get_temp_dir(), 'cookie');
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }

        $res = curl_exec($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($res, 0, $header_size);
        $body = substr($res, $header_size);

        $return = new stdClass();
        $return->headers = $header;
        $return->body = $body;

        if ($res === false) {
            echo "Full response .. :\n";
            var_dump($return);
            echo "--- End of full response\n";
            throw new Exception("Request to [$url], curl error: " . curl_error($ch));
        }

        curl_close($ch);

        if (str_contains($url, 'test-authenticate')) {
            $lines = explode("\n", $header);
            foreach($lines as $line) {
                if (str_starts_with($line, 'Set-Cookie: PHPSESSID=')) {
                    $cookie = explode(';', $line)[0];
                    $cookie = explode(': ', $cookie)[1];
                    $return->cookies[] = $cookie;
                }
            }
        }

        return $return;
    }
}

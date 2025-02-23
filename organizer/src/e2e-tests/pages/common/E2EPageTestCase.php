<?php

use PHPUnit\Framework\TestCase;
class E2EPageTestCase extends TestCase {

    private static $session_cookies = array();

    protected function renderPage($path, $user = 'dev-user-id', $method = 'GET') {
        $url = 'http://localhost:25081' . $path;
        if ($user !== null) {
            if (!isset(self::$session_cookies[$user])) {
                $session_cookie = $this->authenticate($user);
                self::$session_cookies[$user] = $session_cookie;
            }
            $session_cookie = self::$session_cookies[$user];
            $response = $this->curl($url, $method, $session_cookie);
        } else {
            $response = $this->curl($url, $method);
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

    private function curl($url, $method = 'GET', $session_cookie = null, $headers = array()) {
        //echo date('Y-m-d H:i:s') . " - $method $url      $session_cookie\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        if ($session_cookie !== null) {
            $headers[] = 'Cookie: ' . $session_cookie;
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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
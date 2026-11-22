<?php

/**
 * TEST-ONLY RSA key used to sign MCP bearer tokens in tests.
 *
 * This key is public (it is in the repository) and must never be trusted in
 * production. It is trusted only when $environment == 'development' or under
 * PHPUnit.
 */
class McpTestKeys {
    public const KID = 'offpost-mcp-test-1';

    public const PRIVATE_KEY = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAoE8QnsoWyAw6tyKJqhu2fWXF30YY3ZcgxZ9ClILtUvUIgCvm
4ZMuICN6YWL12uPxcSdAAPP0ZRHsaBMwwSHlXe44kXH8YUTQdD6xnHmVRqmdLrWC
wQONF9tCtbUksPdPuFFeAooHqdKWikonv2xZLFGAx32tu+tHAzzT/+SCoyerkEtz
Ty+MubIhL01QhxhR69qCGHwQpo0EKp0RXLK9LJbO/r17PS8mWQ60zsD6g3teH/4d
4WtiivOh0Nouw8Ff6RcOoZ0F4yBCGv6P4YUNpnQcV5ZX1CEBEjyqIo68jfSZKtiI
by5076HgekiY+tZviKHJASO/uDOraXXn9c8KwwIDAQABAoIBAEi918LV3KSFuBbl
myYPUS1xguofeqUCMKK785BEIMBl46eVRDzTdjirIJsUNo9Oo34+2zITgLBkA6vu
GU9pvBR1RkHpQSY5/YTh9eIU4YGg8WwCfQRsQhezLIAbZDzQ2+G9OjVYwMV0g07Y
+1RHK+eKTC85fh4pUp3Lej9BR24UmETSHMJb/JSSmiZTUl+JRW6naGjcpAI7EnUY
ABCujB/AEPVcNEpW9/0d4p2IpmB1dMG8nKL9lALLNboINW0isXq7oFmjIPCfHji4
BjL1KGxVTqzRG2xoPPeyOKhJdDSphUzj0hBi7TLkFKx+Y6Es5oWicN4Yl8WfCSc2
c5l/4cECgYEAz17QvYqNZIgxlJBY7EwXbhgB8FryEFEhc3kgwtmHjJcI77abWotE
NZNEd9l0xX/PseCo7hz+LRUgZ1zo1BZ5PkAV4o3p+d8/R8JeFRqe5XAgvcIMFcoy
GpeW+vVRtb7UovZPZxETVY2sAHGBrMTy99D40BvgVOIJ4iB1G8MEJjECgYEAxeb6
XFa7D9xjYJ+Yos6XRblktO3+91+RpLK0PB5kZSDE+lFD+roM8Pp+DhkzlmbKXD8u
6PCaiFtnV4n7LC3ovm0Y1hWQNkAsH3wXgp6VEfjhYlG09EGmghHn4XaZZEeCODqe
76SeTX3OfH9pElUvPg2QkkcGunsPnBacvhSznzMCgYAdmdA8Linuvs4tAcnfuSDj
E0Q7lg/Vqm8W96Roi/qgY/LfnPZiH10WNLZiUUUs6AiywHn8u2DA+LIObyHBHJAk
BavaEh3jEC4NRT5+zrT91VqnG6rz9U/foeHoYtpOEjZyKKy+7p4O6VEzIwC6s8Zm
/4p00nXcSAPXJoDw/I58EQKBgH5DSNajC0QqU0kxafvtnPwCNatt8O85DT4sOevl
ZB5VUvd1485S57DT0JsEHzLFMDS8pxw9bgqzbnKeeR8Nx2G/T0o/hbvnI3+O950B
upA13u6QvMfeHvL7o5roxSMCsKVZWVACEnHUY2pxC8ODj9miY+u/3x4ZJ7NpheWM
ZbGbAoGBAJiZeWKTbyVX4oRfd9RbCSfCInnY9yEd8ZDi1SShl3pKg3af4uLBvMVS
hBR1e2UWRSBL44KmdcoywAUuatS7hqHUqQnP3EhQVtY7cEFUup9lmrr1eHK7Swyn
P8tWBq8RJxX3O5s33qlT+QWs4sBpdD3Fm0YHrimY5dzE1mLWhmN0
-----END RSA PRIVATE KEY-----
PEM;

    /** A second, unrelated key. Tokens signed with it must be rejected. */
    public const OTHER_PRIVATE_KEY = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAwxhnlpePFYGjpXVzHlUZrsDoyUDzKtPkduDCvDgOFHXDt161
lOgWEE9GBJLD2Rrv0VmNn9QiuBrBBmZ7k57XVtEXpvVd72PiEP7GwMA5bT57GOPV
gJPFqvz1pFV0y501NSV37aUeVFF6DloQS6A5rBDROy4BmcKurhZXHJyo7dgPY4zo
DX9VBS5zHU9R+yTTDwxoxKgAZVrzRKU9ravTaqpXBJwAY6033nj4++urAePYCb2g
mv7RHSz1RweFpSjEKp49aLnSdEtG2ytGYmZl5RlEo3H/6pCEmaT1CChaWyLds2iS
cwhHYRF3+QuJtA7y/WBd89YHIh/N+Vmg8yPZTwIDAQABAoIBADaMWEpOzf7hZHQa
xrkwaiHmZi//LP5IiJSEe25M7gLienqGzJZ53dFpmNYmgwPs+Ci8tVB+68Y2FMpJ
VTwNKYBorghMipsofkjqM4ApPuVAlwJ6kXOF3brVR4lPK3zS09GsTD/x7uz8avgg
TnYWsoldmmLFlkT7ngOm02x4kPMO/Nsf5k3kzVlJ65EWcce4IZdMTbMxRWukbhHP
ZapRLwW9jMWVZPefd6rwafp2CpfqhOJxwgeYSdA3p6B0JslUnpXdOPf6xFnHhuAX
BhqymXXClLbVVvCk73HabKEwV3r9vT2eFFna+ckSQFCaIG5m93bQxCc8D71lECYt
H8NergECgYEA70Lv1fVWhKFBPytE+8YslzEzjkLv4JFHJ5gl/z1TohGy0C9Jwb9N
DM3kLIkAVVKcHvxtHl1nDOlT7pP3G3zK5UVS/9roHwENkXyAVVAa0WpCN6MbuG9t
wBS4kiRnGtqnqNNAeVEz3/mkzgi0IQQXHDetDyXHj9d6hQ+qJrfHx0ECgYEA0L54
80cdFtRflV0HOR4WOYcIyYZ70WltPYg1X7ieBx1xikEW3H9RtFXuT3RQ6k5dDxku
PvBGNqTSmg+4ohNrOldo3+7kP+Bh2wpQUx9AEN7QbAndRIwgQG3C/4j8wQxIksSd
pgObPZpVV1wbCnYU7Ywxj9pKuOC8dGaCodiUjI8CgYAgApSTy38Hv4s7KE7SYHEW
yT5cihgNs2IRqglrXIkT5cpGkA0OHsnwLJuaWbnxsFuyyZLPvS9MSusvwBwPUWLT
ybKRCHiFrV5jWITS5SSxX8X8K5ZJ1p6YVC1dYAFrVlWYAKtfj72g+YVTC1cOE74D
yG8e168bT3jU1IOvKmdQAQKBgQCaXG+2ftzs9+ki26MuwqfurIN7+v3u0oZcnS/S
9AOwAT7n3NAaB/CUaYMLsFjxmzdHpfhDxIw3dlTiZkPDXpbJkr/T8KVK8xOJ1aum
fBJ1Jz961SSRvwlOe5xaOKOn4fx8w/ZMQJh4ynrXWh4I817wdt5joSnmYxIZBYEO
S8k10wKBgQDHtmuF298sU6kItwe6lLxCsFr8GimXGqjilNJlsxHkuA2aKXSGaT7Z
dO0B+G+jEvnR0A8xPCYRNHoJxfM3k2ouTrawPWXrsJqgqoOLblfEa5jng97U+sng
lCR287PixvnHJ1ruqQm1E+S0B6omgA2E9Rk6DHw1kinkFQrgeSSYqQ==
-----END RSA PRIVATE KEY-----
PEM;

    /** Decoded JWKS containing the public part of PRIVATE_KEY. */
    public static function jwks(): array {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::PRIVATE_KEY));
        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KID,
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]];
    }

    /** Sign claims as an RS256 JWT with header kid. */
    public static function token(array $claims, string $privateKey = self::PRIVATE_KEY): string {
        return \Firebase\JWT\JWT::encode($claims, $privateKey, 'RS256', self::KID);
    }

    private static function base64Url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

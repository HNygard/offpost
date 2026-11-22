<?php

require_once __DIR__ . '/McpTokenVerifier.php';

/**
 * Short-lived signed download tokens for attachments:
 * base64url(json{sub, tid, aid, exp}) . '.' . base64url(hmac_sha256(payload_part, secret)).
 * A valid token only proves the link was issued by this server; the download
 * endpoint re-checks access for sub when the link is used.
 */
class McpAttachmentLink {
    public const TTL_SECONDS = 300;

    public function __construct(private string $secret) {
    }

    public function create(string $sub, string $threadId, string $attachmentId, int $now): string {
        $payload = self::base64UrlEncode(json_encode([
            'sub' => $sub,
            'tid' => $threadId,
            'aid' => $attachmentId,
            'exp' => $now + self::TTL_SECONDS,
        ]));
        return $payload . '.' . self::base64UrlEncode($this->sign($payload));
    }

    /**
     * @return array{sub: string, tid: string, aid: string}
     * @throws McpAuthException on bad format, bad signature or expiry
     */
    public function verify(string $token, int $now): array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new McpAuthException('Malformed attachment link');
        }
        [$payloadPart, $signaturePart] = $parts;
        $signature = self::base64UrlDecode($signaturePart);
        if ($signature === null || !hash_equals($this->sign($payloadPart), $signature)) {
            throw new McpAuthException('Bad attachment link signature');
        }
        $json = self::base64UrlDecode($payloadPart);
        $claims = $json === null ? null : json_decode($json, true);
        if (!is_array($claims) || !is_string($claims['sub'] ?? null) || !is_string($claims['tid'] ?? null)
            || !is_string($claims['aid'] ?? null) || !is_int($claims['exp'] ?? null)) {
            throw new McpAuthException('Malformed attachment link');
        }
        if ($claims['exp'] < $now) {
            throw new McpAuthException('Attachment link expired');
        }
        return ['sub' => $claims['sub'], 'tid' => $claims['tid'], 'aid' => $claims['aid']];
    }

    private function sign(string $payloadPart): string {
        return hash_hmac('sha256', $payloadPart, $this->secret, true);
    }

    private static function base64UrlEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): ?string {
        if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}

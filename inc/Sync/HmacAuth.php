<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * HMAC-SHA256 Authentication für Sync-Requests zwischen Peers.
 *
 * Header-Format:
 *   Authorization: RHBP-HMAC peer="<id>", ts="<unix>", sig="<base64>"
 *   Content-SHA256: <hex>
 *
 * Signature-Base: METHOD\nPATH\nTS\nSHA256(BODY)
 * Key: Peer-Token (64-char hex shared secret)
 */
final class HmacAuth
{
    public const AUTH_HEADER = 'Authorization';
    public const CONTENT_HASH_HEADER = 'Content-SHA256';
    public const AUTH_PREFIX = 'RHBP-HMAC';
    public const MAX_TIMESTAMP_DRIFT = 300; // 5 Minuten
    private const REPLAY_TRANSIENT_PREFIX = 'rhbp_sync_seen_';

    public function __construct(private readonly PeerRegistry $registry)
    {
    }

    public function sign(string $method, string $path, string $body, string $token, int $timestamp): string
    {
        $base = $this->signatureBase($method, $path, $body, $timestamp);

        return base64_encode(hash_hmac('sha256', $base, $token, true));
    }

    /**
     * @return array<string, string>
     */
    public function buildHeaders(string $method, string $path, string $body, Peer $peer): array
    {
        $timestamp = time();
        $signature = $this->sign($method, $path, $body, $peer->token, $timestamp);
        $bodyHash = hash('sha256', $body);

        return [
            self::AUTH_HEADER => sprintf(
                '%s peer="%s", ts="%d", sig="%s"',
                self::AUTH_PREFIX,
                $peer->id,
                $timestamp,
                $signature
            ),
            self::CONTENT_HASH_HEADER => $bodyHash,
        ];
    }

    /**
     * Verifiziert einen WP_REST_Request anhand der HMAC-Header.
     * Geeignet als permission_callback.
     */
    public function verifyRestRequest(\WP_REST_Request $request): ?Peer
    {
        $method = $request->get_method();
        $path = self::canonicalPath($request->get_route());
        $body = (string) $request->get_body();

        $headers = [
            self::AUTH_HEADER => (string) $request->get_header(self::AUTH_HEADER),
            self::CONTENT_HASH_HEADER => (string) $request->get_header(self::CONTENT_HASH_HEADER),
        ];

        return $this->verify($method, $path, $body, $headers);
    }

    public static function canonicalPath(string $route): string
    {
        return '/' . ltrim(rest_get_url_prefix(), '/') . '/' . ltrim($route, '/');
    }

    /**
     * @param array<string, string> $headers
     */
    public function verify(string $method, string $path, string $body, array $headers): ?Peer
    {
        $authHeader = $this->findHeader($headers, self::AUTH_HEADER);
        if ($authHeader === null) {
            return null;
        }

        $parsed = $this->parseAuthHeader($authHeader);
        if ($parsed === null) {
            return null;
        }

        [$peerId, $timestamp, $signature] = $parsed;

        if (abs(time() - $timestamp) > self::MAX_TIMESTAMP_DRIFT) {
            return null;
        }

        $peer = $this->registry->get($peerId);
        if ($peer === null) {
            return null;
        }

        $expected = $this->sign($method, $path, $body, $peer->token, $timestamp);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        // Zusätzlich: Content-Hash prüfen falls vorhanden
        $contentHash = $this->findHeader($headers, self::CONTENT_HASH_HEADER);
        if ($contentHash !== null) {
            $actualHash = hash('sha256', $body);
            if (!hash_equals($actualHash, $contentHash)) {
                return null;
            }
        }

        // Replay-Schutz: jede gültige Signatur darf nur EINMAL akzeptiert werden.
        // Das Drift-Fenster allein lässt einen mitgeschnittenen Request bis zu 5 Min
        // wiederholen. Wir merken gesehene Signaturen für die Fensterdauer.
        if (!$this->markSignatureSeen($peerId, $signature)) {
            return null;
        }

        return $peer;
    }

    /**
     * Speichert eine Signatur als "gesehen" und liefert false, wenn sie das schon war.
     * Fail-open nur wenn die WP-Transient-API fehlt (CLI/Unit-Test ohne WP-Kontext).
     */
    private function markSignatureSeen(string $peerId, string $signature): bool
    {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }

        $key = self::REPLAY_TRANSIENT_PREFIX . hash('sha256', $peerId . '|' . $signature);

        if (get_transient($key) !== false) {
            return false;
        }

        set_transient($key, 1, self::MAX_TIMESTAMP_DRIFT + 10);

        return true;
    }

    private function signatureBase(string $method, string $path, string $body, int $timestamp): string
    {
        return sprintf(
            "%s\n%s\n%d\n%s",
            strtoupper($method),
            $path,
            $timestamp,
            hash('sha256', $body)
        );
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function parseAuthHeader(string $header): ?array
    {
        $prefix = self::AUTH_PREFIX . ' ';
        if (!str_starts_with($header, $prefix)) {
            return null;
        }

        $payload = substr($header, strlen($prefix));

        if (preg_match('/peer="([^"]+)"/', $payload, $peerMatch) !== 1) {
            return null;
        }
        if (preg_match('/ts="(\d+)"/', $payload, $tsMatch) !== 1) {
            return null;
        }
        if (preg_match('/sig="([^"]+)"/', $payload, $sigMatch) !== 1) {
            return null;
        }

        return [$peerMatch[1], (int) $tsMatch[1], $sigMatch[1]];
    }

    /**
     * @param array<string, string> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        $nameLower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return (string) $value;
            }
        }

        return null;
    }
}

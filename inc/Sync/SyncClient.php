<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Low-level HTTP-Client für ausgehende Sync-Requests zu anderen Peers.
 *
 * Signiert jeden Request mit HMAC-SHA256 (Shared-Secret aus Peer-Token),
 * leitet ihn über `wp_remote_*` an den Ziel-Peer und liefert das Ergebnis
 * als strukturiertes Response-Objekt.
 */
final class SyncClient
{
    public const DEFAULT_TIMEOUT = 60;
    public const DOWNLOAD_TIMEOUT = 600;

    /**
     * Timeout für einen einzelnen Range-Chunk. Bewusst kurz: ein 8-MB-Chunk ist in
     * Sekunden geladen, ein zu langer Timeout würde einen hängenden Chunk unnötig lange
     * blockieren (die Chunk-Schleife resumt bei Abbruch eh ab dem letzten Offset).
     */
    public const CHUNK_TIMEOUT = 120;

    /**
     * Timeout für Calls, die auf der Gegenseite synchron eine lange Operation
     * auslösen (Export-ZIP bauen, Import einspielen). Diese dauern bei großen
     * Datenmengen leicht über das DEFAULT_TIMEOUT von 60s hinaus.
     */
    public const OPERATION_TIMEOUT = 600;

    public function __construct(private readonly HmacAuth $auth)
    {
    }

    /**
     * @param array<string, mixed>|null $bodyData
     */
    public function request(Peer $peer, string $method, string $route, ?array $bodyData = null, int $timeout = self::DEFAULT_TIMEOUT): SyncResponse
    {
        $body = $bodyData !== null ? (string) wp_json_encode($bodyData) : '';
        $contentType = $bodyData !== null ? 'application/json' : null;

        return $this->requestRaw($peer, $method, $route, $body, $contentType, $timeout);
    }

    /**
     * Low-level request mit raw-body. Wird für Binary-Uploads (Chunks) genutzt.
     */
    public function requestRaw(
        Peer $peer,
        string $method,
        string $route,
        string $body,
        ?string $contentType = null,
        int $timeout = self::DEFAULT_TIMEOUT
    ): SyncResponse {
        $method = strtoupper($method);
        $path = HmacAuth::canonicalPath($route);

        $headers = $this->auth->buildHeaders($method, $path, $body, $peer);
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $url = untrailingslashit($peer->url) . $path;

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'sslverify' => apply_filters('rh-blueprint/sync/sslverify', true, $peer),
        ];
        if ($body !== '') {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new SyncResponse(0, '', [], $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        /** @var array<string, string> $responseHeaders */
        $responseHeaders = (array) wp_remote_retrieve_headers($response);

        return new SyncResponse($status, $responseBody, $responseHeaders, null);
    }

    /**
     * Streamt einen HTTP-Response in eine lokale Datei.
     *
     * Wenn `$onProgress` gesetzt ist und cURL verfügbar ist, wird der Callback
     * während des Downloads regelmäßig mit (bytes_now, bytes_total) aufgerufen
     * Damit kann das Frontend Live-Progress anzeigen.
     *
     * @param callable(int, int): void|null $onProgress
     * @throws \RuntimeException wenn der Download fehlschlaegt.
     */
    public function downloadTo(string $url, string $destination, ?Peer $peer = null, ?callable $onProgress = null): int
    {
        if ($onProgress !== null && function_exists('curl_init')) {
            return $this->downloadWithProgress($url, $destination, $peer, $onProgress);
        }

        $args = [
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'stream' => true,
            'filename' => $destination,
            'sslverify' => apply_filters('rh-blueprint/sync/sslverify', true, $peer),
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Download fehlgeschlagen: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Download fehlgeschlagen mit HTTP-Status ' . $status);
        }

        if (!is_file($destination) || filesize($destination) === 0) {
            throw new \RuntimeException('Download-Datei leer oder nicht vorhanden.');
        }

        return $status;
    }

    /**
     * Streamt einen einzelnen HTTP-Range in $partFile (Byte-Bereich [$start, $start+$length-1]).
     *
     * Der Download-Endpoint ist token-authentifiziert (Query-Param), NICHT HMAC-signiert,
     * darum ein direkter `wp_remote_get` (wie {@see downloadTo()}), erweitert um den Range-Header.
     * Der Body wird per Streaming direkt in $partFile geschrieben (kein RAM-Buffer).
     *
     * @return array{status:int, bytes:int} status 206 (Teilstück, Normalfall) oder 200
     *   (Quelle ohne Range-Support / Proxy ignoriert Range, dann enthält $partFile die VOLLE Datei).
     * @throws \RuntimeException bei Transport-Fehler oder unerwartetem HTTP-Status.
     */
    public function downloadRange(string $url, ?Peer $peer, int $start, int $length, string $partFile): array
    {
        $end = $start + $length - 1;

        $args = [
            'timeout' => self::CHUNK_TIMEOUT,
            'stream' => true,
            'filename' => $partFile,
            'headers' => ['Range' => 'bytes=' . $start . '-' . $end],
            'sslverify' => apply_filters('rh-blueprint/sync/sslverify', true, $peer),
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Download fehlgeschlagen: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 206 && $status !== 200) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Download fehlgeschlagen mit HTTP-Status ' . $status);
        }

        $bytes = is_file($partFile) ? (int) filesize($partFile) : 0;

        return ['status' => $status, 'bytes' => $bytes];
    }

    /**
     * cURL-basierter Download mit Live-Progress-Callback.
     *
     * @param callable(int, int): void $onProgress
     */
    private function downloadWithProgress(string $url, string $destination, ?Peer $peer, callable $onProgress): int
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_error -- cURL für Fortschrittsanzeige beim Download, wp_remote_get bietet keinen Progress-Callback, und Streaming großer Dateien (WP_Filesystem lädt komplett in den RAM).
        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Ziel-Datei kann nicht zum Schreiben geöffnet werden: ' . $destination);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new \RuntimeException('cURL konnte nicht initialisiert werden.');
        }

        $sslVerify = (bool) apply_filters('rh-blueprint/sync/sslverify', true, $peer);

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::DOWNLOAD_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($_resource, $totalBytes, $downloadedBytes) use ($onProgress): int {
            if ($downloadedBytes > 0 || $totalBytes > 0) {
                $onProgress((int) $downloadedBytes, (int) $totalBytes);
            }
            return 0;
        });

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        unset($ch);
        fclose($fp);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_error

        if ($ok === false) {
            wp_delete_file($destination);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Download fehlgeschlagen: ' . $error);
        }

        if ($status !== 200) {
            wp_delete_file($destination);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException('Download fehlgeschlagen mit HTTP-Status ' . $status);
        }

        if (!is_file($destination) || filesize($destination) === 0) {
            throw new \RuntimeException('Download-Datei leer oder nicht vorhanden.');
        }

        return $status;
    }
}

final class SyncResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}

<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhDbEngine\Exporter;
use RhDbEngine\Storage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SyncController
{
    public const NAMESPACE = 'rhbp/v1';
    public const DOWNLOAD_TRANSIENT_PREFIX = 'rhbp_sync_dl_';
    public const DOWNLOAD_TTL = 600; // 10 Minuten
    public const IMPORT_SESSION_PREFIX = 'rhbp_import_session_';
    public const IMPORT_SESSION_TTL = 1800; // 30 Minuten
    public const IMPORT_SESSION_LENGTH = 32;

    public function __construct(
        private readonly HmacAuth $auth,
        private readonly Exporter $exporter,
        private readonly Storage $storage,
        private readonly PeerRegistry $peers,
        private readonly JobScheduler $scheduler,
    ) {
    }

    /**
     * Holt den authentifizierten Peer (aus checkAuth via `_peer_id`).
     * Liefert null, wenn der Peer zwischenzeitlich entfernt wurde.
     */
    private function authenticatedPeer(WP_REST_Request $request): ?Peer
    {
        $peerId = (string) $request->get_param('_peer_id');

        return $peerId !== '' ? $this->peers->get($peerId) : null;
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/sync/manifest', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handleManifest'],
            'permission_callback' => [$this, 'checkAuth'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/export', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handleExport'],
            'permission_callback' => [$this, 'checkAuth'],
            'args' => [
                'include_uploads' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/download', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handleDownload'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/import/init', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handleImportInit'],
            'permission_callback' => [$this, 'checkAuth'],
        ]);

        register_rest_route(
            self::NAMESPACE,
            '/sync/import/(?P<session_id>[A-Za-z0-9]{' . self::IMPORT_SESSION_LENGTH . '})/chunk/(?P<index>\d+)',
            [
                'methods' => 'PUT',
                'callback' => [$this, 'handleImportChunk'],
                'permission_callback' => [$this, 'checkAuth'],
                'args' => [
                    'session_id' => ['type' => 'string', 'required' => true],
                    'index' => ['type' => 'integer', 'required' => true],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/sync/import/(?P<session_id>[A-Za-z0-9]{' . self::IMPORT_SESSION_LENGTH . '})/complete',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handleImportComplete'],
                'permission_callback' => [$this, 'checkAuth'],
                'args' => [
                    'session_id' => ['type' => 'string', 'required' => true],
                    'profile' => ['type' => 'object', 'default' => null],
                ],
            ]
        );

        // Status eines auf dieser Seite laufenden Remote-Import-Tick-Jobs (vom Push-Client gepollt).
        register_rest_route(
            self::NAMESPACE,
            '/sync/import/job/(?P<remote_job_id>[a-f0-9]{32})/status',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'handleImportJobStatus'],
                'permission_callback' => [$this, 'checkAuth'],
                'args' => [
                    'remote_job_id' => ['type' => 'string', 'required' => true],
                ],
            ]
        );
    }

    public function handleImportJobStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $jobId = (string) $request->get_param('remote_job_id');
        $job = JobState::load($jobId);

        if ($job === null) {
            return new WP_Error('rhbp_job_not_found', __('Import-Job nicht gefunden.', 'rh-sync'), ['status' => 404]);
        }

        // Ein Peer darf nur den Import-Job pollen, den er selbst gestartet hat.
        if ($job->peerId !== (string) $request->get_param('_peer_id')) {
            return new WP_Error('rhbp_job_forbidden', __('Kein Zugriff auf diesen Import-Job.', 'rh-sync'), ['status' => 403]);
        }

        return new WP_REST_Response([
            'phase' => $job->stage,
            'message' => $job->message,
            'bytes_now' => $job->bytesNow,
            'bytes_total' => $job->bytesTotal,
            'last_update_at' => $job->lastUpdateAt,
            'stale' => $job->isStale(),
            'error' => $job->error,
            'summary' => $job->summary,
        ]);
    }

    public function checkAuth(WP_REST_Request $request): bool|WP_Error
    {
        $peer = $this->auth->verifyRestRequest($request);

        if ($peer === null) {
            return new WP_Error(
                'rhbp_unauthorized',
                __('HMAC-Verifizierung fehlgeschlagen.', 'rh-sync'),
                ['status' => 401]
            );
        }

        $request->set_param('_peer_id', $peer->id);

        return true;
    }

    public function handleManifest(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $uploads = wp_upload_dir();
        $uploadBase = (string) $uploads['basedir'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Manifest-Statistik, direkte Query auf interne posts-Tabelle, Caching für eine Live-Statusabfrage nicht sinnvoll.
        $postCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Manifest-Statistik, direkte Query auf interne posts-Tabelle, Caching für eine Live-Statusabfrage nicht sinnvoll.
        $lastModified = (string) $wpdb->get_var("SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}");

        $dbName = defined('DB_NAME') ? (string) constant('DB_NAME') : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Manifest-Statistik, direkte Query auf information_schema mit esc_sql-Werten, Caching für eine Live-Statusabfrage nicht sinnvoll.
        $dbSize = (int) $wpdb->get_var(sprintf(
            "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '%s' AND table_name LIKE '%s'",
            esc_sql($dbName),
            esc_sql(str_replace('_', '\\_', $wpdb->prefix) . '%')
        ));

        // Capability-Discovery: dem anfragenden Peer mitteilen, was er bei dieser
        // Site auslösen darf. Die Client-Seite gated darüber Pull/Push proaktiv.
        $peer = $this->authenticatedPeer($request);
        $capabilities = [
            'allow_export' => $peer !== null && $peer->permissions->allowInboundExport,
            'allow_import' => $peer !== null && $peer->permissions->allowInboundImport,
        ];

        return new WP_REST_Response([
            'plugin_version' => defined('RHSYNC_VERSION') ? RHSYNC_VERSION : '0.0.0',
            'wp_version' => get_bloginfo('version'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'db_prefix' => $wpdb->prefix,
            'db_size' => $dbSize,
            'uploads_size' => $this->estimateDirectorySize($uploadBase),
            'post_count' => $postCount,
            'last_modified' => $lastModified,
            'capabilities' => $capabilities,
            'limits' => Preflight::localLimits(),
            'generated_at' => gmdate('c'),
        ]);
    }

    public function handleExport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $peer = $this->authenticatedPeer($request);
        if ($peer === null || ! $peer->permissions->allowInboundExport) {
            return new WP_Error(
                'rhbp_export_forbidden',
                __('Diese Site erlaubt dem Peer keinen Export (Pull).', 'rh-sync'),
                ['status' => 403]
            );
        }

        $includeUploads = (bool) $request->get_param('include_uploads');

        try {
            $zipPath = $this->exporter->createBackup($includeUploads, SyncDefaults::excludedTables());
        } catch (\Throwable $e) {
            return new WP_Error(
                'rhbp_export_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        $token = wp_generate_password(40, false, false);
        set_transient(
            self::DOWNLOAD_TRANSIENT_PREFIX . $token,
            [
                'path' => $zipPath,
                'peer_id' => (string) $request->get_param('_peer_id'),
                'created' => time(),
            ],
            self::DOWNLOAD_TTL
        );

        return new WP_REST_Response([
            'token' => $token,
            'download_url' => add_query_arg(
                ['token' => $token],
                rest_url(self::NAMESPACE . '/sync/download')
            ),
            'expires_at' => gmdate('c', time() + self::DOWNLOAD_TTL),
            'size' => is_file($zipPath) ? (int) filesize($zipPath) : 0,
            'filename' => basename($zipPath),
        ]);
    }

    public function handleImportInit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $peer = $this->authenticatedPeer($request);
        if ($peer === null || ! $peer->permissions->allowInboundImport) {
            return new WP_Error(
                'rhbp_import_forbidden',
                __('Diese Site erlaubt dem Peer keinen Import (Push).', 'rh-sync'),
                ['status' => 403]
            );
        }

        $this->storage->ensureReady();

        $sessionId = wp_generate_password(self::IMPORT_SESSION_LENGTH, false, false);
        $peerId = (string) $request->get_param('_peer_id');

        $sessionDir = trailingslashit($this->storage->jobsPath()) . $sessionId;
        if (!wp_mkdir_p($sessionDir)) {
            return new WP_Error('rhbp_session_mkdir', __('Session-Ordner konnte nicht angelegt werden.', 'rh-sync'), ['status' => 500]);
        }

        set_transient(
            self::IMPORT_SESSION_PREFIX . $sessionId,
            [
                'peer_id' => $peerId,
                'started_at' => time(),
                'dir' => $sessionDir,
            ],
            self::IMPORT_SESSION_TTL
        );

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'expires_at' => gmdate('c', time() + self::IMPORT_SESSION_TTL),
            'chunk_size_max' => 5 * 1024 * 1024,
        ]);
    }

    public function handleImportChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $sessionId = (string) $request->get_param('session_id');
        $index = (int) $request->get_param('index');

        $session = $this->getValidatedSession($sessionId, (string) $request->get_param('_peer_id'));
        if ($session instanceof WP_Error) {
            return $session;
        }

        $body = (string) $request->get_body();
        if ($body === '') {
            return new WP_Error('rhbp_empty_chunk', __('Chunk-Body ist leer.', 'rh-sync'), ['status' => 400]);
        }

        $chunkFile = sprintf('%s/chunk-%06d.bin', $session['dir'], $index);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Schreibt einen einzelnen Chunk eines großen Sync-Uploads, WP_Filesystem lädt komplette Dateien in den RAM und ist auf Shared Hosting untauglich.
        $written = file_put_contents($chunkFile, $body);
        if ($written === false) {
            return new WP_Error('rhbp_chunk_write', __('Chunk konnte nicht geschrieben werden.', 'rh-sync'), ['status' => 500]);
        }

        return new WP_REST_Response([
            'index' => $index,
            'size' => $written,
        ]);
    }

    public function handleImportComplete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $sessionId = (string) $request->get_param('session_id');
        $session = $this->getValidatedSession($sessionId, (string) $request->get_param('_peer_id'));
        if ($session instanceof WP_Error) {
            return $session;
        }

        // Profile aus Request lesen (optional, Default = alle Bereiche an = wie vorher)
        $profileData = $request->get_param('profile');
        $profile = is_array($profileData)
            ? SyncProfile::fromArray($profileData)
            : SyncProfile::defaults();

        $chunks = glob($session['dir'] . '/chunk-*.bin') ?: [];
        if ($chunks === []) {
            $this->cleanupSession($sessionId, $session);
            return new WP_Error('rhbp_no_chunks', __('Keine Chunks in der Session.', 'rh-sync'), ['status' => 400]);
        }
        sort($chunks);

        $assembledZip = $session['dir'] . '/assembled.zip';
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming großer Sync-Dateien, WP_Filesystem lädt komplette Dateien in den RAM und ist auf Shared Hosting untauglich.
        $out = fopen($assembledZip, 'wb');
        if ($out === false) {
            $this->cleanupSession($sessionId, $session);
            return new WP_Error('rhbp_assemble_failed', __('Zusammenfuehren fehlgeschlagen.', 'rh-sync'), ['status' => 500]);
        }

        foreach ($chunks as $chunk) {
            $in = fopen($chunk, 'rb');
            if ($in === false) {
                fclose($out);
                $this->cleanupSession($sessionId, $session);
                return new WP_Error('rhbp_assemble_failed', __('Chunk nicht lesbar.', 'rh-sync'), ['status' => 500]);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $totalBytes = (int) filesize($assembledZip);

        // Kein synchroner Import mehr: auf dieser Ziel-Seite einen eigenen Import-Tick-Job
        // starten (das ist der 10-GB-Fix für Push). Das assemblierte ZIP an einen stabilen Ort
        // verschieben (die Session wird gleich aufgeräumt), dann den Job über die Tick-Engine
        // laufen lassen. Der Push-Client pollt /import/job/{id}/status.
        $peerId = (string) $request->get_param('_peer_id');
        $importJob = JobState::create($peerId, SyncStatus::DIRECTION_IMPORT, $profile);

        $incomingDir = $this->storage->jobWorkdir('import-incoming-' . $importJob->jobId);
        $incomingZip = trailingslashit($incomingDir) . 'incoming.zip';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- Verschieben innerhalb desselben Storage-Volumes, atomar; WP_Filesystem bietet kein atomares rename.
        if (!@rename($assembledZip, $incomingZip)) {
            $importJob->purge();
            $this->cleanupSession($sessionId, $session);
            return new WP_Error('rhbp_assemble_failed', __('Konnte Snapshot nicht bereitstellen.', 'rh-sync'), ['status' => 500]);
        }

        $importJob->cursor = ['ij_zip' => $incomingZip];
        $importJob->save();

        $this->cleanupSession($sessionId, $session);
        $this->scheduler->spawnLoopback($importJob);

        return new WP_REST_Response([
            'remote_job_id' => $importJob->jobId,
            'started' => true,
            'bytes' => $totalBytes,
        ]);
    }

    /**
     * @return array{peer_id: string, started_at: int, dir: string}|WP_Error
     */
    private function getValidatedSession(string $sessionId, string $peerId)
    {
        if (!preg_match('/^[A-Za-z0-9]{' . self::IMPORT_SESSION_LENGTH . '}$/', $sessionId)) {
            return new WP_Error('rhbp_invalid_session_id', __('Ungültige Session-ID.', 'rh-sync'), ['status' => 400]);
        }

        $session = get_transient(self::IMPORT_SESSION_PREFIX . $sessionId);
        if (!is_array($session) || !isset($session['peer_id'], $session['dir'])) {
            return new WP_Error('rhbp_session_not_found', __('Session nicht gefunden oder abgelaufen.', 'rh-sync'), ['status' => 404]);
        }

        if ($session['peer_id'] !== $peerId) {
            return new WP_Error('rhbp_session_peer_mismatch', __('Session gehört zu einem anderen Peer.', 'rh-sync'), ['status' => 403]);
        }

        /** @var array{peer_id: string, started_at: int, dir: string} $session */
        return $session;
    }

    /**
     * @param array{peer_id: string, started_at: int, dir: string} $session
     */
    private function cleanupSession(string $sessionId, array $session): void
    {
        delete_transient(self::IMPORT_SESSION_PREFIX . $sessionId);

        $dir = $session['dir'];
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup eines temporären Session-Verzeichnisses, ein Fehlschlag ist unkritisch, WP_Filesystem ist hier unnoetiger Overhead.
        @rmdir($dir);
    }

    /**
     * @return never|WP_Error|array<string, mixed> Roh-Stream endet mit exit (never); der
     *   base64-JSON-Modus (format=json) gibt ein Array zurück, das WP als JSON rendert.
     */
    public function handleDownload(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('token');

        if ($token === '' || !preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return new WP_Error('rhbp_invalid_token', __('Ungültiges Token.', 'rh-sync'), ['status' => 400]);
        }

        $transientKey = self::DOWNLOAD_TRANSIENT_PREFIX . $token;
        $data = get_transient($transientKey);

        if (!is_array($data) || empty($data['path']) || !is_string($data['path'])) {
            return new WP_Error('rhbp_token_expired', __('Token ungültig oder abgelaufen.', 'rh-sync'), ['status' => 404]);
        }

        $zipPath = (string) $data['path'];

        // Path-Validation: muss im backups/ Ordner liegen
        $resolved = $this->storage->resolveInside($this->storage->backupsPath(), basename($zipPath));
        if ($resolved === null || !is_readable($resolved)) {
            // Token bewusst NICHT löschen: ein einzelner is_readable-Aussetzer (Race gegen
            // den noch schreibenden Export, NFS-Latenz, kurze Last) darf nicht das ganze
            // Token vernichten, sonst laufen alle DOWNLOAD_MAX_RETRIES garantiert in
            // token_expired und die Retry-Logik ist wirkungslos. Das Token verfällt regulär
            // nach seiner TTL. 503 signalisiert dem Client "transient, gleich nochmal".
            return new WP_Error('rhbp_file_missing', __('Backup-Datei gerade nicht lesbar.', 'rh-sync'), ['status' => 503]);
        }

        // Sliding TTL statt Single-Use: der Download läuft chunked über viele Range-Requests
        // (resume-bar bei großen Uploads). Jeder gültige Zugriff schiebt das Ablauf-Fenster
        // um DOWNLOAD_TTL nach vorn, solange Fortschritt läuft. Nach dem letzten Zugriff
        // verfällt das Token regulär (kein dauerhafter Download-Link).
        set_transient($transientKey, $data, self::DOWNLOAD_TTL);

        $fileSize = (int) filesize($resolved);

        // base64-JSON-Modus: manche Webserver/WAFs (mod_security) blocken binäre Download-
        // Responses, weil sie die ZIP-/SQL-Signatur im Body als verdächtig einstufen, die
        // Verbindung wird ohne ein Byte resettet. Dann fordert der Client den Range über
        // format=json an und bekommt ihn base64-kodiert in einer normalen JSON-Antwort, die
        // passiert den Filter. Query: format=json&start=…&length=…
        if ($request->get_param('format') === 'json') {
            $start  = max(0, (int) $request->get_param('start'));
            $length = (int) $request->get_param('length');
            if ($length <= 0) {
                $length = $fileSize - $start;
            }
            if ($start >= $fileSize) {
                return ['chunk' => '', 'start' => $start, 'bytes' => 0, 'total' => $fileSize, 'eof' => true];
            }
            $length = (int) min($length, $fileSize - $start);
            $chunk  = self::readFileRange($resolved, $start, $length);

            return [
                'chunk' => base64_encode($chunk),
                'start' => $start,
                'bytes' => strlen($chunk),
                'total' => $fileSize,
                'eof'   => ($start + strlen($chunk)) >= $fileSize,
            ];
        }

        $range = self::parseRangeHeader($request->get_header('range'), $fileSize);

        // Aktive Output-Buffer leeren: sonst puffert PHP die komplette Datei in den
        // Speicher bevor sie rausgeht und sprengt bei großen Backups das memory_limit.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // set_time_limit ist auf Shared-Hostern per disable_functions oft gesperrt. Dann
        // ist die Funktion undefined und der Aufruf ein FATAL (nicht nur eine Warnung, das
        // @ fängt Fatals nicht). Der Download-Handler stürbe genau hier ab, direkt vor den
        // Headern, der Client sähe nur "Empty reply". Darum vor dem Aufruf prüfen.
        if (function_exists('set_time_limit')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- kann trotz vorhandener Funktion (z.B. Safe-Mode-Rest) eine Warnung werfen, der lange Download soll trotzdem laufen.
            @set_time_limit(0);
        }

        nocache_headers();
        header('Accept-Ranges: bytes');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');

        if ($range === 'unsatisfiable') {
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }

        if (is_array($range)) {
            http_response_code(206);
            header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $fileSize);
            header('Content-Length: ' . (string) $range['length']);
            $this->streamFileRange($resolved, $range['start'], $range['length']);
            exit;
        }

        // Kein/ungültiger Range: volle Datei (Abwärtskompat für alte Clients).
        header('Content-Length: ' . (string) $fileSize);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming großer Backup-Dateien, WP_Filesystem lädt komplette Dateien in den RAM und ist auf Shared Hosting untauglich.
        readfile($resolved);
        exit;
    }

    /**
     * Parst einen HTTP-Range-Header für eine Datei bekannter Größe.
     *
     * Unterstützt genau einen einzelnen Byte-Range: geschlossen (`bytes=start-end`)
     * oder offen (`bytes=start-`). Multi-Range, Suffix-Range (`bytes=-N`) und alles
     * Ungültige fallen bewusst auf `null` zurück, der Aufrufer liefert dann die volle
     * Datei mit Status 200 (kein Multipart-Support nötig, der Sync-Client sendet immer
     * geschlossene Ranges).
     *
     * @return array{start:int,end:int,length:int}|string|null Array bei gültigem Range,
     *   'unsatisfiable' wenn der Range außerhalb der Datei liegt (416), null sonst (volle Datei).
     */
    public static function parseRangeHeader(?string $header, int $fileSize): array|string|null
    {
        if ($header === null) {
            return null;
        }

        $header = trim($header);
        if ($header === '' || stripos($header, 'bytes=') !== 0) {
            return null;
        }

        $spec = trim(substr($header, 6));

        // Multi-Range wird nicht unterstützt.
        if (strpos($spec, ',') !== false) {
            return null;
        }

        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $m)) {
            return null;
        }

        // Suffix-Range (bytes=-N) wird nicht unterstützt: volle Datei.
        if ($m[1] === '') {
            return null;
        }

        if ($fileSize <= 0) {
            return null;
        }

        $start = (int) $m[1];
        if ($start >= $fileSize) {
            return 'unsatisfiable';
        }

        $end = $m[2] === '' ? $fileSize - 1 : (int) $m[2];
        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }
        if ($end < $start) {
            return 'unsatisfiable';
        }

        return [
            'start' => $start,
            'end' => $end,
            'length' => $end - $start + 1,
        ];
    }

    /**
     * Streamt genau $length Bytes ab Offset $start aus einer Datei, gepuffert (1 MB),
     * ohne die ganze Datei in den RAM zu laden.
     */
    /**
     * Liest einen Byte-Range aus einer Datei in den Speicher (für den base64-JSON-Modus).
     * Bewusst nur für Chunk-Größen gedacht (nicht die ganze Datei), base64 verdoppelt den
     * Speicher kurzzeitig.
     */
    private static function readFileRange(string $path, int $start, int $length): string
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }
        fseek($fp, $start);
        $out       = '';
        $remaining = $length;
        while ($remaining > 0 && ! feof($fp)) {
            $read = fread($fp, (int) min(1048576, $remaining));
            if ($read === false || $read === '') {
                break;
            }
            $out       .= $read;
            $remaining -= strlen($read);
        }
        fclose($fp);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return $out;
    }

    private function streamFileRange(string $path, int $start, int $length): void
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Byte-genaues Range-Streaming großer Backup-Dateien; WP_Filesystem kann keinen Offset lesen und lädt komplett in den RAM.
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return;
        }

        fseek($fp, $start);
        $remaining = $length;
        $bufferSize = 1024 * 1024;

        while ($remaining > 0 && !feof($fp)) {
            $read = fread($fp, (int) min($bufferSize, $remaining));
            if ($read === false || $read === '') {
                break;
            }
            echo $read; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binärdaten (ZIP-Bytes), kein HTML.
            $remaining -= strlen($read);
            flush();
        }

        fclose($fp);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    }

    private function estimateDirectorySize(string $path): int
    {
        if ($path === '' || !is_dir($path)) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return $size;
    }
}

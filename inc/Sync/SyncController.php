<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhDbEngine\Exporter;
use RhDbEngine\Importer;
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
        private readonly Importer $importer,
        private readonly Storage $storage,
        private readonly PeerRegistry $peers,
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

        $totalBytes = (int) filesize($assembledZip);
        $startTime = microtime(true);

        // Auto-Safety-Backup vor dem Import (mit gleichen Excluded-Tables wie Sync-Export)
        $safetyBackup = null;
        try {
            $safetyBackup = $this->exporter->createBackup(false, SyncDefaults::excludedTables());
        } catch (\Throwable $e) {
            $this->cleanupSession($sessionId, $session);
            return new WP_Error('rhbp_safety_backup_failed', 'Safety-Backup fehlgeschlagen: ' . $e->getMessage(), ['status' => 500]);
        }

        // Site-spezifische rhbp_* Options (inkl. rhbp_peers!) vor dem Import sichern.
        $guard = new LocalOptionGuard();
        $snapshot = $guard->snapshot();

        global $wpdb;

        try {
            $this->importer->importFromFile($assembledZip, $profile->tableFilter((string) $wpdb->prefix), $profile->uploads);
        } catch (\Throwable $e) {
            // Rollback (Vollimport, kein Profile)
            try {
                $this->importer->importFromFile($safetyBackup);
            } catch (\Throwable $rollbackError) {
                $this->cleanupSession($sessionId, $session);
                return new WP_Error(
                    'rhbp_import_and_rollback_failed',
                    sprintf('Import fehlgeschlagen: %s. Rollback fehlgeschlagen: %s.', $e->getMessage(), $rollbackError->getMessage()),
                    ['status' => 500]
                );
            }
            $this->cleanupSession($sessionId, $session);
            return new WP_Error(
                'rhbp_import_failed',
                'Import fehlgeschlagen, Safety-Backup wurde zurückgespielt: ' . $e->getMessage(),
                ['status' => 500]
            );
        }

        // Erfolg: lokale rhbp_* Options wiederherstellen.
        $guard->restore($snapshot);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->cleanupSession($sessionId, $session);

        return new WP_REST_Response([
            'success' => true,
            'bytes' => $totalBytes,
            'duration_ms' => $durationMs,
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
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup einer temporären Session-Datei, ein Fehlschlag ist unkritisch.
                @unlink($file);
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup eines temporären Session-Verzeichnisses, ein Fehlschlag ist unkritisch.
        @rmdir($dir);
    }

    /**
     * @return never|WP_Error
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
            delete_transient($transientKey);
            return new WP_Error('rhbp_file_missing', __('Backup-Datei nicht lesbar.', 'rh-sync'), ['status' => 404]);
        }

        // Token ist Single-Use
        delete_transient($transientKey);

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');
        header('Content-Length: ' . (string) filesize($resolved));

        readfile($resolved);
        exit;
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

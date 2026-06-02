<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhBackup\Api;

/**
 * Push-Workflow: Schiebt einen lokalen Snapshot zum Ziel-Peer und loest dort den Import aus.
 *
 * Schritte (jeweils mit SyncStatus-Update):
 *   1. Lokaler Export via `Exporter::createBackup()` (Uploads je nach Profile)
 *   2. POST /rhbp/v1/sync/import/init: Session anlegen
 *   3. PUT  /rhbp/v1/sync/import/{sid}/chunk/N: ZIP in 5-MB-Chunks hochladen (Progress)
 *   4. POST /rhbp/v1/sync/import/{sid}/complete: Ziel importiert mit Profile (Safety-Backup dort)
 */
final class PushOperation
{
    public const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly SyncClient $client,
        private readonly Api $backup,
        private readonly SyncLog $log,
    ) {
    }

    public function execute(Peer $peer, ?SyncProfile $profile = null, ?string $jobId = null): PushResult
    {
        $effectiveProfile = $profile ?? $peer->profile;
        $startTime = microtime(true);
        $localZip = null;
        $phaseTimings = [];

        if ($jobId === null) {
            $jobId = SyncStatus::start($peer->id, SyncStatus::DIRECTION_PUSH, $effectiveProfile);
        }

        $currentPhase = SyncStatus::PHASE_EXPORT;

        try {
            // 1. Lokaler Export
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_EXPORT, __('Erstelle lokalen Snapshot...', 'rh-sync'));
            $phaseStart = microtime(true);
            $localZip = $this->backup->createBackup($effectiveProfile->uploads, SyncDefaults::excludedTables());
            $totalSize = (int) filesize($localZip);
            $phaseTimings['export'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::progress($jobId, 0, $totalSize);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_EXPORT, sprintf(
                __('Snapshot bereit (%s)', 'rh-sync'),
                size_format($totalSize)
            ));

            // 2./3. Upload (Init + Chunks)
            $currentPhase = SyncStatus::PHASE_UPLOAD;
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_UPLOAD, __('Initialisiere Upload-Session...', 'rh-sync'));
            $phaseStart = microtime(true);
            $sessionId = $this->initSession($peer);
            $chunks = $this->uploadChunks($peer, $sessionId, $localZip, $jobId, $totalSize);
            $phaseTimings['upload'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::progress($jobId, $totalSize, $totalSize);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_UPLOAD, sprintf(
                __('%s in %d Chunks hochgeladen', 'rh-sync'),
                size_format($totalSize),
                $chunks
            ));

            // 4. Ziel-Import (synchron im Remote-Call)
            $currentPhase = SyncStatus::PHASE_IMPORT;
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_IMPORT, __('Ziel-Site spielt Daten ein...', 'rh-sync'));
            $phaseStart = microtime(true);
            $completion = $this->completeSession($peer, $sessionId, $effectiveProfile);
            $phaseTimings['import'] = (int) ((microtime(true) - $phaseStart) * 1000);
            $remoteImportMs = isset($completion['duration_ms']) ? (int) $completion['duration_ms'] : null;
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_IMPORT, $remoteImportMs !== null
                ? sprintf(__('Remote-Import abgeschlossen (%d ms)', 'rh-sync'), $remoteImportMs)
                : __('Remote-Import abgeschlossen', 'rh-sync'));

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->log->record(
                $peer,
                SyncStatus::DIRECTION_PUSH,
                'success',
                $totalSize,
                $durationMs,
                null,
                $effectiveProfile,
                null,
                null
            );

            SyncStatus::done($jobId, [
                'bytes' => $totalSize,
                'chunks' => $chunks,
                'duration_ms' => $durationMs,
                'remote_import_ms' => $remoteImportMs,
                'phase_timings' => $phaseTimings,
                'profile' => $effectiveProfile->toArray(),
            ]);

            return new PushResult(
                success: true,
                bytes: $totalSize,
                chunks: $chunks,
                durationMs: $durationMs,
                remoteImportMs: $remoteImportMs,
                error: null,
                jobId: $jobId,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->log->record(
                $peer,
                SyncStatus::DIRECTION_PUSH,
                'failed',
                0,
                $durationMs,
                $e->getMessage(),
                $effectiveProfile,
                null,
                null
            );

            SyncStatus::failed($jobId, $e->getMessage(), $currentPhase);

            return new PushResult(
                success: false,
                bytes: 0,
                chunks: 0,
                durationMs: $durationMs,
                remoteImportMs: null,
                error: $e->getMessage(),
                jobId: $jobId,
            );
        } finally {
            if ($localZip !== null && is_file($localZip)) {
                @unlink($localZip);
            }
        }
    }

    private function initSession(Peer $peer): string
    {
        $response = $this->client->request($peer, 'POST', '/rhbp/v1/sync/import/init', []);

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Import-Init fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['session_id']) || !is_string($data['session_id'])) {
            throw new \RuntimeException(sprintf(
                'Init-Response unvollstaendig (kein session_id). HTTP %d, Body-Preview: %s',
                $response->status,
                $this->previewBody($response->body)
            ));
        }

        return $data['session_id'];
    }

    private function uploadChunks(Peer $peer, string $sessionId, string $zipPath, string $jobId, int $totalSize): int
    {
        $handle = fopen($zipPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('ZIP konnte nicht gelesen werden.');
        }

        try {
            $index = 0;
            $bytesSent = 0;
            while (!feof($handle)) {
                $chunk = (string) fread($handle, self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                $route = sprintf('/rhbp/v1/sync/import/%s/chunk/%d', $sessionId, $index);
                $response = $this->client->requestRaw(
                    $peer,
                    'PUT',
                    $route,
                    $chunk,
                    'application/octet-stream',
                    SyncClient::DOWNLOAD_TIMEOUT
                );

                if (!$response->isSuccess()) {
                    throw new \RuntimeException(sprintf(
                        'Chunk %d fehlgeschlagen (HTTP %d): %s',
                        $index,
                        $response->status,
                        $response->error ?? $this->extractErrorMessage($response)
                    ));
                }

                $bytesSent += strlen($chunk);
                SyncStatus::progress($jobId, $bytesSent, $totalSize);

                $index++;
            }

            return $index;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSession(Peer $peer, string $sessionId, SyncProfile $profile): array
    {
        $route = sprintf('/rhbp/v1/sync/import/%s/complete', $sessionId);
        $response = $this->client->request($peer, 'POST', $route, [
            'profile' => $profile->toArray(),
        ]);

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Import-Complete fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
        }

        return $response->json() ?? [];
    }

    private function extractErrorMessage(SyncResponse $response): string
    {
        $data = $response->json();
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        return 'Unbekannter Fehler. Body-Preview: ' . $this->previewBody($response->body);
    }

    private function previewBody(string $body): string
    {
        $stripped = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if (strlen($stripped) > 200) {
            return substr($stripped, 0, 200) . '…';
        }
        return $stripped;
    }
}

final class PushResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $bytes,
        public readonly int $chunks,
        public readonly int $durationMs,
        public readonly ?int $remoteImportMs,
        public readonly ?string $error,
        public readonly ?string $jobId = null,
    ) {
    }
}

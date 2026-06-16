<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhDbEngine\ExportCursor;
use RhDbEngine\Exporter;
use RhDbEngine\Storage;

/**
 * Push-Workflow: Schiebt einen lokalen Snapshot zum Ziel-Peer und loest dort den Import aus.
 *
 * Schritte (jeweils mit SyncStatus-Update):
 *   1. Lokaler Export via `Exporter::createBackup()` (Uploads je nach Profile)
 *   2. POST /rhbp/v1/sync/import/init: Session anlegen
 *   3. PUT  /rhbp/v1/sync/import/{sid}/chunk/N: ZIP in 5-MB-Chunks hochladen (Progress)
 *   4. POST /rhbp/v1/sync/import/{sid}/complete: Ziel importiert mit Profile (Safety-Backup dort)
 */
final class PushOperation implements StageAdvancer
{
    public const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly SyncClient $client,
        private readonly Exporter $exporter,
        private readonly SyncLog $log,
        private readonly Storage $storage,
        private readonly PeerRegistry $peers,
    ) {
    }

    /**
     * Tick-getriebener Push (neuer Pfad über die Tick-Engine).
     *
     * Stages: export (resume-fähig) -> upload (Chunk-Index-Resume) -> import (das Ziel importiert
     * als eigener Tick-Job, dieser Client pollt nur dessen Status). Damit läuft auch ein 10-GB-Push
     * durch: kein einzelner Request muss Export, Upload oder Ziel-Import in einem Stück schaffen.
     */
    public function advance(JobState $job): void
    {
        $peer = $this->peers->get($job->peerId);
        if ($peer === null) {
            $job->finishFailure(__('Peer nicht gefunden.', 'rh-sync'), $job->stage);
            return;
        }

        $profile = SyncProfile::fromArray($job->profile);

        match ($job->stage) {
            SyncStatus::PHASE_EXPORT => $this->stageExport($job, $profile),
            SyncStatus::PHASE_UPLOAD => $this->stageUpload($job, $peer),
            SyncStatus::PHASE_IMPORT => $this->stageRemoteImport($job, $peer, $profile),
            default => $job->finishFailure('Unerwartete Push-Stage: ' . $job->stage, $job->stage),
        };
    }

    private function stageExport(JobState $job, SyncProfile $profile): void
    {
        if (!isset($job->cursor['ex_cursor'])) {
            $job->markStarted();
            $job->beginStep(SyncStatus::PHASE_EXPORT, __('Erstelle lokalen Snapshot...', 'rh-sync'));
            $cursor = ExportCursor::start(
                $this->storage->jobWorkdir('push-export-' . $job->jobId),
                $profile->uploads,
                SyncDefaults::excludedTables()
            );
        } else {
            $cursor = ExportCursor::fromArray($job->cursor['ex_cursor']);
        }

        $cursor = $this->exporter->exportStep($cursor, $job->tickBudget);
        $job->cursor['ex_cursor'] = $cursor->toArray();

        if ($cursor->isDone()) {
            $size = is_file((string) $cursor->zipPath) ? (int) filesize((string) $cursor->zipPath) : 0;
            $job->cursor['zip_path'] = $cursor->zipPath;
            $job->cursor['total_size'] = $size;
            $job->setProgress(0, $size);
            $job->completeStep(SyncStatus::PHASE_EXPORT, sprintf(
                /* translators: %s = Datenmenge */
                __('Snapshot bereit (%s)', 'rh-sync'),
                size_format($size)
            ));
            $job->stage = SyncStatus::PHASE_UPLOAD;
            $job->beginStep(SyncStatus::PHASE_UPLOAD, __('Lade Daten hoch...', 'rh-sync'));
        }

        $job->save();
    }

    private function stageUpload(JobState $job, Peer $peer): void
    {
        if (!isset($job->cursor['session_id'])) {
            $job->cursor['session_id'] = $this->initSession($peer);
            $job->cursor['chunk_index'] = 0;
        }

        $sessionId = (string) $job->cursor['session_id'];
        $zipPath = (string) $job->cursor['zip_path'];
        $totalSize = (int) ($job->cursor['total_size'] ?? 0);
        $startIndex = (int) ($job->cursor['chunk_index'] ?? 0);

        $result = $this->uploadChunksBudgeted($peer, $sessionId, $zipPath, $startIndex, $job->tickBudget);
        $job->cursor['chunk_index'] = $result['index'];
        $job->setProgress(min($result['index'] * self::CHUNK_SIZE, $totalSize), $totalSize);

        if ($result['done']) {
            $job->completeStep(SyncStatus::PHASE_UPLOAD, sprintf(
                /* translators: %1$s = Datenmenge, %2$d = Anzahl Chunks */
                __('%1$s in %2$d Chunks hochgeladen', 'rh-sync'),
                size_format($totalSize),
                $result['index']
            ));
            $job->stage = SyncStatus::PHASE_IMPORT;
            $job->beginStep(SyncStatus::PHASE_IMPORT, __('Ziel-Site spielt Daten ein...', 'rh-sync'));
        }

        $job->save();
    }

    private function stageRemoteImport(JobState $job, Peer $peer, SyncProfile $profile): void
    {
        // Erster Import-Tick: Remote-Import-Job auf dem Ziel starten.
        if (!isset($job->cursor['remote_job_id'])) {
            $completion = $this->completeSession($peer, (string) $job->cursor['session_id'], $profile);
            $remoteJobId = isset($completion['remote_job_id']) ? (string) $completion['remote_job_id'] : '';
            if ($remoteJobId === '') {
                $job->finishFailure(__('Das Ziel hat keinen Import-Job gestartet.', 'rh-sync'), SyncStatus::PHASE_IMPORT);
                return;
            }
            $job->cursor['remote_job_id'] = $remoteJobId;
            $job->save();
            return;
        }

        // Folge-Ticks: den Remote-Import-Job pollen.
        $status = $this->pollRemoteImport($peer, (string) $job->cursor['remote_job_id']);
        $phase = (string) ($status['phase'] ?? '');

        if ($phase === SyncStatus::PHASE_DONE) {
            $job->completeStep(SyncStatus::PHASE_IMPORT, __('Remote-Import abgeschlossen', 'rh-sync'));
            $this->cleanupExportWorkdir($job);
            $job->finishSuccess([
                'bytes' => (int) ($job->cursor['total_size'] ?? 0),
                'remote_job_id' => $job->cursor['remote_job_id'],
                'profile' => $job->profile,
            ]);
            return;
        }

        if ($phase === SyncStatus::PHASE_FAILED) {
            $remoteError = is_array($status['error'] ?? null) ? (string) ($status['error']['message'] ?? '') : '';
            $this->cleanupExportWorkdir($job);
            $job->finishFailure(sprintf(__('Remote-Import fehlgeschlagen: %s', 'rh-sync'), $remoteError), SyncStatus::PHASE_IMPORT);
            return;
        }

        // Läuft noch: Heartbeat halten, nächster Tick pollt erneut.
        $job->save();
    }

    /**
     * Lädt Chunks ab $startIndex hoch, bis das Zeitbudget erreicht ist oder das ZIP zu Ende ist.
     *
     * @return array{index: int, done: bool}
     */
    private function uploadChunksBudgeted(Peer $peer, string $sessionId, string $zipPath, int $startIndex, float $budgetSeconds): array
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming großer Sync-Dateien in Chunks, WP_Filesystem lädt komplett in den RAM.
        $handle = fopen($zipPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('ZIP konnte nicht gelesen werden.');
        }

        $deadline = microtime(true) + max(0.1, $budgetSeconds);

        try {
            $index = $startIndex;
            if (fseek($handle, $startIndex * self::CHUNK_SIZE) !== 0) {
                throw new \RuntimeException('Konnte nicht zum Chunk-Offset springen.');
            }

            while (!feof($handle)) {
                $chunk = (string) fread($handle, self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                $route = sprintf('/rhbp/v1/sync/import/%s/chunk/%d', $sessionId, $index);
                $response = $this->client->requestRaw($peer, 'PUT', $route, $chunk, 'application/octet-stream', SyncClient::DOWNLOAD_TIMEOUT);

                if (!$response->isSuccess()) {
                    throw new \RuntimeException(sprintf(
                        'Chunk %d fehlgeschlagen (HTTP %d): %s',
                        $index,
                        $response->status,
                        $response->error ?? $this->extractErrorMessage($response)
                    ));
                }

                $index++;

                if (microtime(true) >= $deadline) {
                    return ['index' => $index, 'done' => feof($handle)];
                }
            }

            return ['index' => $index, 'done' => true];
        } finally {
            fclose($handle);
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    }

    /**
     * @return array<string, mixed>
     */
    private function pollRemoteImport(Peer $peer, string $remoteJobId): array
    {
        $response = $this->client->request($peer, 'GET', '/rhbp/v1/sync/import/job/' . $remoteJobId . '/status');
        if (!$response->isSuccess()) {
            return [];
        }
        return $response->json() ?? [];
    }

    private function cleanupExportWorkdir(JobState $job): void
    {
        $dir = trailingslashit($this->storage->jobsPath()) . 'push-export-' . $job->jobId;
        if (!is_dir($dir)) {
            return;
        }
        $items = glob(trailingslashit($dir) . '*') ?: [];
        foreach ($items as $item) {
            if (is_file($item)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup temporärer Export-Datei, unkritisch.
                @unlink($item);
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup temporäres Verzeichnis, unkritisch.
        @rmdir($dir);
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
            $localZip = $this->exporter->createBackup($effectiveProfile->uploads, SyncDefaults::excludedTables());
            $totalSize = (int) filesize($localZip);
            $phaseTimings['export'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::progress($jobId, 0, $totalSize);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_EXPORT, sprintf(
                /* translators: %s = Datenmenge */
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
                /* translators: %1$s = Datenmenge, %2$d = Anzahl Chunks */
                __('%1$s in %2$d Chunks hochgeladen', 'rh-sync'),
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
                /* translators: %d = Dauer in Millisekunden */
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
                wp_delete_file($localZip);
            }
        }
    }

    private function initSession(Peer $peer): string
    {
        $response = $this->client->request($peer, 'POST', '/rhbp/v1/sync/import/init', []);

        if (!$response->isSuccess()) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPushResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException(sprintf(
                'Import-Init fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['session_id']) || !is_string($data['session_id'])) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPushResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException(sprintf(
                'Init-Response unvollstaendig (kein session_id). HTTP %d, Body-Preview: %s',
                $response->status,
                $this->previewBody($response->body)
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $data['session_id'];
    }

    private function uploadChunks(Peer $peer, string $sessionId, string $zipPath, string $jobId, int $totalSize): int
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming großer Sync-Dateien in Chunks, WP_Filesystem lädt komplette Dateien in den RAM und ist auf Shared Hosting untauglich.
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
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSession(Peer $peer, string $sessionId, SyncProfile $profile): array
    {
        $route = sprintf('/rhbp/v1/sync/import/%s/complete', $sessionId);
        $response = $this->client->request($peer, 'POST', $route, [
            'profile' => $profile->toArray(),
        ], SyncClient::OPERATION_TIMEOUT);

        if (!$response->isSuccess()) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPushResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException(sprintf(
                'Import-Complete fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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

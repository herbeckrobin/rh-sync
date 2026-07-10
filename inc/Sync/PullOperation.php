<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhDbEngine\Exporter;
use RhDbEngine\Importer;
use RhDbEngine\Storage;

/**
 * Pull-Workflow: Holt einen Remote-Snapshot vom Peer und importiert ihn lokal.
 *
 * Schritte (jeweils mit SyncStatus-Update für Live-Frontend):
 *   1. fetchManifest:      Peer-Kompatibilität prüfen
 *   2. triggerExport:      Export anstoßen, Token erhalten (uploads je nach Profile)
 *   3. downloadBackup:     ZIP streamen (Progress-Callback updated SyncStatus)
 *   4. createSafetyBackup: Auto-Safety-Backup des lokalen Zustands
 *   5. runImport:          Remote-DB lokal einspielen (gefiltert nach Profile) + URL-Rewrite
 *
 * Bei Fehlern nach Step 4: Rollback via Importer auf den Safety-Backup.
 */
final class PullOperation implements StageAdvancer
{
    /** Größe eines Download-Chunks. Klein genug, um sicher unter jedem Proxy-/FPM-Timeout zu bleiben. */
    private const DOWNLOAD_CHUNK_SIZE = 8 * 1024 * 1024;

    /** Aufeinanderfolgende Chunk-Fehlversuche, bevor der Download-Schritt endgültig scheitert. */
    private const DOWNLOAD_MAX_RETRIES = 5;

    private readonly ImportJobAdvancer $importMachine;

    public function __construct(
        private readonly SyncClient $client,
        private readonly Exporter $exporter,
        private readonly Importer $importer,
        private readonly Storage $storage,
        private readonly SyncLog $log,
        private readonly PeerRegistry $peers,
    ) {
        $this->importMachine = new ImportJobAdvancer($importer, $exporter, $storage);
    }

    /**
     * Tick-getriebener Pull (neuer Pfad über die Tick-Engine).
     *
     * Stages: manifest -> export -> download, danach übernimmt die geteilte
     * {@see ImportJobAdvancer}-Maschine (safety -> import -> ggf. rollback), sobald der Snapshot
     * lokal als `cursor['ij_zip']` vorliegt.
     *
     * Der Download läuft resume-bar über viele Ticks: pro Tick werden mehrere Range-Chunks
     * (je {@see DOWNLOAD_CHUNK_SIZE}) geladen, bis das Tick-Budget erschöpft ist. Der Byte-Offset
     * lebt im Cursor (`download_offset`), sodass ein Verbindungsabbruch bei großen Uploads nur den
     * laufenden Chunk kostet und der nächste Tick nahtlos weitermacht.
     */
    public function advance(JobState $job): void
    {
        // Snapshot liegt lokal: ab hier fährt die geteilte Import-Maschine.
        if (isset($job->cursor['ij_zip'])) {
            $this->importMachine->advance($job);
            return;
        }

        $peer = $this->peers->get($job->peerId);
        if ($peer === null) {
            $job->finishFailure(__('Peer nicht gefunden.', 'rh-sync'), $job->stage);
            return;
        }

        $profile = SyncProfile::fromArray($job->profile);

        match ($job->stage) {
            SyncStatus::PHASE_MANIFEST => $this->stageManifest($job, $peer),
            SyncStatus::PHASE_EXPORT => $this->stageExport($job, $peer, $profile),
            SyncStatus::PHASE_DOWNLOAD => $this->stageDownload($job, $peer),
            default => $job->finishFailure('Unerwartete Pull-Stage: ' . $job->stage, $job->stage),
        };
    }

    private function stageManifest(JobState $job, Peer $peer): void
    {
        $job->markStarted();
        $job->beginStep(SyncStatus::PHASE_MANIFEST, __('Verbindung zur Quelle prüfen...', 'rh-sync'));

        $manifest = $this->fetchManifest($peer);

        $job->cursor['source_manifest'] = $manifest;
        $job->completeStep(SyncStatus::PHASE_MANIFEST, sprintf(
            /* translators: %1$s = WordPress-Version, %2$s = Plugin-Version */
            __('Quelle erreichbar: WP %1$s, Plugin %2$s', 'rh-sync'),
            (string) ($manifest['wp_version'] ?? '?'),
            (string) ($manifest['plugin_version'] ?? '?')
        ));
        $job->stage = SyncStatus::PHASE_EXPORT;
        $job->beginStep(SyncStatus::PHASE_EXPORT, __('Snapshot auf Quelle erstellen...', 'rh-sync'));
        $job->save();
    }

    private function stageExport(JobState $job, Peer $peer, SyncProfile $profile): void
    {
        $exportInfo = $this->triggerExport($peer, $profile);
        $size = (int) ($exportInfo['size'] ?? 0);

        $job->cursor['download_url'] = (string) $exportInfo['download_url'];
        $job->cursor['download_size'] = $size;
        $job->setProgress(0, $size);
        $job->completeStep(SyncStatus::PHASE_EXPORT, sprintf(
            /* translators: %s = Datenmenge */
            __('Snapshot bereit (%s)', 'rh-sync'),
            size_format($size)
        ));
        $job->stage = SyncStatus::PHASE_DOWNLOAD;
        $job->beginStep(SyncStatus::PHASE_DOWNLOAD, __('Lade Daten...', 'rh-sync'));
        $job->save();
    }

    private function stageDownload(JobState $job, Peer $peer): void
    {
        $url = (string) ($job->cursor['download_url'] ?? '');
        $size = (int) ($job->cursor['download_size'] ?? 0);
        if ($url === '') {
            $job->finishFailure(__('Kein Download-Link von der Quelle erhalten.', 'rh-sync'), SyncStatus::PHASE_DOWNLOAD);
            return;
        }

        $this->storage->ensureReady();

        // Ziel-Datei EINMAL reservieren und im Cursor festhalten: reserveTempFile() erzeugt bei
        // jedem Aufruf einen neuen Zufallsnamen, alle Folge-Ticks müssen aber dieselbe Datei
        // fortschreiben, sonst wäre Resume unmöglich.
        $target = (string) ($job->cursor['download_target'] ?? '');
        if ($target === '') {
            $target = $this->storage->reserveTempFile('sync-pull') . '.zip';
            $job->cursor['download_target'] = $target;
        }

        // Größe unbekannt: kein Range möglich, klassischer Voll-Download in einem Zug.
        if ($size <= 0) {
            $this->client->downloadTo($url, $target, $peer);
            if (!is_file($target) || filesize($target) === 0) {
                $job->finishFailure(__('Heruntergeladene Datei ist leer oder fehlt.', 'rh-sync'), SyncStatus::PHASE_DOWNLOAD);
                return;
            }
            $this->completeDownload($job, $target, (int) filesize($target));
            return;
        }

        $chunkSize = max(1, (int) apply_filters('rh-blueprint/sync/download_chunk_size', self::DOWNLOAD_CHUNK_SIZE));
        $offset = (int) ($job->cursor['download_offset'] ?? 0);
        $retries = (int) ($job->cursor['download_retries'] ?? 0);
        $deadline = microtime(true) + $job->tickBudget;

        while ($offset < $size && microtime(true) < $deadline) {
            $len = (int) min($chunkSize, $size - $offset);
            $part = $target . '.part';

            // Heartbeat VOR dem Chunk: der Watchdog wertet bis zu STALE_AFTER (90s) Ruhe pro
            // Chunk als normal und greift nicht in einen aktiven Download ein.
            $job->touch();

            try {
                $result = $this->client->downloadRange($url, $peer, $offset, $len, $part);
            } catch (\Throwable $e) {
                // Transienter Netzwerkfehler (cURL 18 etc.): partiellen Chunk verwerfen, ab dem
                // letzten persistierten Offset weiter. Erst nach MAX_RETRIES endgültig aufgeben.
                $this->deletePart($part);
                if ($this->bumpRetry($job, ++$retries, $e->getMessage())) {
                    return;
                }
                $job->save();
                return; // nächster Loopback-Tick resumt ab $offset
            }

            // Alte Quelle ohne Range-Support (200 statt 206): $part hält die volle Datei.
            if ($result['status'] === 200) {
                if ($offset > 0) {
                    $this->deletePart($part);
                    $job->finishFailure(
                        __('Die Quelle lieferte eine widersprüchliche Range-Antwort. Bitte Quelle und Ziel auf dieselbe Version aktualisieren.', 'rh-sync'),
                        SyncStatus::PHASE_DOWNLOAD
                    );
                    return;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- atomares Verschieben einer lokalen Temp-Datei, WP_Filesystem wäre unnötiger Overhead.
                rename($part, $target);
                $job->cursor['download_no_range'] = true;
                $offset = is_file($target) ? (int) filesize($target) : 0;
                $job->cursor['download_offset'] = $offset;
                break;
            }

            // Erwartet: 206 mit genau $len Bytes. Alles andere ist ein Fehlversuch.
            if ($result['status'] !== 206 || $result['bytes'] !== $len) {
                $this->deletePart($part);
                if ($this->bumpRetry($job, ++$retries, __('inkonsistentes Teilstück', 'rh-sync'))) {
                    return;
                }
                $job->save();
                return;
            }

            if (!self::writeChunkAt($target, $offset, $part)) {
                $this->deletePart($part);
                $job->finishFailure(__('Download-Datei konnte nicht geschrieben werden.', 'rh-sync'), SyncStatus::PHASE_DOWNLOAD);
                return;
            }
            $this->deletePart($part);

            $offset += $result['bytes'];
            $retries = 0;
            $job->cursor['download_offset'] = $offset;
            $job->cursor['download_retries'] = 0;
            $job->setProgress($offset, $size);
            $job->touch();
        }

        if ($offset >= $size) {
            $this->completeDownload($job, $target, $size);
            return;
        }

        // Budget erschöpft, aber noch nicht fertig: nächster Loopback-Tick macht weiter.
        $job->save();
    }

    /**
     * Erhöht den Retry-Zähler im Cursor. Gibt true zurück, wenn das Limit erreicht ist und
     * der Job bereits als gescheitert abgeschlossen wurde (Aufrufer muss dann return).
     */
    private function bumpRetry(JobState $job, int $retries, string $reason): bool
    {
        $job->cursor['download_retries'] = $retries;
        if ($retries >= self::DOWNLOAD_MAX_RETRIES) {
            $job->finishFailure(
                sprintf(
                    /* translators: %s = Fehlergrund */
                    __('Download nach mehreren Versuchen fehlgeschlagen: %s', 'rh-sync'),
                    $reason
                ),
                SyncStatus::PHASE_DOWNLOAD
            );
            return true;
        }
        return false;
    }

    private function deletePart(string $part): void
    {
        if (is_file($part)) {
            wp_delete_file($part);
        }
    }

    /**
     * Schreibt den Inhalt von $part byte-genau ab $offset in $target. Idempotent über
     * fseek+ftruncate (nicht Append), damit ein etwaiger paralleler Revive-Tick dieselben
     * Bytes an dieselbe Position schreibt statt hinten anzuhängen.
     */
    private static function writeChunkAt(string $target, int $offset, string $part): bool
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- byte-genaues Resume-Schreiben großer Backup-Dateien; WP_Filesystem kann keinen Offset schreiben und lädt komplett in den RAM.
        $in = fopen($part, 'rb');
        if ($in === false) {
            return false;
        }
        $out = fopen($target, $offset === 0 ? 'wb' : 'cb');
        if ($out === false) {
            fclose($in);
            return false;
        }
        if (fseek($out, $offset) !== 0) {
            fclose($in);
            fclose($out);
            return false;
        }
        $copied = stream_copy_to_stream($in, $out);
        $partSize = (int) filesize($part);
        ftruncate($out, $offset + $partSize);
        fclose($in);
        fclose($out);
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return $copied !== false && (int) $copied === $partSize;
    }

    private function completeDownload(JobState $job, string $target, int $size): void
    {
        $job->setProgress($size, $size);
        $job->completeStep(SyncStatus::PHASE_DOWNLOAD, sprintf(
            /* translators: %s = Datenmenge */
            __('%s heruntergeladen', 'rh-sync'),
            size_format($size)
        ));

        // Übergabe an die Import-Maschine: ZIP-Pfad setzen, Sub-Phase initialisieren.
        $job->cursor['ij_zip'] = $target;
        $job->cursor['ij_phase'] = '';
        $job->save();
    }

    public function execute(Peer $peer, ?SyncProfile $profile = null, ?string $jobId = null): PullResult
    {
        $effectiveProfile = $profile ?? $peer->profile;
        $startTime = microtime(true);
        $phaseTimings = [];
        $safetyBackup = null;
        $manifest = null;

        // Wenn kein Job-Id übergeben wurde: hier starten (Fallback für admin-post)
        $ownJob = false;
        if ($jobId === null) {
            $jobId = SyncStatus::start($peer->id, SyncStatus::DIRECTION_PULL, $effectiveProfile);
            $ownJob = true;
        }

        $currentPhase = SyncStatus::PHASE_MANIFEST;

        try {
            // 1. Manifest
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_MANIFEST, __('Verbindung zur Quelle prüfen...', 'rh-sync'));
            $phaseStart = microtime(true);
            $manifest = $this->fetchManifest($peer);
            $phaseTimings['manifest'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_MANIFEST, sprintf(
                /* translators: %1$s = WordPress-Version, %2$s = Plugin-Version */
                __('Quelle erreichbar: WP %1$s, Plugin %2$s', 'rh-sync'),
                (string) ($manifest['wp_version'] ?? '?'),
                (string) ($manifest['plugin_version'] ?? '?')
            ));

            // 2. Export anstossen
            $currentPhase = SyncStatus::PHASE_EXPORT;
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_EXPORT, __('Snapshot auf Quelle erstellen...', 'rh-sync'));
            $phaseStart = microtime(true);
            $exportInfo = $this->triggerExport($peer, $effectiveProfile);
            $phaseTimings['export'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_EXPORT, sprintf(
                /* translators: %s = Datenmenge */
                __('Snapshot bereit (%s)', 'rh-sync'),
                size_format((int) ($exportInfo['size'] ?? 0))
            ));

            // 3. Download
            $currentPhase = SyncStatus::PHASE_DOWNLOAD;
            $totalSize = (int) ($exportInfo['size'] ?? 0);
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_DOWNLOAD, __('Lade Daten...', 'rh-sync'));
            SyncStatus::progress($jobId, 0, $totalSize);
            $phaseStart = microtime(true);
            $localZip = $this->downloadBackup($peer, (string) $exportInfo['download_url'], $jobId, $totalSize);
            $phaseTimings['download'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::progress($jobId, $totalSize, $totalSize);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_DOWNLOAD, sprintf(
                /* translators: %s = Datenmenge */
                __('%s heruntergeladen', 'rh-sync'),
                size_format($totalSize)
            ));

            // 4. Safety-Backup
            $currentPhase = SyncStatus::PHASE_SAFETY;
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_SAFETY, __('Erstelle lokales Sicherheits-Backup...', 'rh-sync'));
            $phaseStart = microtime(true);
            $safetyBackup = $this->createSafetyBackup();
            $phaseTimings['safety'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_SAFETY, basename($safetyBackup));

            // 5. Import
            $currentPhase = SyncStatus::PHASE_IMPORT;
            SyncStatus::beginStep($jobId, SyncStatus::PHASE_IMPORT, __('Spiele Daten lokal ein...', 'rh-sync'));
            $phaseStart = microtime(true);
            $this->runImport($localZip, $safetyBackup, $effectiveProfile);
            $phaseTimings['import'] = (int) ((microtime(true) - $phaseStart) * 1000);
            SyncStatus::completeStep($jobId, SyncStatus::PHASE_IMPORT, __('Import abgeschlossen', 'rh-sync'));

            wp_delete_file($localZip);

            $bytes = $totalSize;
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->log->record(
                $peer,
                SyncStatus::DIRECTION_PULL,
                'success',
                $bytes,
                $durationMs,
                null,
                $effectiveProfile,
                $manifest,
                $safetyBackup
            );

            SyncStatus::done($jobId, [
                'bytes' => $bytes,
                'duration_ms' => $durationMs,
                'phase_timings' => $phaseTimings,
                'safety_backup_path' => $safetyBackup,
                'source_manifest' => $manifest,
                'profile' => $effectiveProfile->toArray(),
            ]);

            return new PullResult(
                success: true,
                bytes: $bytes,
                durationMs: $durationMs,
                manifest: $manifest,
                safetyBackup: $safetyBackup,
                error: null,
                jobId: $jobId,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->log->record(
                $peer,
                SyncStatus::DIRECTION_PULL,
                'failed',
                0,
                $durationMs,
                $e->getMessage(),
                $effectiveProfile,
                $manifest,
                $safetyBackup
            );

            SyncStatus::failed($jobId, $e->getMessage(), $currentPhase, $safetyBackup);

            return new PullResult(
                success: false,
                bytes: 0,
                durationMs: $durationMs,
                manifest: $manifest,
                safetyBackup: $safetyBackup,
                error: $e->getMessage(),
                jobId: $jobId,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchManifest(Peer $peer): array
    {
        $response = $this->client->request($peer, 'GET', '/rhbp/v1/sync/manifest');

        if (!$response->isSuccess()) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPullResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException(sprintf(
                'Manifest-Request fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $data = $response->json();
        if ($data === null) {
            throw new \RuntimeException('Manifest-Response konnte nicht als JSON geparst werden.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function triggerExport(Peer $peer, SyncProfile $profile): array
    {
        $response = $this->client->request($peer, 'POST', '/rhbp/v1/sync/export', [
            'include_uploads' => $profile->uploads,
        ], SyncClient::OPERATION_TIMEOUT);

        if (!$response->isSuccess()) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPullResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException(sprintf(
                'Export-Request fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $data = $response->json();
        if ($data === null || empty($data['download_url'])) {
            throw new \RuntimeException('Export-Response unvollstaendig, kein download_url enthalten.');
        }

        return $data;
    }

    private function downloadBackup(Peer $peer, string $url, string $jobId, int $totalSize): string
    {
        $this->storage->ensureReady();
        $target = $this->storage->reserveTempFile('sync-pull') . '.zip';

        $lastUpdate = 0.0;
        $this->client->downloadTo(
            $url,
            $target,
            $peer,
            function (int $bytesNow, int $bytesTotal) use ($jobId, $totalSize, &$lastUpdate): void {
                // Throttle: max 4 Updates pro Sekunde (vermeidet Transient-Spam)
                $now = microtime(true);
                if ($now - $lastUpdate < 0.25 && $bytesNow !== $bytesTotal) {
                    return;
                }
                $lastUpdate = $now;
                SyncStatus::progress($jobId, $bytesNow, $bytesTotal > 0 ? $bytesTotal : $totalSize);
            }
        );

        if (!is_file($target) || filesize($target) === 0) {
            throw new \RuntimeException('Heruntergeladene ZIP-Datei ist leer oder fehlt.');
        }

        return $target;
    }

    private function createSafetyBackup(): string
    {
        return $this->exporter->createBackup(false, SyncDefaults::excludedTables());
    }

    private function runImport(string $zipPath, string $safetyBackup, SyncProfile $profile): void
    {
        global $wpdb;

        $guard = new LocalOptionGuard();
        $snapshot = $guard->snapshot();

        try {
            $this->importer->importFromFile($zipPath, $profile->tableFilter((string) $wpdb->prefix), $profile->uploads);
        } catch (\Throwable $e) {
            // Rollback-Versuch: Safety-Backup zurückspielen (Vollimport, kein Profile).
            try {
                $this->importer->importFromFile($safetyBackup);
            } catch (\Throwable $rollbackError) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPullResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
                throw new \RuntimeException(sprintf(
                    'Import fehlgeschlagen (%s) UND Rollback fehlgeschlagen (%s). Manuelle Wiederherstellung nötig: %s',
                    $e->getMessage(),
                    $rollbackError->getMessage(),
                    $safetyBackup
                ));
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- interne Exception-Meldung, wird gefangen und am Anzeige-Layer (renderPullResultNotice) via esc_html escapt, hier escapen würde den Log-Eintrag doppelt kodieren.
            throw new \RuntimeException(sprintf(
                'Import fehlgeschlagen: %s. Safety-Backup wurde zurückgespielt.',
                $e->getMessage()
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $guard->restore($snapshot);
    }

    private function extractErrorMessage(SyncResponse $response): string
    {
        $data = $response->json();
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        $stripped = trim(preg_replace('/\s+/', ' ', $response->body) ?? $response->body);
        if (strlen($stripped) > 200) {
            $stripped = substr($stripped, 0, 200) . '…';
        }
        return 'Unbekannter Fehler. Body-Preview: ' . $stripped;
    }
}

final class PullResult
{
    /**
     * @param array<string, mixed>|null $manifest
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $bytes,
        public readonly int $durationMs,
        public readonly ?array $manifest,
        public readonly ?string $safetyBackup,
        public readonly ?string $error,
        public readonly ?string $jobId = null,
    ) {
    }
}

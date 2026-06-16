<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhDbEngine\ExportCursor;
use RhDbEngine\Exporter;
use RhDbEngine\ImportCursor;
use RhDbEngine\Importer;
use RhDbEngine\Storage;

/**
 * Geteilte, tick-getriebene Import-Maschine.
 *
 * Spielt ein vorliegendes Backup-ZIP zustandsbehaftet ein und ist damit der eigentliche
 * 10-GB-Fix: kein synchroner Monolith-Import mehr, sondern viele kurze Ticks. Wird von beiden
 * Seiten genutzt: vom Pull (lokaler Import des heruntergeladenen Snapshots) und vom Push-Ziel
 * (Import des hochgeladenen Snapshots als eigener Hintergrund-Job).
 *
 * Erwartet im Job-Cursor: `ij_zip` = absoluter Pfad zum zu importierenden ZIP.
 *
 * Phasen (je ein Sub-Step pro Tick, jeweils selbst resume-fähig):
 *   safety  -> Sicherheits-Backup des aktuellen Zustands (exportStep)
 *   import  -> Snapshot einspielen (importStep, gefiltert nach Profil)
 *   rollback-> bei Importfehler das Safety-Backup zurückspielen, dann sauber als failed enden
 */
final class ImportJobAdvancer implements StageAdvancer
{
    public function __construct(
        private readonly Importer $importer,
        private readonly Exporter $exporter,
        private readonly Storage $storage,
    ) {
    }

    public function advance(JobState $job): void
    {
        $phase = (string) ($job->cursor['ij_phase'] ?? '');

        match ($phase) {
            '' => $this->init($job),
            'safety' => $this->stepSafety($job),
            'import' => $this->stepImport($job),
            'rollback' => $this->stepRollback($job),
            default => $job->finishFailure('Unbekannte Import-Phase: ' . $phase, SyncStatus::PHASE_IMPORT),
        };
    }

    private function init(JobState $job): void
    {
        $job->markStarted();

        $zip = (string) ($job->cursor['ij_zip'] ?? '');
        if ($zip === '' || !is_readable($zip)) {
            // Vor dem Safety-Backup: ein Wurf ist sicher (kein Rollback nötig).
            throw new \RuntimeException('Zu importierendes Backup-ZIP fehlt oder ist nicht lesbar.');
        }

        $job->cursor['ij_phase'] = 'safety';
        $job->beginStep(SyncStatus::PHASE_SAFETY, __('Erstelle Sicherheits-Backup...', 'rh-sync'));
        $job->save();
    }

    private function stepSafety(JobState $job): void
    {
        $cursor = isset($job->cursor['ij_safety_cursor']) && is_array($job->cursor['ij_safety_cursor'])
            ? ExportCursor::fromArray($job->cursor['ij_safety_cursor'])
            : ExportCursor::start($this->storage->jobWorkdir('ij-safety-' . $job->jobId), false, SyncDefaults::excludedTables());

        $cursor = $this->exporter->exportStep($cursor, $job->tickBudget);
        $job->cursor['ij_safety_cursor'] = $cursor->toArray();

        if ($cursor->isDone()) {
            $job->cursor['ij_safety_path'] = $cursor->zipPath;
            $job->completeStep(SyncStatus::PHASE_SAFETY, basename((string) $cursor->zipPath));

            // Site-lokale Options (rhbp_peers, active_plugins, Login-URL, ...) jetzt sichern,
            // solange die DB noch im Vor-Import-Zustand ist. Nach dem Import wieder einspielen,
            // damit der Snapshot der Quelle die Identität der Ziel-Site nicht überschreibt.
            $guard = new LocalOptionGuard();
            $job->cursor['ij_option_guard'] = $guard->snapshot();

            $job->cursor['ij_phase'] = 'import';
            $job->beginStep(SyncStatus::PHASE_IMPORT, __('Spiele Daten ein...', 'rh-sync'));
        }

        $job->save();
    }

    private function stepImport(JobState $job): void
    {
        global $wpdb;

        $profile = SyncProfile::fromArray($job->profile);

        $cursor = isset($job->cursor['ij_import_cursor']) && is_array($job->cursor['ij_import_cursor'])
            ? ImportCursor::fromArray($job->cursor['ij_import_cursor'])
            : ImportCursor::start((string) $job->cursor['ij_zip'], $this->storage->jobWorkdir('ij-import-' . $job->jobId));

        try {
            $cursor = $this->importer->importStep(
                $cursor,
                $job->tickBudget,
                $profile->tableFilter((string) $wpdb->prefix),
                $profile->uploads
            );
        } catch (\Throwable $e) {
            // Importfehler NICHT werfen: in den Rollback überführen (Safety-Backup ist da).
            $job->cursor['ij_error'] = $e->getMessage();
            $job->cursor['ij_phase'] = 'rollback';
            $job->save();
            return;
        }

        $job->cursor['ij_import_cursor'] = $cursor->toArray();
        $job->setProgress($cursor->sqlByteOffset, 0);

        if ($cursor->isDone()) {
            $job->importCommitted = true;

            // Gesicherte site-lokale Options wiederherstellen (der Import hat sie mit dem
            // Quell-Stand überschrieben).
            if (isset($job->cursor['ij_option_guard']) && is_array($job->cursor['ij_option_guard'])) {
                (new LocalOptionGuard())->restore($job->cursor['ij_option_guard']);
            }

            // Bei users-Pull: die Session des auslösenden Admins wiederherstellen (kein Logout).
            // Nur gesetzt, wenn der Trigger im eingeloggten Kontext einen Snapshot gemacht hat.
            if (isset($job->cursor['session_guard']) && is_array($job->cursor['session_guard'])) {
                (new SessionGuard())->restore($job->cursor['session_guard']);
            }

            $job->completeStep(SyncStatus::PHASE_IMPORT, __('Import abgeschlossen', 'rh-sync'));
            $this->cleanupWorkdirs($job);
            $job->finishSuccess([
                'safety_backup_path' => $job->cursor['ij_safety_path'] ?? null,
                'profile' => $job->profile,
            ]);
            return;
        }

        $job->save();
    }

    private function stepRollback(JobState $job): void
    {
        $error = (string) ($job->cursor['ij_error'] ?? 'Unbekannter Importfehler');
        $safety = (string) ($job->cursor['ij_safety_path'] ?? '');

        if ($safety === '' || !is_readable($safety)) {
            $this->cleanupWorkdirs($job);
            $job->finishFailure(
                sprintf('Import fehlgeschlagen (%s) und kein Safety-Backup zum Zurückspielen vorhanden.', $error),
                SyncStatus::PHASE_IMPORT
            );
            return;
        }

        $cursor = isset($job->cursor['ij_rollback_cursor']) && is_array($job->cursor['ij_rollback_cursor'])
            ? ImportCursor::fromArray($job->cursor['ij_rollback_cursor'])
            : ImportCursor::start($safety, $this->storage->jobWorkdir('ij-rollback-' . $job->jobId));

        try {
            // Vollimport ohne Filter: kompletter Vor-Zustand zurück.
            $cursor = $this->importer->importStep($cursor, $job->tickBudget);
        } catch (\Throwable $e) {
            $this->cleanupWorkdirs($job);
            $job->finishFailure(
                sprintf('Import fehlgeschlagen (%s) UND Rollback fehlgeschlagen (%s). Manuelle Wiederherstellung nötig: %s', $error, $e->getMessage(), $safety),
                SyncStatus::PHASE_IMPORT,
                $safety
            );
            return;
        }

        $job->cursor['ij_rollback_cursor'] = $cursor->toArray();

        if ($cursor->isDone()) {
            $this->cleanupWorkdirs($job);
            $job->finishFailure(
                sprintf('Import fehlgeschlagen: %s. Das Sicherheits-Backup wurde zurückgespielt.', $error),
                SyncStatus::PHASE_IMPORT,
                $safety
            );
            return;
        }

        $job->save();
    }

    private function cleanupWorkdirs(JobState $job): void
    {
        foreach (['ij-safety-', 'ij-import-', 'ij-rollback-'] as $prefix) {
            $dir = trailingslashit($this->storage->jobsPath()) . $prefix . $job->jobId;
            if (is_dir($dir)) {
                $this->deleteDir($dir);
            }
        }

        // Das übergebene Transfer-ZIP (Pull-Download bzw. Push-incoming) ist ein Wegwerf-Artefakt.
        $zip = (string) ($job->cursor['ij_zip'] ?? '');
        if ($zip !== '' && is_file($zip)) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup eines temporären Transfer-ZIP, unkritisch.
            @unlink($zip);
            $parent = dirname($zip);
            // Falls das ZIP in einem eigenen incoming-Verzeichnis lag, das leere Verzeichnis entfernen.
            if (str_contains(basename($parent), 'import-incoming-')) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup eines temporären Verzeichnisses, unkritisch.
                @rmdir($parent);
            }
        }
    }

    private function deleteDir(string $dir): void
    {
        $items = glob(trailingslashit($dir) . '*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteDir($item);
            } elseif (is_file($item)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup einer temporären Datei, unkritisch.
                @unlink($item);
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup eines temporären Verzeichnisses, unkritisch.
        @rmdir($dir);
    }
}

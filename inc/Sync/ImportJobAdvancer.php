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
 *   safety   -> Sicherheits-Backup des aktuellen Zustands (exportStep)
 *   import   -> Snapshot einspielen (importStep, gefiltert nach Profil)
 *   aftercare-> Termine wiederherstellen, die an Inhalten hängen (ScheduleRebuilder)
 *   rollback -> bei Importfehler das Safety-Backup zurückspielen, dann sauber als failed enden
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
            'aftercare' => $this->stepAftercare($job),
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
            : ExportCursor::start(
                $this->storage->jobWorkdir('ij-safety-' . $job->jobId),
                false,
                SyncDefaults::excludedTables(),
                // Sicherungskopien gehören zu den Backups, nicht flach dazwischen: so
                // sind sie in der Liste als solche erkennbar und haben ihre eigene
                // Aufbewahrungsgrenze.
                $this->storage->backupsSubPath(SyncDefaults::SAFETY_SUBDIR)
            );

        $cursor = $this->exporter->exportStep($cursor, $job->tickBudget);
        $job->cursor['ij_safety_cursor'] = $cursor->toArray();

        if ($cursor->isDone()) {
            $job->cursor['ij_safety_path'] = $cursor->zipPath;
            $job->completeStep(SyncStatus::PHASE_SAFETY, basename((string) $cursor->zipPath));

            // Site-eigene Options (Adresse, aktive Plugins, Rollen, Peer-Liste) jetzt sichern,
            // solange die Datenbank noch der Zielseite gehört.
            //
            // Der Snapshot geht als DATEI weg, nicht in den Job-Cursor. Der Cursor ist eine
            // Option und liegt damit in genau der Tabelle, die der Import gleich ersetzt: die
            // Rettungsleine lag bisher in dem Boot, das sinkt. Am 2026-08-02 war sie deshalb
            // nach dem Absturz nicht mehr auffindbar.
            $snapshot = (new LocalOptionGuard())->snapshot();
            $path = (new GuardVault())->store($job->jobId, $snapshot);
            $job->cursor['ij_guard_file'] = $path;

            JobTrace::write($job->jobId, 'guard_stored', [
                'file' => basename($path),
                'options' => count($snapshot),
            ]);

            $job->cursor['ij_phase'] = 'import';
            $job->beginStep(SyncStatus::PHASE_IMPORT, __('Spiele Daten ein...', 'rh-sync'));
        }

        $job->save();
    }

    /**
     * Der Snapshot der site-eigenen Options, egal woher er kommt.
     *
     * Erst die Datei, dann der alte Platz im Cursor. Der Cursor-Zweig deckt Jobs ab, die
     * noch unter der vorigen Version gestartet sind und mitten im Lauf aktualisiert wurden.
     *
     * @return array<int, array{option_name: string, option_value: string, autoload: string}>
     */
    private function guardSnapshot(JobState $job): array
    {
        $fromFile = (new GuardVault())->load($job->jobId);
        if (is_array($fromFile) && $fromFile !== []) {
            return $fromFile;
        }

        /** @var array<int, array{option_name: string, option_value: string, autoload: string}> $legacy */
        $legacy = is_array($job->cursor['ij_option_guard'] ?? null) ? $job->cursor['ij_option_guard'] : [];

        return $legacy;
    }

    /**
     * Hängt sich in die beiden Momente ein, in denen die db-engine über die Live-Daten entscheidet.
     *
     * Schaltet sie atomar um, wandern die site-eigenen Options VOR dem Umschalten in die
     * Schattentabelle. Damit stimmt die Zielseite in dem Moment, in dem sie umschaltet, und
     * es gibt kein Fenster mehr, in dem sie mit der Adresse oder den Rollen der Quelle
     * dasteht. Kann sie es nicht, schreibt der Import direkt in die Live-Tabellen, und
     * dann wird die Notluke scharf.
     *
     * @param array<int, array{option_name: string, option_value: string, autoload: string}> $snapshot
     * @param bool $applied Wird gesetzt, wenn die Options bereits in der Schattentabelle stehen.
     */
    private function hookIntoSwap(JobState $job, array $snapshot, bool &$applied): void
    {
        $jobId = $job->jobId;

        add_action(
            'rh-db-engine/before_table_swap',
            static function (string $stagePrefix) use ($snapshot, &$applied, $jobId): void {
                if ($snapshot === [] || $applied) {
                    return;
                }

                try {
                    (new LocalOptionGuard())->applyTo($stagePrefix . 'options', $snapshot);
                } catch (\Throwable $e) {
                    // Nicht umschalten. Die Zielseite behält ihre Daten, und der Import
                    // endet gleich als Fehlschlag, ohne die Live-Tabellen berührt zu haben.
                    JobTrace::write($jobId, 'guard_failed_before_swap', [
                        'options' => count($snapshot),
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $applied = true;
                JobTrace::write($jobId, 'guard_applied_before_swap', ['options' => count($snapshot)]);
            },
            10,
            1
        );

        $guardFile = (string) ($job->cursor['ij_guard_file'] ?? '');

        add_action(
            'rh-db-engine/swap_unavailable',
            static function (string $reason) use ($jobId, $guardFile): void {
                JobTrace::write($jobId, 'swap_unavailable', ['reason' => $reason]);

                if ($guardFile === '') {
                    return;
                }

                $hatch = (new RecoveryHatch())->arm($jobId, $guardFile);
                if ($hatch !== null) {
                    // Nicht in den Job-Cursor: der liegt in der Tabelle, die gleich ersetzt
                    // wird. Die Adresse steht im Verlaufsprotokoll, das den Absturz überlebt.
                    JobTrace::write($jobId, 'recovery_url', ['url' => $hatch['url']]);
                }
            },
            10,
            1
        );
    }

    /**
     * Räumt ab, sobald der Lauf vorbei ist: Rettungsleine, Notluke und die Tabellen-Reste
     * eines abgebrochenen Umschaltens.
     */
    private function standDown(JobState $job): void
    {
        (new GuardVault())->clear($job->jobId);
        (new RecoveryHatch())->disarm();

        $left = (new \RhDbEngine\TableSwap())->dropLeftovers();
        if ($left > 0) {
            JobTrace::write($job->jobId, 'leftovers_dropped', ['tables' => $left]);
        }
    }

    private function stepImport(JobState $job): void
    {
        global $wpdb;

        $profile = SyncProfile::fromArray($job->profile);

        $cursor = isset($job->cursor['ij_import_cursor']) && is_array($job->cursor['ij_import_cursor'])
            ? ImportCursor::fromArray($job->cursor['ij_import_cursor'])
            : ImportCursor::start((string) $job->cursor['ij_zip'], $this->storage->jobWorkdir('ij-import-' . $job->jobId));

        // Der Schutz der site-eigenen Options ist nur nötig, wenn der Import die Options-Tabelle
        // überhaupt anfasst. Bei einem Profil ohne "Einstellungen" gibt es weder eine
        // Schattentabelle, in die er schreiben könnte, noch etwas zu reparieren: die Live-Tabelle
        // bleibt unberührt. Ohne diese Prüfung schreibt der Guard ins Leere, das Log füllt sich
        // mit "Table rhstg_options doesn't exist", und am Ende ginge ein überflüssiges
        // DELETE + INSERT über die echte Options-Tabelle.
        $snapshot = $profile->options ? $this->guardSnapshot($job) : [];
        $guardApplied = false;
        $this->hookIntoSwap($job, $snapshot, $guardApplied);

        JobTrace::context([
            'stage' => SyncStatus::PHASE_IMPORT,
            'import_phase' => $cursor->phase,
            'swap' => $cursor->swapMode,
            'sql_offset' => $cursor->sqlByteOffset,
        ]);
        JobTrace::write($job->jobId, 'import_step_start', [
            'import_phase' => $cursor->phase,
            'swap' => $cursor->swapMode,
            'sql_offset' => $cursor->sqlByteOffset,
        ]);

        try {
            $cursor = $this->importer->importStep(
                $cursor,
                $job->tickBudget,
                $profile->tableFilter((string) $wpdb->prefix),
                $profile->uploads
            );
        } catch (\Throwable $e) {
            JobTrace::write($job->jobId, 'import_failed', [
                'import_phase' => $cursor->phase,
                'swap_done' => $cursor->swapDone,
                'message' => $e->getMessage(),
            ]);

            $job->cursor['ij_error'] = $e->getMessage();

            // Wurde nie umgeschaltet, hat der Import die Live-Daten nie berührt: dann gibt
            // es nichts zurückzuspielen, und ein Rollback würde nur unnötig eine intakte
            // Datenbank überschreiben. Vor allem soll die Meldung nicht nach Schaden
            // klingen, wo keiner ist.
            if (!$cursor->liveDataTouched()) {
                $this->cleanupWorkdirs($job);
                $this->standDown($job);
                JobTrace::write($job->jobId, 'import_failed_without_damage', []);
                $job->finishFailure(
                    sprintf(
                        /* translators: %s = Fehlermeldung des Imports */
                        __('Import fehlgeschlagen: %s. Die Site wurde nicht verändert und läuft unverändert weiter.', 'rh-sync'),
                        $e->getMessage()
                    ),
                    SyncStatus::PHASE_IMPORT
                );
                return;
            }

            // Ab hier stehen die neuen Daten live: jetzt zählt das Sicherheits-Backup.
            $job->cursor['ij_phase'] = 'rollback';
            $job->save();
            return;
        }

        $job->cursor['ij_import_cursor'] = $cursor->toArray();
        $job->setProgress($cursor->sqlByteOffset, 0);

        JobTrace::write($job->jobId, 'import_step_end', [
            'import_phase' => $cursor->phase,
            'sql_offset' => $cursor->sqlByteOffset,
            'tables' => count($cursor->createdTables),
        ]);

        if ($cursor->isDone()) {
            $job->importCommitted = true;

            // Beim atomaren Umschalten standen die site-eigenen Options schon in der
            // Schattentabelle, bevor sie live ging. Dann gibt es hier nichts mehr zu
            // reparieren. Nur auf dem direkten Weg muss nachträglich zurückgeschrieben
            // werden, und genau dieses Fenster ist das Risiko.
            if (!$guardApplied && $snapshot !== []) {
                try {
                    (new LocalOptionGuard())->restore($snapshot);
                } catch (\Throwable $e) {
                    // Hier sind die neuen Daten schon live. Greift der Schutz jetzt nicht,
                    // steht die Zielseite mit der Adresse, den Plugins und der Kopplung der
                    // Quelle da. Das darf nicht als Erfolg durchgehen, und die Rettungsleine
                    // bleibt liegen, damit sich der Zustand von Hand richten lässt.
                    JobTrace::write($job->jobId, 'guard_failed_after_import', [
                        'options' => count($snapshot),
                        'message' => $e->getMessage(),
                    ]);

                    $job->finishFailure(
                        sprintf(
                            /* translators: %1$s = Fehlermeldung, %2$s = Pfad zum Options-Snapshot */
                            __('Die Daten sind eingespielt, aber die site-eigenen Einstellungen konnten nicht zurückgeschrieben werden: %1$s. Diese Website trägt jetzt möglicherweise Adresse, Plugin-Liste und Kopplung der Quelle. Der Stand von vorher liegt in %2$s.', 'rh-sync'),
                            $e->getMessage(),
                            (string) ($job->cursor['ij_guard_file'] ?? '(nicht abgelegt)')
                        ),
                        SyncStatus::PHASE_IMPORT,
                        (string) ($job->cursor['ij_safety_path'] ?? '')
                    );

                    return;
                }

                JobTrace::write($job->jobId, 'guard_restored_after_import', ['options' => count($snapshot)]);
            }

            // Bei users-Pull: die Session des auslösenden Admins wiederherstellen (kein Logout).
            // Nur gesetzt, wenn der Trigger im eingeloggten Kontext einen Snapshot gemacht hat.
            if (isset($job->cursor['session_guard']) && is_array($job->cursor['session_guard'])) {
                (new SessionGuard())->restore($job->cursor['session_guard']);
            }

            // Die Daten stehen. Was jetzt noch fehlt, sind die Termine, die an ihnen hängen:
            // die Option `cron` bleibt beim Import ziel-lokal, also kommen die Beiträge ohne
            // ihre Wecker an. Das ist ein eigener Schritt mit eigenem Zeitbudget, weil jeder
            // gesetzte Termin ein Schreibvorgang auf eine wachsende Option ist.
            $job->cursor['ij_swap_mode'] = $cursor->swapMode;
            // Welche der ausgelassenen Tabellen die Quelle benutzt. Der Import-Cursor wird
            // gleich weggeräumt, der Nachlauf braucht die Angabe aber noch.
            $job->cursor['ij_source_tables'] = is_array($cursor->manifest['excluded_tables_present'] ?? null)
                ? array_map('strval', $cursor->manifest['excluded_tables_present'])
                : [];
            $job->cursor['ij_phase'] = 'aftercare';
            $job->cursor['ij_aftercare'] = ScheduleRebuilder::start($profile->options);
            $job->updateStepMessage(
                SyncStatus::PHASE_IMPORT,
                __('Stelle Veröffentlichungs-Termine wieder her...', 'rh-sync')
            );
            $job->save();
            return;
        }

        $job->save();
    }

    /**
     * Nachlauf: die Termine wiederherstellen, die an den importierten Inhalten hängen.
     *
     * Alles hier liegt in einem Auffangnetz. Die Daten stehen zu diesem Zeitpunkt bereits
     * live, der Import ist gewonnen. Ein Nachlauf, der stolpert, darf ihn nicht nachträglich
     * zum Fehlschlag machen: dann eben ohne Bericht, aber mit einer Zeile im Verlaufsprotokoll.
     */
    private function stepAftercare(JobState $job): void
    {
        $report = null;

        try {
            /** @var array<string, mixed> $state */
            $state = is_array($job->cursor['ij_aftercare'] ?? null)
                ? $job->cursor['ij_aftercare']
                : ScheduleRebuilder::start();

            // Der Nachlauf bekommt höchstens zehn Sekunden pro Durchgang, auch wenn der Job
            // mehr dürfte. Er ist Kür, nicht Pflicht, und soll den Abschluss nicht aufhalten.
            $deadline = microtime(true) + min($job->tickBudget, 10.0);

            $state = (new ScheduleRebuilder())->step($state, $deadline);
            $job->cursor['ij_aftercare'] = $state;

            if (!ScheduleRebuilder::isDone($state)) {
                $job->save();
                return;
            }

            $report = ScheduleRebuilder::report($state);
            JobTrace::write($job->jobId, 'schedule_rebuilt', $report->toArray());
        } catch (\Throwable $e) {
            JobTrace::write($job->jobId, 'aftercare_failed', ['message' => $e->getMessage()]);
        }

        $job->completeStep(SyncStatus::PHASE_IMPORT, __('Import abgeschlossen', 'rh-sync'));
        $this->cleanupWorkdirs($job);
        $this->standDown($job);
        JobTrace::write($job->jobId, 'import_done', [
            'swap' => $job->cursor['ij_swap_mode'] ?? null,
        ]);

        // Tabellen, die die Quelle benutzt und die es hier noch nie gab, fehlen nach dem
        // Import einfach. Das meldet niemand sonst, WordPress läuft dann mit einem
        // Datenbankfehler pro Aufruf weiter.
        $fehlendeTabellen = [];
        try {
            /** @var array<int, string> $aufDerQuelle */
            $aufDerQuelle = is_array($job->cursor['ij_source_tables'] ?? null) ? $job->cursor['ij_source_tables'] : [];
            $fehlendeTabellen = (new MissingTables())->detect($aufDerQuelle);
            if ($fehlendeTabellen !== []) {
                JobTrace::write($job->jobId, 'tables_missing', ['tables' => $fehlendeTabellen]);
            }
        } catch (\Throwable $e) {
            JobTrace::write($job->jobId, 'table_check_failed', ['message' => $e->getMessage()]);
        }

        $summary = [
            'safety_backup_path' => $job->cursor['ij_safety_path'] ?? null,
            'profile' => $job->profile,
            'schedule_rebuild' => $report?->toArray(),
            'missing_tables' => $fehlendeTabellen,
        ];

        $notes = [];
        $note = $report?->note();
        if ($note !== null) {
            $notes[] = $note;
        }
        $tabellenHinweis = (new MissingTables())->note($fehlendeTabellen);
        if ($tabellenHinweis !== null) {
            $notes[] = $tabellenHinweis;
        }
        if ($notes !== []) {
            $summary['notes'] = $notes;
        }

        $job->finishSuccess($summary);
    }

    private function stepRollback(JobState $job): void
    {
        $error = (string) ($job->cursor['ij_error'] ?? 'Unbekannter Importfehler');
        $safety = (string) ($job->cursor['ij_safety_path'] ?? '');

        if ($safety === '' || !is_readable($safety)) {
            $this->cleanupWorkdirs($job);
            // Die Notluke bleibt hier bewusst offen: ohne Sicherungskopie ist sie das
            // einzige, was die Zielseite noch geradebiegen kann.
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
            // Der Vor-Zustand steht wieder, damit ist die Notluke überflüssig.
            $this->standDown($job);
            JobTrace::write($job->jobId, 'rollback_done', []);
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

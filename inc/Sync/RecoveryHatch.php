<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Notluke für den Fall, dass der Import die Zielseite unbedienbar macht.
 *
 * Die Tick-Kette treibt sich über WP-Cron und einen Loopback auf admin-ajax.php an. Beide
 * brauchen ein startfähiges WordPress, und WordPress braucht die Optionen, die ein Import
 * gerade ersetzt. Macht der Import die Site kaputt, kann er sich weder fortsetzen noch
 * aufräumen. Am 2026-08-02 stand er deshalb neunzig Minuten still, und die Rettung ging
 * nur noch von Hand über FTP.
 *
 * Diese Luke ist eine eigenständige Datei ohne WordPress-Abhängigkeit. Sie wird nur dann
 * scharfgeschaltet, wenn der Import NICHT atomar umschalten kann, also nur auf dem Weg, der
 * die Site überhaupt gefährden kann. Auf dem normalen Weg entsteht sie gar nicht erst.
 *
 * Abgesichert über drei Dinge: ein nicht erratbarer Dateiname, ein Kennwort, das nur als
 * Prüfsumme in der Datei steht, und eine Frist, nach der sich die Datei selbst entfernt.
 */
final class RecoveryHatch
{
    private const PREFIX = 'rh-sync-notfall-';

    /** Wie lange die Luke offen steht, wenn sie niemand schliesst. */
    private const TTL = 12 * HOUR_IN_SECONDS;

    /**
     * Schaltet die Luke scharf.
     *
     * @return array{url: string, token: string, file: string}|null null, wenn abgeschaltet
     *                                                              oder nicht schreibbar.
     */
    public function arm(string $jobId, string $snapshotPath): ?array
    {
        /**
         * Wer die Luke nicht will, schaltet sie ab. Dann bleibt im Ernstfall nur der
         * Weg über FTP und das Sicherheits-Backup.
         */
        if (!apply_filters('rh-blueprint/sync/recovery_hatch', true, $jobId)) {
            return null;
        }

        $template = dirname(__DIR__, 2) . '/templates/notfall.php.tpl';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- eigene Vorlage aus dem Plugin-Verzeichnis.
        $source = @file_get_contents($template);
        if (!is_string($source) || $source === '') {
            return null;
        }

        $slug = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(16));
        $file = trailingslashit(WP_CONTENT_DIR) . self::PREFIX . $slug . '.php';

        $contents = strtr($source, [
            '{{TOKEN_HASH}}' => hash('sha256', $token),
            '{{SNAPSHOT_PATH}}' => $snapshotPath,
            '{{WP_CONFIG}}' => $this->wpConfigPath(),
            // Als Zeichenkette eingesetzt, damit die Vorlage selbst gültiges PHP bleibt und
            // sich prüfen lässt (`php -l`), statt erst nach dem Einsetzen.
            '{{EXPIRES}}' => (string) (time() + self::TTL),
            '{{JOB_ID}}' => $jobId,
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- die Luke muss auch dann entstehen, wenn WP_Filesystem nicht verfügbar ist.
        if (@file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
            return null;
        }

        $url = trailingslashit(content_url()) . basename($file) . '?token=' . $token;

        JobTrace::write($jobId, 'recovery_armed', ['url' => $url]);

        return ['url' => $url, 'token' => $token, 'file' => $file];
    }

    /**
     * Schliesst die Luke wieder. Ohne Job-Bezug, weil der Job-Zustand genau dann fehlen
     * kann, wenn es darauf ankommt.
     */
    public function disarm(?string $file = null): void
    {
        if (is_string($file) && $file !== '' && is_file($file)) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Aufräumen der eigenen Datei.
            @unlink($file);
            return;
        }

        foreach ($this->existing() as $path) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Aufräumen der eigenen Dateien.
            @unlink($path);
        }
    }

    /**
     * Entfernt Luken, die länger offen stehen als vorgesehen.
     *
     * Greift im Watchdog. Eine vergessene Luke ist genau die Art Altlast, die man Monate
     * später auf einer Kundensite findet.
     */
    public function gc(): int
    {
        $removed = 0;
        foreach ($this->existing() as $path) {
            $mtime = @filemtime($path);
            if ($mtime === false || (time() - $mtime) < self::TTL) {
                continue;
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Aufräumen der eigenen Datei.
            @unlink($path);
            $removed++;
        }

        return $removed;
    }

    /**
     * @return array<int, string>
     */
    private function existing(): array
    {
        $found = glob(trailingslashit(WP_CONTENT_DIR) . self::PREFIX . '*.php');

        return is_array($found) ? $found : [];
    }

    private function wpConfigPath(): string
    {
        $inRoot = trailingslashit(ABSPATH) . 'wp-config.php';
        if (is_readable($inRoot)) {
            return $inRoot;
        }

        // WordPress akzeptiert die Konfiguration auch eine Ebene über dem Stammverzeichnis,
        // solange dort keine zweite wp-settings.php liegt.
        return trailingslashit(dirname(untrailingslashit(ABSPATH))) . 'wp-config.php';
    }
}

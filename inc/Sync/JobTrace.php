<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Verlaufsprotokoll eines Sync-Laufs, das einen Absturz überlebt.
 *
 * Der Job-Zustand liegt in der Datenbank. Genau deshalb war am 2026-08-02 nicht mehr
 * feststellbar, woran der Import gestorben ist: der Zustand lag in derselben Tabelle, die
 * der Import gerade ersetzt hat, und war hinterher weg. Neunzig Minuten Stillstand ohne
 * eine einzige Zeile, die erklärt hätte, warum.
 *
 * Dieses Protokoll liegt als Datei neben den Jobs, wird nur angehängt und nach jedem
 * Eintrag geschrieben. Es überlebt einen Speicher-Abbruch, ein Zeitlimit und auch ein
 * hartes Abschiessen des Prozesses. Pro Zeile eine JSON-Struktur mit Zeit, Job, Ereignis,
 * Phase und Speicherverbrauch.
 *
 * Zwei Fälle, zwei Antworten:
 *   - PHP bricht selbst ab (Speicher voll, Zeitlimit): das Abschluss-Ereignis fängt es und
 *     schreibt die echte Fehlermeldung mit.
 *   - Der Webserver schiesst den Prozess ab: dann fehlt schlicht der Abschluss zum letzten
 *     Beginn. Auch das ist eine Aussage, und der letzte Speicherwert zeigt, wie eng es war.
 */
final class JobTrace
{
    public const FILE = 'sync-trace.log';

    /** Ab dieser Grösse wird einmal rotiert, damit das Protokoll die Platte nicht füllt. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Notreserve, die im Abschluss-Handler freigegeben wird.
     *
     * Nach einem Speicher-Abbruch ist kein Byte mehr frei, und der Handler könnte seine
     * eigene Zeile nicht mehr schreiben. Diese Reserve wird beim Scharfschalten belegt und
     * im Ernstfall zuerst wieder losgelassen.
     */
    private static ?string $reserve = null;

    private static bool $armed = false;

    /** @var array<string, mixed> */
    private static array $current = [];

    /**
     * Hängt eine Zeile an. Schlägt nie fehl, ein Protokoll darf keinen Sync abbrechen.
     *
     * @param array<string, mixed> $context
     */
    public static function write(string $jobId, string $event, array $context = []): void
    {
        $path = self::path();
        if ($path === null) {
            return;
        }

        $line = [
            'ts' => gmdate('c'),
            'job' => $jobId,
            'event' => $event,
            'mem' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => (string) ini_get('memory_limit'),
        ] + $context;

        $json = wp_json_encode($line);
        if (!is_string($json)) {
            return;
        }

        self::rotateIfNeeded($path);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Anhängen mit Sperre, WP_Filesystem kennt kein FILE_APPEND und überlebt keinen Abbruch.
        @file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Schaltet die Absturz-Erkennung für diesen Request scharf.
     *
     * @param array<string, mixed> $context
     */
    public static function arm(string $jobId, array $context = []): void
    {
        self::$current = ['job' => $jobId] + $context;

        if (self::$armed) {
            return;
        }

        self::$armed = true;
        self::$reserve = str_repeat(' ', 256 * 1024);

        register_shutdown_function(static function (): void {
            self::$reserve = null;

            $error = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

            if ($error === null || !in_array((int) $error['type'], $fatal, true)) {
                return;
            }

            $jobId = (string) (self::$current['job'] ?? 'unbekannt');
            $context = self::$current;
            unset($context['job']);

            self::write($jobId, 'fatal', $context + [
                'message' => (string) $error['message'],
                'file' => (string) $error['file'],
                'line' => (int) $error['line'],
            ]);
        });
    }

    /**
     * Aktualisiert den Kontext, den der Abschluss-Handler im Ernstfall mitschreibt.
     *
     * @param array<string, mixed> $context
     */
    public static function context(array $context): void
    {
        self::$current = array_merge(self::$current, $context);
    }

    /**
     * Pfad zum Protokoll, oder null wenn die Ablage nicht bereit ist.
     */
    public static function path(): ?string
    {
        if (!function_exists('rh_db_engine')) {
            return null;
        }

        $dir = rh_db_engine()->storage()->jobsPath();
        if (!is_dir($dir)) {
            return null;
        }

        return trailingslashit($dir) . self::FILE;
    }

    /**
     * Die letzten Zeilen des Protokolls, jüngste zuerst. Für die Fehlersuche in der Oberfläche.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tail(int $lines = 50): array
    {
        $path = self::path();
        if ($path === null || !is_readable($path)) {
            return [];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Lesen des eigenen Protokolls, keine Nutzer-Datei.
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return [];
        }

        $all = array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => trim($l) !== ''));
        $slice = array_slice($all, -$lines);

        $out = [];
        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private static function rotateIfNeeded(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $size = @filesize($path);
        if ($size === false || $size < self::MAX_BYTES) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- einfache Rotation der eigenen Protokolldatei.
        @rename($path, $path . '.1');
    }
}

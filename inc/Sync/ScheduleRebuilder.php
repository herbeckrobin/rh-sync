<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Stellt nach einem Import die Termine wieder her, die an Inhalten hängen.
 *
 * Warum das nötig ist: der {@see LocalOptionGuard} schützt beim Import die Option `cron`,
 * damit die Sync-Zustände der Quelle nicht die der Zielseite überschreiben. Die Beiträge
 * wandern also mit, ihre Wecker nicht. Für geplante Beiträge hat WordPress keine
 * Selbstheilung: anders als beim Papierkorb-Aufräumen oder den Update-Prüfungen trägt sich
 * `publish_future_post` nie von selbst nach. Ein geplanter Beitrag ohne Termin bleibt
 * dauerhaft liegen und erscheint nie.
 *
 * Das Modell dahinter, und es trägt den ganzen Schritt: die Cron-Einträge dieser drei Hooks
 * sind eine reine Funktion der posts-Tabelle. Nach dem Import wird sie neu ausgewertet.
 *
 * Zwei Dinge, die hier nicht verhandelbar sind:
 *
 * 1. Der Termin kommt aus `post_date` über `get_gmt_from_date()`, wie es WordPress in
 *    `_future_post_hook()` selbst tut. NICHT aus `post_date_gmt`: das Feld ist auf
 *    importierten Beiträgen unzuverlässig (auf einer echten Kundenseite stand dort das
 *    Erstellungs- statt des Veröffentlichungsdatums). Wer daraus rechnet, plant alles in die
 *    Vergangenheit, und der nächste Cron-Lauf veröffentlicht auf einen Schlag auch das, was
 *    für nächstes Jahr gedacht war.
 * 2. Die Beitrags-ID geht als Integer ins Argument-Array. WordPress erkennt Dubletten über
 *    `md5(serialize($args))`, und `['42']` ist damit ein anderer Termin als `[42]`. Ein
 *    String rutscht durch jede Vorprüfung und es feuern am Ende zwei.
 *
 * Termine in der Vergangenheit werden bewusst NICHT gesetzt, sondern nur gemeldet. Ein
 * Sync soll nichts veröffentlichen. Was liegengeblieben ist, entscheidet ein Mensch.
 */
final class ScheduleRebuilder
{
    public const HOOK_PUBLISH = 'publish_future_post';
    public const HOOK_PINGS = 'do_pings';
    public const HOOK_IMPORTER = 'importer_scheduled_cleanup';

    /** Beiträge pro Datenbank-Abfrage. */
    private const PAGE = 200;

    /**
     * Obergrenzen. Jeder gesetzte Termin ist ein Schreibvorgang auf eine wachsende Option,
     * das skaliert nicht beliebig. Wird eine Grenze erreicht, endet der Lauf ehrlich als
     * unvollständig, statt sich festzufahren.
     */
    private const MAX_EVENTS = 5000;
    private const MAX_TICKS = 20;

    /** Abweichung in Sekunden, ab der ein vorhandener Termin als falsch gilt. */
    private const DRIFT_TOLERANCE = 60;

    public const STAGE_TYPES = 'types';
    public const STAGE_POSTS = 'posts';
    public const STAGE_PINGS = 'pings';
    public const STAGE_IMPORTER = 'importer';
    public const STAGE_ORPHANS = 'orphans';
    public const STAGE_DONE = 'done';

    /**
     * Der Anfangszustand. Wandert in den Job-Cursor, muss also flach und serialisierbar sein.
     *
     * @param bool $optionsSynced Wurden die Einstellungen mitgesynct? Wenn nicht, gehört die
     *                            verwendete Zeitzone in den Bericht: sie ist dann die der
     *                            Zielseite und passt womöglich nicht zu den Beitragsdaten.
     * @return array<string, mixed>
     */
    public static function start(bool $optionsSynced = true): array
    {
        return [
            'stage' => self::STAGE_TYPES,
            'types' => [],
            'type_index' => 0,
            'after_date' => '',
            'after_id' => 0,
            'ticks' => 0,
            'events' => 0,
            'own_timezone' => !$optionsSynced,
            'report' => [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function isDone(array $state): bool
    {
        return (string) ($state['stage'] ?? self::STAGE_DONE) === self::STAGE_DONE;
    }

    /**
     * Arbeitet bis zur Deadline und gibt den neuen Zustand zurück.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function step(array $state, float $deadline): array
    {
        $state['ticks'] = (int) ($state['ticks'] ?? 0) + 1;

        if ((int) $state['ticks'] > self::MAX_TICKS) {
            $state['report']['truncated'] = true;
            $state['stage'] = self::STAGE_DONE;

            return $state;
        }

        while (!self::isDone($state)) {
            $state = match ((string) $state['stage']) {
                self::STAGE_TYPES => $this->collectTypes($state),
                self::STAGE_POSTS => $this->processPosts($state, $deadline),
                self::STAGE_PINGS => $this->processPings($state),
                self::STAGE_IMPORTER => $this->processImporter($state, $deadline),
                self::STAGE_ORPHANS => $this->processOrphans($state, $deadline),
                default => array_merge($state, ['stage' => self::STAGE_DONE]),
            };

            if ((int) ($state['events'] ?? 0) >= self::MAX_EVENTS) {
                $state['report']['truncated'] = true;
                $state['stage'] = self::STAGE_DONE;
                break;
            }

            if (microtime(true) >= $deadline && !self::isDone($state)) {
                break;
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function report(array $state): ScheduleReport
    {
        /** @var array<string, mixed> $raw */
        $raw = is_array($state['report'] ?? null) ? $state['report'] : [];

        $raw['timezone'] = $raw['timezone'] ?? self::timezoneLabel();
        $raw['own_timezone'] = (bool) ($state['own_timezone'] ?? false);

        return ScheduleReport::fromArray($raw);
    }

    /**
     * Für den alten synchronen Import-Pfad: läuft ohne Zeitbudget durch.
     */
    public function runToCompletion(bool $optionsSynced = true): ScheduleReport
    {
        $state = self::start($optionsSynced);

        while (!self::isDone($state)) {
            $state = $this->step($state, microtime(true) + 3600.0);
        }

        return self::report($state);
    }

    // ---------------------------------------------------------------- Stufen

    /**
     * Welche Inhaltsarten gibt es überhaupt?
     *
     * Der Umweg über die Typen ist kein Selbstzweck: der Index `type_status_date` beginnt mit
     * `post_type`. Eine Abfrage nur auf den Status wäre ein voller Tabellen-Scan und damit auf
     * grossen Websites genau im teuersten Moment des Laufs teuer.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function collectTypes(array $state): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interne Tabelle, einmalige Wartungs-Abfrage nach dem Import.
        $types = $wpdb->get_col("SELECT DISTINCT post_type FROM {$wpdb->posts}");

        $state['types'] = array_values(array_map('strval', (array) $types));
        $state['type_index'] = 0;
        $state['after_date'] = '';
        $state['after_id'] = 0;
        $state['stage'] = $state['types'] === [] ? self::STAGE_PINGS : self::STAGE_POSTS;

        return $state;
    }

    /**
     * Geplante Beiträge, Inhaltsart für Inhaltsart, seitenweise über den Index.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processPosts(array $state, float $deadline): array
    {
        global $wpdb;

        /** @var array<int, string> $types */
        $types = (array) ($state['types'] ?? []);
        $index = (int) ($state['type_index'] ?? 0);

        if (!isset($types[$index])) {
            $state['stage'] = self::STAGE_PINGS;

            return $state;
        }

        $type = (string) $types[$index];
        $afterDate = (string) ($state['after_date'] ?? '');
        $afterId = (int) ($state['after_id'] ?? 0);

        if ($afterDate === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_date FROM {$wpdb->posts}
                  WHERE post_type = %s AND post_status = 'future'
                  ORDER BY post_date ASC, ID ASC
                  LIMIT %d",
                $type,
                self::PAGE
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_date FROM {$wpdb->posts}
                  WHERE post_type = %s AND post_status = 'future'
                    AND (post_date > %s OR (post_date = %s AND ID > %d))
                  ORDER BY post_date ASC, ID ASC
                  LIMIT %d",
                $type,
                $afterDate,
                $afterDate,
                $afterId,
                self::PAGE
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- vorbereitete Abfrage, interne Tabelle.
        $rows = (array) $wpdb->get_results((string) $sql, ARRAY_A);

        $typeKnown = !function_exists('post_type_exists') || post_type_exists($type);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->handleFuturePost($state, $row, $typeKnown);

            $state['after_date'] = (string) ($row['post_date'] ?? '');
            $state['after_id'] = (int) ($row['ID'] ?? 0);
            $state['report']['scanned'] = (int) ($state['report']['scanned'] ?? 0) + 1;

            if ((int) ($state['events'] ?? 0) >= self::MAX_EVENTS) {
                return $state;
            }

            if (microtime(true) >= $deadline) {
                return $state;
            }
        }

        if (count($rows) < self::PAGE) {
            $state['type_index'] = $index + 1;
            $state['after_date'] = '';
            $state['after_id'] = 0;
        }

        return $state;
    }

    /**
     * Ein einzelner geplanter Beitrag.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $row
     */
    private function handleFuturePost(array &$state, array $row, bool $typeKnown): void
    {
        $id = (int) ($row['ID'] ?? 0);
        if ($id <= 0) {
            return;
        }

        if (!$typeKnown) {
            $type = (string) ($row['post_type'] ?? '');
            $state['report']['unregistered_types'][$type] =
                (int) ($state['report']['unregistered_types'][$type] ?? 0) + 1;
        }

        $target = $this->targetTimestamp((string) ($row['post_date'] ?? ''));
        if ($target === null) {
            // Ein unbrauchbares Datum lässt sich nicht in einen Termin übersetzen.
            $state['report']['failed'] = (int) ($state['report']['failed'] ?? 0) + 1;

            return;
        }

        $existing = wp_next_scheduled(self::HOOK_PUBLISH, [$id]);

        if ($existing !== false) {
            // Es gibt bereits einen Termin. Nur wenn er deutlich abweicht UND der richtige
            // Zeitpunkt noch bevorsteht, wird er geradegezogen. Liegt der richtige Zeitpunkt
            // in der Vergangenheit, bleibt der vorhandene Termin stehen: der Cron-Lauf holt
            // ihn ohnehin, und wir verschieben nichts nach vorn.
            if ($target > time() && abs((int) $existing - $target) > self::DRIFT_TOLERANCE) {
                // Zwei Schreibvorgänge auf die Cron-Option, also zählen sie auch zweimal gegen
                // die Obergrenze: erst das Entfernen, dann das Neusetzen.
                wp_unschedule_event((int) $existing, self::HOOK_PUBLISH, [$id]);
                $state['events'] = (int) ($state['events'] ?? 0) + 1;

                if ($this->schedule($target, self::HOOK_PUBLISH, [$id])) {
                    $state['report']['corrected'] = (int) ($state['report']['corrected'] ?? 0) + 1;
                } else {
                    $state['report']['failed'] = (int) ($state['report']['failed'] ?? 0) + 1;
                }
                $state['events'] = (int) $state['events'] + 1;
            }

            return;
        }

        if ($target <= time()) {
            // Überfällig: nicht planen, nur melden. Ein Sync veröffentlicht nichts.
            $total = (int) ($state['report']['overdue_total'] ?? 0) + 1;
            $state['report']['overdue_total'] = $total;

            $listed = is_array($state['report']['overdue'] ?? null) ? $state['report']['overdue'] : [];
            if (count($listed) < ScheduleReport::MAX_LISTED) {
                $listed[] = [
                    'id' => $id,
                    'title' => $this->shortTitle((string) ($row['post_title'] ?? '')),
                    'type' => (string) ($row['post_type'] ?? ''),
                    'date' => $this->displayDate((string) ($row['post_date'] ?? '')),
                ];
                $state['report']['overdue'] = $listed;
            }

            return;
        }

        if ($this->schedule($target, self::HOOK_PUBLISH, [$id])) {
            $state['report']['scheduled'] = (int) ($state['report']['scheduled'] ?? 0) + 1;
        } else {
            $state['report']['failed'] = (int) ($state['report']['failed'] ?? 0) + 1;
        }
        $state['events'] = (int) ($state['events'] ?? 0) + 1;
    }

    /**
     * Trackbacks und Verweise, die nach dem Veröffentlichen verschickt werden wollen.
     *
     * Der Termin hängt hier nicht am Beitrag, sondern an zwei Meta-Feldern. Liegt eines
     * davon herum und es gibt keinen Termin, würde es nie abgearbeitet.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processPings(array $state): array
    {
        global $wpdb;

        $state['stage'] = self::STAGE_IMPORTER;

        if (wp_next_scheduled(self::HOOK_PINGS) !== false) {
            return $state;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interne Tabelle, bricht beim ersten Treffer ab.
        $pending = $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key IN ('_pingme', '_encloseme') LIMIT 1"
        );

        if ($pending === null) {
            return $state;
        }

        // Eine Minute Versatz, damit der Termin nicht im selben Durchgang losläuft, in dem
        // der Sync gerade seinen eigenen Loopback anstößt.
        if ($this->schedule(time() + MINUTE_IN_SECONDS, self::HOOK_PINGS, [])) {
            $state['report']['pings'] = 1;
        } else {
            $state['report']['failed'] = (int) ($state['report']['failed'] ?? 0) + 1;
        }
        $state['events'] = (int) ($state['events'] ?? 0) + 1;

        return $state;
    }

    /**
     * Das Aufräumen nach einem WordPress-Import: die Zwischendatei soll nach einem Tag weg.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processImporter(array $state, float $deadline): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interne Tabelle, sehr kleine Ergebnismenge.
        $rows = (array) $wpdb->get_results(
            "SELECT ID, post_date FROM {$wpdb->posts}
              WHERE post_type = 'attachment' AND post_mime_type = 'import'
              ORDER BY ID ASC
              LIMIT 500",
            ARRAY_A
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['ID'] ?? 0);
            if ($id <= 0 || wp_next_scheduled(self::HOOK_IMPORTER, [$id]) !== false) {
                continue;
            }

            $base = $this->targetTimestamp((string) ($row['post_date'] ?? ''));
            if ($base === null) {
                continue;
            }

            $target = $base + DAY_IN_SECONDS;

            if ($target <= time()) {
                // Dieselbe Regel wie bei den Beiträgen: nichts rückwirkend anstoßen, melden.
                $state['report']['importer_overdue'] = (int) ($state['report']['importer_overdue'] ?? 0) + 1;
                continue;
            }

            if ($this->schedule($target, self::HOOK_IMPORTER, [$id])) {
                $state['report']['importer_cleanups'] = (int) ($state['report']['importer_cleanups'] ?? 0) + 1;
            } else {
                $state['report']['failed'] = (int) ($state['report']['failed'] ?? 0) + 1;
            }
            $state['events'] = (int) ($state['events'] ?? 0) + 1;

            if (microtime(true) >= $deadline) {
                // Der nächste Durchgang sieht die schon gesetzten Termine und überspringt sie.
                return $state;
            }
        }

        $state['stage'] = self::STAGE_ORPHANS;

        return $state;
    }

    /**
     * Termine, deren Beitrag es nicht mehr gibt oder der längst nicht mehr geplant ist.
     *
     * Angefasst werden ausschliesslich die drei eigenen Hooks. Ein pauschales Aufräumen des
     * ganzen Cron-Arrays wäre genau die Art Kollateralschaden, die man Monate später sucht.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processOrphans(array $state, float $deadline): array
    {
        global $wpdb;

        $state['stage'] = self::STAGE_DONE;

        $crons = _get_cron_array();
        if (!is_array($crons) || $crons === []) {
            return $state;
        }

        /** @var array<int, array{ts: int, hook: string, args: array<int, mixed>, id: int, stringId: bool}> $candidates */
        $candidates = [];
        $ids = [];

        foreach ($crons as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            foreach ([self::HOOK_PUBLISH, self::HOOK_IMPORTER] as $hook) {
                if (!isset($hooks[$hook]) || !is_array($hooks[$hook])) {
                    continue;
                }

                foreach ($hooks[$hook] as $event) {
                    $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                    $raw = $args[0] ?? null;

                    if (!is_int($raw) && !is_string($raw)) {
                        continue;
                    }

                    $id = (int) $raw;
                    if ($id <= 0) {
                        continue;
                    }

                    $candidates[] = [
                        'ts' => (int) $timestamp,
                        'hook' => $hook,
                        'args' => $args,
                        'id' => $id,
                        'stringId' => !is_int($raw),
                    ];
                    $ids[$id] = true;
                }
            }
        }

        if ($candidates === []) {
            return $state;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter aus der Anzahl gebaut, Werte gehen durch prepare.
            "SELECT ID, post_status, post_type FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
            array_keys($ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- vorbereitete Abfrage, interne Tabelle.
        $rows = (array) $wpdb->get_results((string) $sql, ARRAY_A);

        $known = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $known[(int) ($row['ID'] ?? 0)] = [
                'status' => (string) ($row['post_status'] ?? ''),
                'type' => (string) ($row['post_type'] ?? ''),
            ];
        }

        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            $exists = isset($known[$id]);

            $orphan = !$exists;
            $stale = false;

            if ($exists && $candidate['hook'] === self::HOOK_PUBLISH) {
                $stale = $known[$id]['status'] !== 'future' || $candidate['stringId'];
            } elseif ($exists && $candidate['hook'] === self::HOOK_IMPORTER) {
                $stale = $known[$id]['type'] !== 'attachment' || $candidate['stringId'];
            }

            if (!$orphan && !$stale) {
                continue;
            }

            // Mit den ursprünglichen Argumenten entfernen, nicht mit den umgewandelten:
            // sonst trifft der Schlüssel nicht und der Eintrag bleibt stehen.
            wp_unschedule_event($candidate['ts'], $candidate['hook'], $candidate['args']);
            $state['events'] = (int) ($state['events'] ?? 0) + 1;

            $key = $orphan ? 'orphans_removed' : 'stale_removed';
            $state['report'][$key] = (int) ($state['report'][$key] ?? 0) + 1;

            if (microtime(true) >= $deadline) {
                // Kein Fortsetzungs-Cursor nötig: entfernte Einträge tauchen im nächsten
                // Durchgang nicht mehr auf.
                $state['stage'] = self::STAGE_ORPHANS;

                return $state;
            }
        }

        $state['stage'] = self::STAGE_DONE;

        return $state;
    }

    // ---------------------------------------------------------------- Helfer

    /**
     * Der Zeitpunkt in GMT, gerechnet aus dem lokalen Beitragsdatum.
     *
     * Die Rechnung selbst steht in {@see Schedule::targetTimestamp()}, damit die Diagnose
     * garantiert dasselbe herausbekommt wie der Wiederaufbau.
     */
    private function targetTimestamp(string $postDate): ?int
    {
        return Schedule::targetTimestamp($postDate);
    }

    /**
     * Setzt einen Termin über die WordPress-Schnittstelle.
     *
     * Bewusst nicht direkt in die Option geschrieben, obwohl das schneller wäre: der Umweg
     * über die Schnittstelle ist der einzige, den Ersatz-Lösungen für den WordPress-Cron
     * (etwa beim Hoster) mitbekommen. Der Rückgabewert kann auch ein Fehlerobjekt sein,
     * deshalb strikt auf `true` prüfen und im Zweifel weiterlaufen statt abzubrechen.
     *
     * @param array<int, mixed> $args
     */
    private function schedule(int $timestamp, string $hook, array $args): bool
    {
        $result = wp_schedule_single_event($timestamp, $hook, $args);

        return $result === true;
    }

    private function shortTitle(string $title): string
    {
        return Schedule::shortTitle($title, 80);
    }

    private function displayDate(string $postDate): string
    {
        if ($postDate === '') {
            return '';
        }

        $formatted = mysql2date('d.m.Y H:i', $postDate, false);

        return is_string($formatted) && $formatted !== '' ? $formatted : $postDate;
    }

    private static function timezoneLabel(): string
    {
        if (function_exists('wp_timezone_string')) {
            return (string) wp_timezone_string();
        }

        return (string) get_option('timezone_string', 'UTC');
    }
}

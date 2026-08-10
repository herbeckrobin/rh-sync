<?php

declare(strict_types=1);

namespace RhSync\Cli;

use RhSync\Sync\Schedule;
use RhSync\Sync\ScheduleRebuilder;

/**
 * Misst den Ist-Zustand der Termine, ohne etwas zu ändern.
 *
 * Das Werkzeug, mit dem der Befund auf Entrümpel-König in einem Aufruf sichtbar gewesen wäre:
 * 14 geplante Beiträge, kein einziger Termin. Von Hand war das eine halbe Stunde Graben in der
 * Options-Tabelle.
 *
 * Die Abfrage geht bewusst denselben Weg wie {@see ScheduleRebuilder}: erst die Inhaltsarten,
 * dann pro Art über den Index-Präfix. Ein Filter nur auf `post_status` wäre ein voller
 * Tabellen-Scan, und eine Diagnose soll die Website nicht ausbremsen.
 */
final class ScheduleInspector
{
    public const STATE_OK = 'ok';
    public const STATE_MISSING = 'fehlt';
    public const STATE_OVERDUE = 'überfällig';
    public const STATE_DRIFT = 'abweichend';
    public const STATE_UNREADABLE = 'unlesbar';

    /** Abweichung in Sekunden, ab der ein Termin als falsch gilt. Wie im Rebuilder. */
    private const DRIFT_TOLERANCE = 60;

    /**
     * Ein Eintrag je geplantem Beitrag.
     *
     * @param int $limit Obergrenze, damit die Diagnose auf sehr grossen Websites nicht ausufert.
     * @return array<int, array{id: int, typ: string, titel: string, geplant_fuer: string, termin: string, zustand: string}>
     */
    public function posts(int $limit = 500): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnose-Abfrage auf interne Tabelle.
        $types = (array) $wpdb->get_col("SELECT DISTINCT post_type FROM {$wpdb->posts}");

        // Einmal alle Termine lesen, statt pro Beitrag nachzufragen. `wp_next_scheduled()`
        // läuft bei jedem Aufruf das ganze Cron-Array durch; auf einer Website mit einigen
        // tausend Terminen anderer Plugins waren das gemessen 243 statt 20 Millisekunden.
        $snapshot = Schedule::snapshot();

        $rows = [];

        foreach ($types as $type) {
            if (count($rows) >= $limit) {
                break;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_date FROM {$wpdb->posts}
                  WHERE post_type = %s AND post_status = 'future'
                  ORDER BY post_date ASC, ID ASC
                  LIMIT %d",
                (string) $type,
                $limit - count($rows)
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- vorbereitete Abfrage.
            $found = (array) $wpdb->get_results((string) $sql, ARRAY_A);

            foreach ($found as $post) {
                if (!is_array($post)) {
                    continue;
                }
                $rows[] = $this->describe($post, $snapshot);
            }
        }

        return $rows;
    }

    /**
     * Cron-Einträge unserer Hooks, deren Beitrag es nicht mehr gibt oder die eine Text-ID tragen.
     *
     * @return array<int, array{hook: string, id: string, grund: string, termin: string}>
     */
    public function strays(): array
    {
        global $wpdb;

        $candidates = [];
        $ids = [];

        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            foreach ([ScheduleRebuilder::HOOK_PUBLISH, ScheduleRebuilder::HOOK_IMPORTER] as $hook) {
                foreach ((array) ($hooks[$hook] ?? []) as $event) {
                    $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                    $raw = $args[0] ?? null;

                    if (!is_int($raw) && !is_string($raw)) {
                        continue;
                    }

                    $candidates[] = [
                        'hook' => $hook,
                        'raw' => $raw,
                        'ts' => (int) $timestamp,
                    ];
                    $ids[(int) $raw] = true;
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter aus der Anzahl gebaut.
            "SELECT ID, post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
            array_keys($ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- vorbereitete Abfrage.
        $known = [];
        foreach ((array) $wpdb->get_results((string) $sql, ARRAY_A) as $row) {
            if (is_array($row)) {
                $known[(int) $row['ID']] = (string) $row['post_status'];
            }
        }

        $out = [];

        foreach ($candidates as $candidate) {
            $id = (int) $candidate['raw'];
            $grund = null;

            if (!isset($known[$id])) {
                $grund = 'Beitrag gibt es nicht mehr';
            } elseif (!is_int($candidate['raw'])) {
                $grund = 'ID steht als Text, WordPress hält das für einen anderen Termin';
            } elseif ($candidate['hook'] === ScheduleRebuilder::HOOK_PUBLISH && $known[$id] !== 'future') {
                $grund = 'Beitrag ist nicht mehr geplant (' . $known[$id] . ')';
            }

            if ($grund === null) {
                continue;
            }

            $out[] = [
                'hook' => $candidate['hook'],
                'id' => var_export($candidate['raw'], true),
                'grund' => $grund,
                'termin' => wp_date('Y-m-d H:i', $candidate['ts']) ?: (string) $candidate['ts'],
            ];
        }

        return $out;
    }

    /**
     * Kurzfassung in Zahlen.
     *
     * @param array<int, array<string, mixed>> $posts
     * @param array<int, array<string, mixed>> $strays
     * @return array<string, int>
     */
    public function summary(array $posts, array $strays): array
    {
        $counts = [
            self::STATE_OK => 0,
            self::STATE_MISSING => 0,
            self::STATE_OVERDUE => 0,
            self::STATE_DRIFT => 0,
            self::STATE_UNREADABLE => 0,
        ];

        foreach ($posts as $post) {
            $state = (string) ($post['zustand'] ?? '');
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        $counts['verwaist'] = count($strays);

        return $counts;
    }

    /**
     * Braucht diese Website einen Eingriff?
     *
     * @param array<string, int> $summary
     */
    public function needsRepair(array $summary): bool
    {
        return ($summary[self::STATE_MISSING] ?? 0) > 0
            || ($summary[self::STATE_DRIFT] ?? 0) > 0
            || ($summary['verwaist'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, array<string, int>> $snapshot
     * @return array{id: int, typ: string, titel: string, geplant_fuer: string, termin: string, zustand: string}
     */
    private function describe(array $post, array $snapshot): array
    {
        $id = (int) ($post['ID'] ?? 0);
        $postDate = (string) ($post['post_date'] ?? '');

        $soll = Schedule::targetTimestamp($postDate);
        $ist = Schedule::lookup($snapshot, ScheduleRebuilder::HOOK_PUBLISH, Schedule::argsFor($id));

        if ($soll === null) {
            $zustand = self::STATE_UNREADABLE;
        } elseif ($ist === null) {
            // Kein Termin. Ob das reparierbar ist, hängt daran, ob der Zeitpunkt noch kommt.
            $zustand = $soll <= time() ? self::STATE_OVERDUE : self::STATE_MISSING;
        } elseif (abs($ist - $soll) > self::DRIFT_TOLERANCE) {
            $zustand = self::STATE_DRIFT;
        } else {
            $zustand = self::STATE_OK;
        }

        return [
            'id' => $id,
            'typ' => (string) ($post['post_type'] ?? ''),
            'titel' => Schedule::shortTitle((string) ($post['post_title'] ?? ''), 40),
            'geplant_fuer' => $postDate,
            'termin' => $ist === null ? '(keiner)' : (string) (wp_date('Y-m-d H:i', $ist) ?: $ist),
            'zustand' => $zustand,
        ];
    }
}

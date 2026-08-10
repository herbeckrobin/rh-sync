<?php

declare(strict_types=1);

namespace RhSync\Cli;

use RhSync\Sync\ScheduleRebuilder;

/**
 * Testdaten für die Termin-Prüfung: anlegen, kaputtmachen, wegräumen.
 *
 * Alles, was hier entsteht, trägt die Markierung {@see self::MARKER}. Aufgeräumt wird
 * ausschliesslich danach. Ein Aufräumen, das über den eigenen Kram hinausgeht, wäre auf einer
 * Website mit echten Inhalten nicht wiedergutzumachen.
 *
 * Zwei Eigenheiten, die den echten Fall nachstellen und die man von Hand leicht falsch baut:
 *
 *   - WordPress schiebt einen Beitrag mit Status `future` und einem Datum in der Vergangenheit
 *     beim Anlegen sofort auf `publish`. Um einen überfälligen geplanten Beitrag zu bekommen,
 *     muss der Status danach direkt in der Datenbank zurückgesetzt werden. Genau so sah es auf
 *     der Kundenseite aus.
 *   - `post_date_gmt` bekommt standardmässig ein falsches Datum, weil es auf importierten
 *     Beiträgen unzuverlässig ist. Wer den Termin daraus rechnet, plant alles in die
 *     Vergangenheit. Mit --valid-gmt lässt sich der gutmütige Fall herstellen.
 */
final class Fixtures
{
    /** Meta-Schlüssel, an dem die Testdaten erkennbar sind. */
    public const MARKER = '_rhsync_fixture';

    /** Das falsche Datum, wie es auf der Kundenseite in `post_date_gmt` stand. */
    private const BROKEN_GMT = '2019-03-05 08:00:00';

    /** Eine Beitrags-ID, die es sicher nicht gibt, für den verwaisten Termin. */
    private const GHOST_ID = 999999;

    /**
     * Legt geplante Beiträge an.
     *
     * @return array{angelegt: int, ueberfaellig: int, ids: array<int, int>}
     */
    public function create(int $upcoming, int $overdue, bool $validGmt = false): array
    {
        global $wpdb;

        $ids = [];
        $overdueIds = [];

        for ($i = 1; $i <= $upcoming; $i++) {
            $id = $this->insert(
                sprintf('Testbeitrag %d (geplant)', $i),
                time() + ($i * 6 * HOUR_IN_SECONDS)
            );
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        for ($i = 1; $i <= $overdue; $i++) {
            $id = $this->insert(
                sprintf('Testbeitrag %d (überfällig)', $i),
                time() - ($i * 3 * DAY_IN_SECONDS)
            );
            if ($id > 0) {
                $ids[] = $id;
                $overdueIds[] = $id;
            }
        }

        // WordPress hat die überfälligen beim Anlegen sofort veröffentlicht. Zurück auf geplant,
        // sonst lässt sich der Fall gar nicht nachstellen.
        foreach ($overdueIds as $id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- gezielter Eingriff auf eine selbst angelegte Testzeile.
            $wpdb->update($wpdb->posts, ['post_status' => 'future'], ['ID' => $id], ['%s'], ['%d']);
            clean_post_cache($id);
        }

        if (!$validGmt && $ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter aus der Anzahl gebaut, Werte gehen durch prepare.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_date_gmt = %s WHERE ID IN ({$placeholders})",
                array_merge([self::BROKEN_GMT], $ids)
            ));
            foreach ($ids as $id) {
                clean_post_cache($id);
            }
        }

        return [
            'angelegt' => count($ids),
            'ueberfaellig' => count($overdueIds),
            'ids' => $ids,
        ];
    }

    /**
     * Stellt den Zustand nach einem Import her: die Beiträge sind da, ihre Termine nicht.
     *
     * Dazu zwei kaputte Einträge, wie sie sich über die Jahre ansammeln: einer auf einen
     * Beitrag, den es nicht mehr gibt, und einer mit der ID als Text.
     *
     * @return array{entfernt: int, verwaist: int, textid: int|null}
     */
    public function damage(): array
    {
        $removed = 0;

        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            foreach ((array) ($hooks[ScheduleRebuilder::HOOK_PUBLISH] ?? []) as $event) {
                $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                wp_unschedule_event((int) $timestamp, ScheduleRebuilder::HOOK_PUBLISH, $args);
                $removed++;
            }
        }

        wp_schedule_single_event(time() + HOUR_IN_SECONDS, ScheduleRebuilder::HOOK_PUBLISH, [self::GHOST_ID]);

        // Eine echte ID, aber als Text: für WordPress ist das ein anderer Termin, und genau
        // deshalb entsteht daraus sonst eine Dublette.
        $textId = $this->firstFixtureId();
        if ($textId !== null) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, ScheduleRebuilder::HOOK_PUBLISH, [(string) $textId]);
        }

        return [
            'entfernt' => $removed,
            'verwaist' => self::GHOST_ID,
            'textid' => $textId,
        ];
    }

    /**
     * Räumt weg, was hier angelegt wurde. Nichts anderes.
     *
     * @return array{beitraege: int, termine: int}
     */
    public function reset(): array
    {
        global $wpdb;

        $ids = $this->fixtureIds();

        foreach ($ids as $id) {
            wp_clear_scheduled_hook(ScheduleRebuilder::HOOK_PUBLISH, [$id]);
            wp_clear_scheduled_hook(ScheduleRebuilder::HOOK_PUBLISH, [(string) $id]);
            wp_delete_post($id, true);
        }

        // Der verwaiste Termin zeigt auf nichts, den findet die Schleife oben nicht.
        $cleared = 0;
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            foreach ((array) ($hooks[ScheduleRebuilder::HOOK_PUBLISH] ?? []) as $event) {
                $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                if ((int) ($args[0] ?? 0) !== self::GHOST_ID) {
                    continue;
                }
                wp_unschedule_event((int) $timestamp, ScheduleRebuilder::HOOK_PUBLISH, $args);
                $cleared++;
            }
        }

        unset($wpdb);

        return [
            'beitraege' => count($ids),
            'termine' => $cleared,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function fixtureIds(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- gezielte Suche nach der eigenen Markierung.
        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::MARKER
        ));

        return array_map('intval', $ids);
    }

    private function firstFixtureId(): ?int
    {
        $ids = $this->fixtureIds();
        sort($ids);

        return $ids[0] ?? null;
    }

    private function insert(string $title, int $timestamp): int
    {
        $id = wp_insert_post([
            'post_type' => 'post',
            'post_title' => $title,
            'post_status' => 'future',
            'post_content' => 'Von "wp rh sync fixture" angelegt. Kann jederzeit gelöscht werden.',
            'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp)),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $timestamp),
            'meta_input' => [self::MARKER => 1],
        ], true);

        if (is_wp_error($id)) {
            \WP_CLI::warning(sprintf('Beitrag "%s" liess sich nicht anlegen: %s', $title, $id->get_error_message()));

            return 0;
        }

        return (int) $id;
    }
}

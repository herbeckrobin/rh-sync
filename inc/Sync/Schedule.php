<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Die geteilten Rechnungen rund um Termine.
 *
 * Zwei Stellen brauchen dieselben Antworten: der {@see ScheduleRebuilder}, der Termine setzt,
 * und die Diagnose, die nachsieht ob sie stimmen. Würden beide eigene Kopien halten, könnten
 * sie auseinanderlaufen, und dann meldet die Diagnose "alles in Ordnung" für etwas, das der
 * Wiederaufbau gerade anders gerechnet hat.
 */
final class Schedule
{
    /** Die Hooks, deren Termine an Inhalten hängen und die wir wiederherstellen. */
    public const HOOKS = [
        ScheduleRebuilder::HOOK_PUBLISH,
        ScheduleRebuilder::HOOK_PINGS,
        ScheduleRebuilder::HOOK_IMPORTER,
    ];

    /**
     * Wann soll dieser Beitrag erscheinen, in GMT?
     *
     * Gerechnet aus `post_date` über `get_gmt_from_date()`, genau wie WordPress es in
     * `_future_post_hook()` selbst tut. NICHT aus `post_date_gmt`: das Feld ist auf
     * importierten Beiträgen unzuverlässig, dort stand auf einer echten Kundenseite das
     * Erstellungs- statt des Veröffentlichungsdatums. Wer daraus rechnet, plant alles in die
     * Vergangenheit, und der nächste Cron-Lauf veröffentlicht auf einen Schlag auch das, was
     * für nächstes Jahr gedacht war.
     *
     * Gibt `null` zurück, wenn sich aus dem Datum kein Zeitpunkt gewinnen lässt.
     */
    public static function targetTimestamp(string $postDate): ?int
    {
        if ($postDate === '' || str_starts_with($postDate, '0000-00-00')) {
            return null;
        }

        $gmt = get_gmt_from_date($postDate);
        if (!is_string($gmt) || $gmt === '') {
            return null;
        }

        $timestamp = strtotime($gmt . ' GMT');

        return ($timestamp === false || $timestamp <= 0) ? null : $timestamp;
    }

    /**
     * Der Schlüssel, unter dem WordPress einen Termin führt.
     *
     * Entscheidend ist, dass die Beitrags-ID als Zahl hineingeht: `serialize(['42'])` ist nicht
     * `serialize([42])`, und ein Eintrag mit der ID als Text ist damit für WordPress ein ganz
     * anderer Termin. Wer das übersieht, findet vorhandene Termine nicht und legt Dubletten an.
     *
     * @param array<int, mixed> $args
     */
    public static function key(array $args): string
    {
        return md5(serialize($args));
    }

    /**
     * @return array<int, int> Argument-Array für einen Beitrag.
     */
    public static function argsFor(int $postId): array
    {
        return [$postId];
    }

    /**
     * Eine Momentaufnahme aller eigenen Termine: [hook][schlüssel] => Zeitpunkt.
     *
     * Für das Nachsehen gedacht. `wp_next_scheduled()` läuft bei jedem Aufruf das komplette
     * Cron-Array durch. Bei zweihundert Beiträgen auf einer Website, die von anderen Plugins
     * ein paar tausend Termine mitbringt, sind das gemessene 243 statt 20 Millisekunden. Einmal
     * lesen und nachschlagen kostet dasselbe wie ein einziger dieser Aufrufe.
     *
     * NICHT geeignet für den Wiederaufbau: der setzt und entfernt Termine, dabei würde die
     * Momentaufnahme veralten. Dort bleibt `wp_next_scheduled()` die verlässliche Auskunft.
     *
     * @return array<string, array<string, int>>
     */
    public static function snapshot(): array
    {
        $index = [];

        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            foreach (self::HOOKS as $hook) {
                foreach ((array) ($hooks[$hook] ?? []) as $key => $event) {
                    $key = (string) $key;
                    // Der früheste Zeitpunkt gewinnt, wie bei wp_next_scheduled().
                    if (!isset($index[$hook][$key]) || $timestamp < $index[$hook][$key]) {
                        $index[$hook][$key] = (int) $timestamp;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Der Zeitpunkt aus einer Momentaufnahme, oder `null` wenn es keinen Termin gibt.
     *
     * @param array<string, array<string, int>> $snapshot
     * @param array<int, mixed> $args
     */
    public static function lookup(array $snapshot, string $hook, array $args): ?int
    {
        return $snapshot[$hook][self::key($args)] ?? null;
    }

    /**
     * Ein Beitragstitel, gekürzt und ohne Auszeichnung, für Listen und Meldungen.
     */
    public static function shortTitle(string $title, int $length = 80): string
    {
        $title = wp_strip_all_tags($title);

        if ($title === '') {
            return __('(ohne Titel)', 'rh-sync');
        }

        return function_exists('wp_html_excerpt')
            ? (string) wp_html_excerpt($title, $length, '...')
            : $title;
    }
}

<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Sucht nach Tabellen, die die Quelle benutzt und die es hier nicht gibt.
 *
 * Der Sync lässt bewusst Tabellen aus, die zu einer Instanz gehören und nicht zu ihren
 * Inhalten (Warteschlangen, Sitzungen, siehe {@see SyncDefaults::excludedTables()}). Das
 * ist richtig, hat aber eine Kante: hatte die Zielseite so eine Tabelle noch nie, bringt
 * der Sync sie auch nicht mit. Sie fehlt danach schlicht.
 *
 * Genau das ist am 2026-08-10 passiert. Die vier Tabellen des Action Scheduler fehlten
 * nach dem Import, und WordPress meldete bei jedem Aufruf einen Datenbankfehler.
 * WooCommerce merkt das nicht: es führt diese Tabellen nicht in seiner eigenen
 * Datenbank-Version, `wp wc update` sagt "No updates required" und legt nichts an.
 *
 * Verglichen wird gegen die Liste im Manifest des Archivs, nicht gegen die eigene
 * Ausschlussliste. Sonst meldete jede Website ohne WooCommerce dieselben vier Tabellen
 * als fehlend, obwohl sie dort niemand erwartet. Gefragt ist nur: was benutzt die Quelle,
 * das es hier nicht gibt?
 *
 * Angelegt wird hier nichts. Eine Tabelle gehört dem Plugin, das sie braucht, und das
 * legt sie beim Aktivieren selbst an. Gemeldet wird sie aber, sonst sucht man den Fehler
 * an der falschen Stelle.
 */
final class MissingTables
{
    /**
     * Welche der auf der Quelle benutzten Tabellen fehlen hier?
     *
     * @param array<int, string> $aufDerQuelle Aus dem Manifest: `excluded_tables_present`.
     * @return array<int, string>
     */
    public function detect(array $aufDerQuelle): array
    {
        global $wpdb;

        $fehlend = [];
        foreach ($aufDerQuelle as $tabelle) {
            $tabelle = (string) $tabelle;
            if ($tabelle === '') {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES, einmalig nach einem Import.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) === null) {
                $fehlend[] = $tabelle;
            }
        }

        return $fehlend;
    }

    /**
     * Der Hinweis für den Abschlussbericht, oder null wenn nichts fehlt.
     *
     * @param array<int, string> $fehlend
     * @return array{title: string, tone: string, stats: array<int, array{label: string, value: string}>, items: array<int, string>}|null
     */
    public function note(array $fehlend): ?array
    {
        if ($fehlend === []) {
            return null;
        }

        return [
            'title' => __('Fehlende Tabellen', 'rh-sync'),
            'tone' => 'warn',
            'stats' => [
                ['label' => __('Fehlen', 'rh-sync'), 'value' => (string) count($fehlend)],
            ],
            'items' => array_merge(
                $fehlend,
                [
                    __('Diese Tabellen gehören zu Warteschlangen und Sitzungen. Sie werden bewusst nicht mitgesynct, und auf dieser Website gab es sie noch nie.', 'rh-sync'),
                    __('Sie entstehen, wenn das zuständige Plugin sie anlegt: einmal deaktivieren und wieder aktivieren reicht in der Regel. Solange sie fehlen, meldet WordPress bei jedem Aufruf einen Datenbankfehler.', 'rh-sync'),
                ]
            ),
        ];
    }
}

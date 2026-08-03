<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Schuetzt beim Sync-Import nur das, was den Sync selbst oder die Ziel-Site
 * zerstoeren wuerde. ALLES andere (inkl. aller rhbp-Modul-Settings) wird gesynct.
 *
 * Grundsatz (bewusst eng, nicht breit): Default ist "synct mit". Geschuetzt wird
 * eine kleine, EXPLIZITE Liste, kein Catch-all-Muster. Ein breites `rhbp\_%` hat
 * frueher alle Modul-Settings (rh-seo Stammdaten, Hardening-Schalter, ...) wieder
 * revertiert, obwohl genau die gesynct werden sollten. Lieber zu wenig schuetzen
 * (eine Site-Identitaets-Option vergessen faellt sofort auf) als zu viel (stilles
 * Verschlucken gewollter Daten, schwer zu finden).
 *
 * Geschuetzt (bleibt ziel-lokal):
 *   - Sync-Engine-Status: `rhbp_peers` (eigene Peer-Liste), `rhbp_sync_*`
 *     (Log, Jobs, Locks) + die zugehoerigen Transients. Wuerde der Import die
 *     ueberschreiben, clobbert er die laufende Sync-Operation und die Kopplung.
 *   - WP-Core Site-Identitaet (siteurl/home/active_plugins/cron/...), die die
 *     Ziel-Site brechen wuerde wenn sie auf den Quellzustand gesetzt wird.
 *
 * Erweiterbar via Filter, falls eine Site doch mehr schuetzen will (z.B. SMTP-
 * Credentials die pro Umgebung verschluesselt sind):
 *   - `rh-blueprint/sync/preserved_option_patterns`, SQL-LIKE-Patterns
 *   - `rh-blueprint/sync/preserved_option_names`   , exakte option_names
 */
final class LocalOptionGuard
{
    /** @var array<int, string> */
    private const DEFAULT_PATTERNS = [
        // Sync-Engine-Status der ZIEL-Site (Log, Jobs-Index, Job-States, Locks)
        'rhbp\\_sync\\_%',
        // Sync-Transients (Download-Cache, Import-Sessions)
        '\\_transient\\_rhbp\\_sync\\_%',
        '\\_transient\\_timeout\\_rhbp\\_sync\\_%',
        '\\_site\\_transient\\_rhbp\\_sync\\_%',
        '\\_site\\_transient\\_timeout\\_rhbp\\_sync\\_%',
        // WP-Core Update-Check Transients (pro Site zeitkritisch)
        '\\_site\\_transient\\_update\\_%',
        '\\_site\\_transient\\_timeout\\_update\\_%',
    ];

    /** @var array<int, string> */
    private const DEFAULT_NAMES = [
        // Sync-Kopplung: die eigene Peer-Liste der Ziel-Site, NICHT die der Quelle
        'rhbp_peers',
        // WP-Core Site-Identitaet, wuerde die Ziel-Site brechen wenn ueberschrieben
        'siteurl',
        'home',
        'admin_email',
        'new_admin_email',
        'active_plugins',
        'active_sitewide_plugins',
        'cron',
        'rewrite_rules',
        'upload_path',
        'upload_url_path',
        'db_version',
        'db_upgraded',
        'fresh_site',
    ];

    /**
     * Options, deren Name den Tabellen-Prefix der Site trägt.
     *
     * `{prefix}_user_roles` hält die Rollendefinitionen. Fehlt sie, hat kein einziger
     * Benutzer mehr Rechte und das Backend antwortet nur noch mit "Du bist leider nicht
     * berechtigt". Genau das war am 2026-08-02 der Fall: der Import kam nicht bis zur
     * Umbenennung der Schlüssel, und in der Options-Tabelle stand danach der Name mit dem
     * Prefix der Quelle.
     *
     * Bewusst NICHT in dieser Liste: `template` und `stylesheet`. Das aktive Theme SOLL
     * mitwandern, sonst bringt ein Sync die Gestaltung nicht mit.
     *
     * @var array<int, string>
     */
    private const PREFIXED_NAMES = [
        'user_roles',
    ];

    /**
     * @return array<int, array{option_name: string, option_value: string, autoload: string}>
     */
    public function snapshot(): array
    {
        global $wpdb;

        $where = $this->buildWhereClause();
        /** @var array<int, array<string, string>>|null $rows */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direkte Query auf interne Options-Tabelle, Caching bei einmaliger Sync-Operation nicht sinnvoll.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE aus festen Konstanten gebaut (kein User-Input), $wpdb->options ist interner Tabellenname.
            "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE {$where}",
            ARRAY_A
        );

        $snapshot = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !isset($row['option_name'], $row['option_value'])) {
                continue;
            }
            $snapshot[] = [
                'option_name' => (string) $row['option_name'],
                'option_value' => (string) $row['option_value'],
                'autoload' => (string) ($row['autoload'] ?? 'no'),
            ];
        }

        return $snapshot;
    }

    /**
     * Spielt den Snapshot in die LIVE-Options-Tabelle zurück.
     *
     * Der Weg des direkten Imports: die Tabelle wurde bereits mit dem Stand der Quelle
     * überschrieben, und die site-eigenen Werte kommen hinterher wieder rein. Zwischen
     * beiden Schritten ist die Site falsch verdrahtet. Deshalb ist im Umschalt-Modus
     * {@see applyTo()} der bessere Weg.
     *
     * @param array<int, array{option_name: string, option_value: string, autoload: string}> $snapshot
     */
    public function restore(array $snapshot): void
    {
        global $wpdb;

        $this->writeInto((string) $wpdb->options, $snapshot);

        wp_cache_flush();
    }

    /**
     * Schreibt den Snapshot in eine noch nicht live geschaltete Options-Tabelle.
     *
     * Das ist der eigentliche Fortschritt gegenüber {@see restore()}: die site-eigenen Werte
     * stehen schon in der Schattentabelle, BEVOR sie live geht. Damit gibt es kein Fenster
     * mehr, in dem die Zielseite mit der Adresse, den Plugins oder den Rollen der Quelle
     * dasteht. Nach dem Umschalten ist nichts mehr zu reparieren.
     *
     * @param array<int, array{option_name: string, option_value: string, autoload: string}> $snapshot
     */
    public function applyTo(string $optionsTable, array $snapshot): void
    {
        $this->writeInto($optionsTable, $snapshot);
    }

    /**
     * @param array<int, array{option_name: string, option_value: string, autoload: string}> $snapshot
     */
    private function writeInto(string $optionsTable, array $snapshot): void
    {
        global $wpdb;

        $table = '`' . str_replace('`', '``', $optionsTable) . '`';
        $where = $this->buildWhereClause();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- direkte Query auf die Options-Tabelle, WHERE aus festen Konstanten (kein User-Input).
        $wpdb->query("DELETE FROM {$table} WHERE {$where}");

        foreach ($snapshot as $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert mit Format-Platzhaltern.
            $wpdb->insert(
                $optionsTable,
                [
                    'option_name' => $row['option_name'],
                    'option_value' => $row['option_value'],
                    'autoload' => $row['autoload'],
                ],
                ['%s', '%s', '%s']
            );
        }
    }

    /**
     * Die geschützten Namen inklusive der prefix-behafteten.
     *
     * @return array<int, string>
     */
    public function protectedNames(): array
    {
        global $wpdb;

        $names = self::DEFAULT_NAMES;
        foreach (self::PREFIXED_NAMES as $suffix) {
            $names[] = (string) $wpdb->prefix . $suffix;
        }

        return $names;
    }

    private function buildWhereClause(): string
    {
        /** @var array<int, string> $patterns */
        $patterns = (array) apply_filters(
            'rh-blueprint/sync/preserved_option_patterns',
            self::DEFAULT_PATTERNS
        );

        /** @var array<int, string> $names */
        $names = (array) apply_filters(
            'rh-blueprint/sync/preserved_option_names',
            $this->protectedNames()
        );

        $parts = [];

        foreach ($patterns as $pattern) {
            $escaped = str_replace("'", "\\'", (string) $pattern);
            $parts[] = "option_name LIKE '{$escaped}'";
        }

        if ($names !== []) {
            $quoted = array_map(
                static fn (string $n): string => "'" . str_replace("'", "\\'", $n) . "'",
                array_map('strval', $names)
            );
            $parts[] = 'option_name IN (' . implode(', ', $quoted) . ')';
        }

        return $parts === [] ? '1=0' : implode(' OR ', $parts);
    }
}

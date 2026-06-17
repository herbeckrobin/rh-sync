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
     * @param array<int, array{option_name: string, option_value: string, autoload: string}> $snapshot
     */
    public function restore(array $snapshot): void
    {
        global $wpdb;

        $where = $this->buildWhereClause();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- direkte Query auf interne Options-Tabelle, WHERE aus festen Konstanten (kein User-Input), Caching bei einmaliger Sync-Operation nicht sinnvoll.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE {$where}");

        foreach ($snapshot as $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert auf interne Options-Tabelle mit Format-Platzhaltern, Caching bei einmaliger Sync-Operation nicht sinnvoll.
            $wpdb->insert(
                $wpdb->options,
                [
                    'option_name' => $row['option_name'],
                    'option_value' => $row['option_value'],
                    'autoload' => $row['autoload'],
                ],
                ['%s', '%s', '%s']
            );
        }

        wp_cache_flush();
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
            self::DEFAULT_NAMES
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

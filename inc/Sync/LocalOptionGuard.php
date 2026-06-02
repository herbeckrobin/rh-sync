<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Schuetzt site-spezifische Options beim Sync-Import.
 *
 * Problem: Der Exporter dumpt die komplette `wp_options` Table. Dort sind auch
 * Options drin, die pro Site unterschiedlich sein müssen und vom Sync nicht
 * überschrieben werden dürfen, sonst bricht der Ziel-Zustand (Peer-Liste
 * weg, Plugin-Aktivierung falsch, Login-URL ändert sich).
 *
 * Default geschuetzt:
 *   - `rhbp_*`, Alle Plugin-eigenen Options (Peers, Settings, Sync-Log)
 *   - `siteurl`, `home`, Site-Identitaet (Safety-Net falls Search-Replace fehlschlaegt)
 *   - `active_plugins`, `active_sitewide_plugins`, Plugin-Aktivierung
 *   - `cron`, Cron-Queue
 *   - `rewrite_rules`, Rewrite-Cache
 *   - `whl_page`, WPS Hide Login Slug (sonst Login-URL weg)
 *
 * Erweiterbar via Filter:
 *   - `rh-blueprint/sync/preserved_option_patterns`, SQL-LIKE-Patterns
 *   - `rh-blueprint/sync/preserved_option_names`   , exakte option_names
 */
final class LocalOptionGuard
{
    /** @var array<int, string> */
    private const DEFAULT_PATTERNS = [
        // Plugin-eigene Options + deren Transients
        'rhbp\\_%',
        '\\_transient\\_rhbp\\_%',
        '\\_site\\_transient\\_rhbp\\_%',
        '\\_transient\\_timeout\\_rhbp\\_%',
        '\\_site\\_transient\\_timeout\\_rhbp\\_%',
        // WP-Core Update-Check Transients (pro Site zeitkritisch)
        '\\_site\\_transient\\_update\\_%',
        '\\_site\\_transient\\_timeout\\_update\\_%',
        // Limit Login Attempts Reloaded, Lockouts und Blacklist sind site-lokal
        'limit\\_login\\_%',
        // WP Mail SMTP, Credentials, Auth-Tokens, Lizenz
        'wp\\_mail\\_smtp%',
    ];

    /** @var array<int, string> */
    private const DEFAULT_NAMES = [
        // WP Core, Site-Identitaet
        'siteurl',
        'home',
        'admin_email',
        'new_admin_email',
        // Plugin-Aktivierung (Fatal-Error-Schutz)
        'active_plugins',
        'active_sitewide_plugins',
        // Cron + Rewrite pro Site
        'cron',
        'rewrite_rules',
        // Hosting-Pfade für Uploads
        'upload_path',
        'upload_url_path',
        // WP-Core SMTP-Fallback
        'mailserver_url',
        'mailserver_login',
        'mailserver_pass',
        'mailserver_port',
        // DB-Schema-State
        'db_version',
        'db_upgraded',
        'fresh_site',
        // WPS Hide Login, Login-URL-Slug
        'whl_page',
    ];

    /**
     * @return array<int, array{option_name: string, option_value: string, autoload: string}>
     */
    public function snapshot(): array
    {
        global $wpdb;

        $where = $this->buildWhereClause();
        /** @var array<int, array<string, string>>|null $rows */
        $rows = $wpdb->get_results(
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
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE {$where}");

        foreach ($snapshot as $row) {
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

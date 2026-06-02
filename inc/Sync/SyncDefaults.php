<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Gemeinsame Defaults für Sync-Operationen, insbesondere welche Tabellen
 * beim Export/Import ausgeschlossen werden sollen.
 *
 * Im Gegensatz zu einem Full-Backup (via DbToolsPage) wollen wir beim Sync
 * zwischen Peers bestimmte pro-Site-Tabellen NICHT mitschicken:
 *
 *   - Action-Scheduler-Queues (wp_actionscheduler_*), Job-Queues sind pro Site
 *   - WooCommerce-Sessions (wp_woocommerce_sessions), Customer-Sessions
 *
 * Erweiterbar via Filter `rh-blueprint/sync/excluded_tables`.
 */
final class SyncDefaults
{
    /**
     * @return array<int, string>
     */
    public static function excludedTables(): array
    {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $defaults = [
            $prefix . 'actionscheduler_actions',
            $prefix . 'actionscheduler_claims',
            $prefix . 'actionscheduler_groups',
            $prefix . 'actionscheduler_logs',
            $prefix . 'woocommerce_sessions',
        ];

        /** @var array<int, string> $filtered */
        $filtered = (array) apply_filters('rh-blueprint/sync/excluded_tables', $defaults);

        return array_values(array_unique(array_map('strval', $filtered)));
    }
}

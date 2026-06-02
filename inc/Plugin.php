<?php

declare(strict_types=1);

namespace RhSync;

use RhBackup\Api;
use RhBlueprint\Core\Core;
use RhBlueprint\Core\Settings\SettingsPage;
use RhSync\Admin\SyncPeersPage;
use RhSync\Sync\HmacAuth;
use RhSync\Sync\PeerRegistry;
use RhSync\Sync\PullOperation;
use RhSync\Sync\PushOperation;
use RhSync\Sync\SyncClient;
use RhSync\Sync\SyncController;
use RhSync\Sync\SyncLog;

/**
 * Bootstrap von rh-sync.
 *
 * Hängt am Core-Hook `rh-blueprint/core/booted` und zieht die Backup-API aus der
 * Service-Registry (für Export/Import) sowie die Core-Storage. Fehlt rh-backup,
 * deaktiviert sich rh-sync graceful (sollte durch `Requires Plugins: rh-backup`
 * ohnehin nicht vorkommen).
 */
final class Plugin
{
    public static function boot(): void
    {
        (new UpdateChecker())->boot();

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        $backup = $core->services()->get('backup', 1);

        if (! $backup instanceof Api) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p><strong>RH Sync:</strong> Das Plugin rh-backup wird benötigt, ist aber nicht aktiv.</p></div>';
            });
            return;
        }

        $storage = $core->storage();

        $peerRegistry = new PeerRegistry();
        $hmacAuth = new HmacAuth($peerRegistry);
        $syncClient = new SyncClient($hmacAuth);
        $syncLog = new SyncLog();
        $pullOperation = new PullOperation($syncClient, $backup, $storage, $syncLog);
        $pushOperation = new PushOperation($syncClient, $backup, $syncLog);

        $syncPeersPage = new SyncPeersPage($peerRegistry, $pullOperation, $pushOperation, $syncLog, $syncClient);
        $syncController = new SyncController($hmacAuth, $backup, $storage, $peerRegistry);

        $core->settings()->registerTab('sync_network', __('Sync Network', 'rh-sync'), 30);
        $syncPeersPage->boot();
        $syncController->boot();

        // Entkopplung: rh-sync steuert seinen Dashboard-Quick-Link selbst bei.
        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Sync Network', 'rh-sync'),
                'url' => admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG . '&tab=sync_network'),
                'icon' => 'update',
            ];
            return $links;
        });
    }
}

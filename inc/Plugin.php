<?php

declare(strict_types=1);

namespace RhSync;

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
 * Hängt am Core-Hook `rh-blueprint/core/booted` und nutzt die geteilte db-engine
 * (`rh_db_engine()`) für Export/Import/Storage. rh-sync ist damit unabhängig von
 * rh-backup, beide ziehen nur Core + db-engine. Fehlt die db-engine, deaktiviert
 * sich rh-sync graceful.
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
        if (! function_exists('rh_db_engine')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p><strong>RH Sync:</strong> Die DB-Engine fehlt. Bitte das Plugin neu installieren (Composer-Dependencies).</p></div>';
            });
            return;
        }

        $engine = rh_db_engine();
        $exporter = $engine->exporter();
        $importer = $engine->importer();
        $storage = $engine->storage();

        $peerRegistry = new PeerRegistry();
        $hmacAuth = new HmacAuth($peerRegistry);
        $syncClient = new SyncClient($hmacAuth);
        $syncLog = new SyncLog();
        $pullOperation = new PullOperation($syncClient, $exporter, $importer, $storage, $syncLog);
        $pushOperation = new PushOperation($syncClient, $exporter, $syncLog);

        $syncPeersPage = new SyncPeersPage($peerRegistry, $pullOperation, $pushOperation, $syncLog, $syncClient);
        $syncController = new SyncController($hmacAuth, $exporter, $importer, $storage, $peerRegistry);

        $core->settings()->registerTab('sync', __('Sync', 'rh-sync'), 30);
        $syncPeersPage->boot();
        $syncController->boot();

        // Entkopplung: rh-sync steuert seinen Dashboard-Quick-Link selbst bei.
        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Sync', 'rh-sync'),
                'url' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=sync'),
                'icon' => 'update',
            ];
            return $links;
        });
    }
}

<?php

declare(strict_types=1);

namespace RhSync\Admin;

use RhBlueprint\Core\Settings\SettingsPage;

/**
 * Admin-weiter Fortschritts-Indikator für laufende Syncs.
 *
 * Da der Sync im Hintergrund tickt und die Session überlebt, kann der Nutzer wegnavigieren.
 * Damit er den Sync nicht aus den Augen verliert, blendet dieser Indikator auf JEDER
 * wp-admin-Seite einen Admin-Bar-Eintrag ein, sobald ein Job läuft (Polling über den
 * vorhandenen `rhbp_sync_active_job`-Endpoint). Klick führt zurück zum Sync-Tab.
 *
 * Komplett eigenständig und additiv: kein Eingriff in das Sync-Modal.
 */
final class SyncProgressIndicator
{
    public function boot(): void
    {
        add_action('admin_bar_menu', [$this, 'addNode'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addNode(\WP_Admin_Bar $bar): void
    {
        if (!current_user_can(SyncPeersPage::CAPABILITY)) {
            return;
        }

        $bar->add_node([
            'id' => 'rhbp-sync-indicator',
            'title' => '<span class="ab-icon dashicons dashicons-update" style="margin-top:2px;"></span>'
                . '<span class="rhbp-sync-ind-label">Sync</span>',
            'href' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=sync'),
            'meta' => ['class' => 'rhbp-sync-indicator-node'],
        ]);
    }

    public function enqueue(): void
    {
        if (!current_user_can(SyncPeersPage::CAPABILITY)) {
            return;
        }

        // Eigenständiges, abhängigkeitsfreies Inline-Script (kein Asset-File nötig).
        wp_register_script('rhbp-sync-indicator', false, [], '1.0.0', true);
        wp_enqueue_script('rhbp-sync-indicator');

        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(SyncPeersPage::NONCE_AJAX),
            'i18n' => [
                'pull' => __('Pull läuft', 'rh-sync'),
                'push' => __('Push läuft', 'rh-sync'),
                'import' => __('Import läuft', 'rh-sync'),
                'stalled' => __('Sync hängt', 'rh-sync'),
            ],
        ];

        $js = <<<'JS'
(function () {
    var cfg = window.__rhbpSyncInd || {};
    var node = document.getElementById('wp-admin-bar-rhbp-sync-indicator');
    if (!node) { return; }
    // Per Default versteckt, bis ein laufender Job gefunden wird.
    node.style.display = 'none';
    var labelEl = node.querySelector('.rhbp-sync-ind-label');
    var iconEl = node.querySelector('.ab-icon');

    function label(status, direction) {
        if (status && status.stale) { return cfg.i18n.stalled; }
        var base = cfg.i18n[direction] || cfg.i18n.pull;
        var pct = '';
        if (status && status.bytes_total > 0) {
            pct = ' ' + Math.min(100, Math.round((status.bytes_now / status.bytes_total) * 100)) + '%';
        }
        return base + pct;
    }

    function poll() {
        var url = cfg.ajaxUrl + '?action=rhbp_sync_active_job&nonce=' + encodeURIComponent(cfg.nonce);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var d = res && res.data ? res.data : {};
                if (d.active) {
                    var st = d.status || {};
                    node.style.display = '';
                    if (labelEl) { labelEl.textContent = label(st, st.direction); }
                    if (iconEl) { iconEl.style.color = st.stale ? '#dba617' : ''; }
                    setTimeout(poll, 2000);
                } else {
                    node.style.display = 'none';
                    setTimeout(poll, 8000);
                }
            })
            .catch(function () { setTimeout(poll, 8000); });
    }
    poll();
})();
JS;

        wp_add_inline_script('rhbp-sync-indicator', 'window.__rhbpSyncInd = ' . wp_json_encode($config) . ';', 'before');
        wp_add_inline_script('rhbp-sync-indicator', $js);
    }
}

<?php

declare(strict_types=1);

namespace RhSync\Admin;

use RhBlueprint\Core\Settings\SettingsPage;
use RhSync\Sync\Peer;
use RhSync\Sync\PeerRegistry;
use RhSync\Sync\PeerUrl;
use RhSync\Sync\PullOperation;
use RhSync\Sync\PushOperation;
use RhSync\Sync\SyncClient;
use RhSync\Sync\SyncLog;
use RhSync\Sync\SyncPermissions;
use RhSync\Sync\SyncProfile;
use RhSync\Sync\SyncStatus;

final class SyncPeersPage
{
    public const TAB_ID = 'sync';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ADD = 'rhbp_peer_add';
    public const NONCE_REMOVE = 'rhbp_peer_remove';
    public const NONCE_REGEN = 'rhbp_peer_regenerate';
    public const NONCE_PULL = 'rhbp_peer_pull';
    public const NONCE_PUSH = 'rhbp_peer_push';
    public const NONCE_PROFILE = 'rhbp_peer_profile';
    public const NONCE_PERMISSIONS = 'rhbp_peer_permissions';
    public const NONCE_AJAX = 'rhbp_sync_ajax';
    public const NEW_TOKEN_TRANSIENT_PREFIX = 'rhbp_peer_new_token_';
    public const PULL_RESULT_TRANSIENT_PREFIX = 'rhbp_pull_result_';
    public const PUSH_RESULT_TRANSIENT_PREFIX = 'rhbp_push_result_';

    public function __construct(
        private readonly PeerRegistry $registry,
        private readonly PullOperation $pullOperation,
        private readonly PushOperation $pushOperation,
        private readonly SyncLog $log,
        private readonly SyncClient $client,
    ) {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderInlineMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'renderPeers']);
        add_action('admin_post_rhbp_peer_add', [$this, 'handleAdd']);
        add_action('admin_post_rhbp_peer_remove', [$this, 'handleRemove']);
        add_action('admin_post_rhbp_peer_regenerate', [$this, 'handleRegenerate']);
        add_action('admin_post_rhbp_peer_pull', [$this, 'handlePull']);
        add_action('admin_post_rhbp_peer_push', [$this, 'handlePush']);
        add_action('admin_post_rhbp_peer_update_profile', [$this, 'handleUpdateProfile']);
        add_action('admin_post_rhbp_peer_update_permissions', [$this, 'handleUpdatePermissions']);

        // AJAX-Handler für Premium-UX (Pre-Flight + Live-Progress)
        add_action('wp_ajax_rhbp_peer_preflight', [$this, 'ajaxPreflight']);
        add_action('wp_ajax_rhbp_peer_pull_async', [$this, 'ajaxPullAsync']);
        add_action('wp_ajax_rhbp_peer_push_async', [$this, 'ajaxPushAsync']);
        add_action('wp_ajax_rhbp_sync_status', [$this, 'ajaxSyncStatus']);
        add_action('wp_ajax_rhbp_sync_clear', [$this, 'ajaxSyncClear']);
    }

    public function renderInlineMessage(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        $message = isset($_GET['rhbp_message']) ? sanitize_key((string) $_GET['rhbp_message']) : '';
        if ($message === '') {
            return;
        }

        $map = [
            'peer_added' => ['success', __('Peer wurde hinzugefügt.', 'rh-sync')],
            'peer_removed' => ['success', __('Peer wurde entfernt.', 'rh-sync')],
            'peer_regenerated' => ['success', __('Token wurde neu generiert.', 'rh-sync')],
            'peer_profile_saved' => ['success', __('Sync-Profil wurde gespeichert.', 'rh-sync')],
            'peer_permissions_saved' => ['success', __('Berechtigungen wurden gespeichert.', 'rh-sync')],
            'pull_forbidden' => ['error', __('Pull von diesem Peer ist lokal deaktiviert.', 'rh-sync')],
            'push_forbidden' => ['error', __('Push zu diesem Peer ist lokal deaktiviert.', 'rh-sync')],
            'peer_missing_fields' => ['warning', __('Name und URL sind Pflicht.', 'rh-sync')],
            'peer_invalid_url' => ['error', __('Die URL ist nicht gültig.', 'rh-sync')],
            'peer_insecure_url' => ['error', __('Peer-URLs müssen HTTPS verwenden. Über HTTP würden Sync-Daten im Klartext übertragen.', 'rh-sync')],
            'peer_blocked_host' => ['error', __('Diese URL zeigt auf eine interne oder lokale Adresse und ist als Sync-Ziel nicht erlaubt.', 'rh-sync')],
            'peer_name_exists' => ['error', __('Ein Peer mit diesem Namen existiert bereits.', 'rh-sync')],
            'peer_not_found' => ['error', __('Peer nicht gefunden.', 'rh-sync')],
            'peer_invalid_pairing' => ['error', __('Pairing-Code ungültig oder beschädigt.', 'rh-sync')],
            'profile_empty' => ['warning', __('Mindestens ein Bereich muss ausgewählt sein.', 'rh-sync')],
            'pull_success' => ['success', __('Pull erfolgreich abgeschlossen.', 'rh-sync')],
            'pull_failed' => ['error', __('Pull fehlgeschlagen.', 'rh-sync')],
            'push_success' => ['success', __('Push erfolgreich abgeschlossen.', 'rh-sync')],
            'push_failed' => ['error', __('Push fehlgeschlagen.', 'rh-sync')],
        ];

        if (!isset($map[$message])) {
            return;
        }

        [$type, $text] = $map[$message];
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }

    public function renderPeers(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        $peers = $this->registry->all();

        echo '<div class="rhbp-sync" data-rhbp-sync data-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '" data-ajax-nonce="' . esc_attr(wp_create_nonce(self::NONCE_AJAX)) . '">';

        // Kopf: Titel + (wenn Peers da) die zwei Aktionen. Im Leerzustand sind die
        // zwei Wege die grossen Kacheln im Empty-State.
        echo '<div class="rhbp-sync__head">';
        echo '<div class="rhbp-sync__head-text">';
        echo '<h2 class="rhbp-sync__heading">' . esc_html__('Sync', 'rh-sync') . '</h2>';
        if ($peers === []) {
            echo '<p class="rhbp-sync__intro">' . esc_html__('Eine Verbindung koppelt diese Site mit einer anderen WordPress-Instanz, die rh-sync aktiv hat. Danach kannst du Datenbank und Mediathek in beide Richtungen abgleichen.', 'rh-sync') . '</p>';
        } else {
            printf(
                '<p class="rhbp-sync__intro">%s</p>',
                esc_html(sprintf(
                    /* translators: %d: number of connections */
                    _n('%d Verbindung.', '%d Verbindungen.', count($peers), 'rh-sync'),
                    count($peers)
                ))
            );
        }
        echo '</div>';
        if ($peers !== []) {
            echo '<div class="rhbp-sync__head-actions">';
            echo '<button type="button" class="rhbp-btn" data-rhbp-modal-open="rhbp-modal-join">' . $this->icon('inbox', 'sm') . ' ' . esc_html__('Code eingeben', 'rh-sync') . '</button>';
            echo '<button type="button" class="rhbp-btn rhbp-btn--primary" data-rhbp-modal-open="rhbp-modal-create">' . $this->icon('plus', 'sm') . ' ' . esc_html__('Verbindung erzeugen', 'rh-sync') . '</button>';
            echo '</div>';
        }
        echo '</div>';

        $this->renderPullResultNotice();
        $this->renderPushResultNotice();
        $this->renderPeerList($peers);
        $this->renderHistory();

        // Modals (versteckt, per JS geoeffnet)
        $this->renderCreateModal();
        $this->renderJoinModal();
        $this->renderCodeModal();
        $this->renderSyncModalTemplate();

        echo '</div>';
    }

    /**
     * Inline-SVG-Icons (feine Linien, wie im abgenommenen Prototyp). Plugin-lokaler
     * Helfer, haelt das Markup schlank.
     */
    private function icon(string $name, string $size = ''): string
    {
        $paths = [
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'inbox' => '<path d="M4 7v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7M4 7l8 6 8-6"/>',
            'site' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            'external' => '<path d="M14 4h6v6M20 4l-9 9M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>',
            'pull' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
            'push' => '<path d="M12 21V9M7 14l5-5 5 5M5 3h14"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
            'refresh' => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
            'trash' => '<path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/>',
            'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'lock' => '<rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
            'warning' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>',
        ];

        $path = $paths[$name] ?? '';
        $cls = 'rhbp-ico' . ($size === 'sm' ? ' rhbp-ico--sm' : '');

        return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }

    public function handleAdd(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ADD);

        $name = isset($_POST['peer_name']) ? sanitize_text_field((string) $_POST['peer_name']) : '';
        $url = isset($_POST['peer_url']) ? esc_url_raw((string) $_POST['peer_url']) : '';
        $tokenInput = isset($_POST['peer_token']) ? sanitize_text_field((string) $_POST['peer_token']) : '';
        $pairingInput = isset($_POST['peer_pairing']) ? trim((string) wp_unslash((string) $_POST['peer_pairing'])) : '';

        $pairing = null;
        if ($pairingInput !== '') {
            $pairing = Peer::decodePairingCode($pairingInput);
            if ($pairing === null) {
                $this->redirect('peer_invalid_pairing');
            }

            if ($name === '') {
                $name = (string) $pairing['name'];
            }
            if ($url === '') {
                $url = esc_url_raw((string) $pairing['url']);
            }
        }

        if ($name === '' || $url === '') {
            $this->redirect('peer_missing_fields');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->redirect('peer_invalid_url');
        }

        // HTTPS-Zwang + SSRF-Schutz (in Produktion hart, in Dev per Environment-Default offen).
        $urlError = PeerUrl::validate($url);
        if ($urlError !== null) {
            $this->redirect($urlError);
        }

        if ($this->registry->getByName($name) !== null) {
            $this->redirect('peer_name_exists');
        }

        $peer = Peer::create(
            name: $name,
            url: $url,
            token: $pairing !== null ? $pairing['token'] : ($tokenInput !== '' ? $tokenInput : null),
            id: $pairing !== null ? $pairing['id'] : null,
        );
        $this->registry->add($peer);

        // Nur beim Erzeugen-Weg (kein Pairing-Code eingegeben) zeigen wir danach den
        // eigenen Pairing-Code zum Weitergeben. Der Code-eingeben-Weg ist sofort fertig.
        if ($pairing === null) {
            set_transient(self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id(), [
                'peer_id' => $peer->id,
                'token' => $peer->token,
            ], 60);
        }

        $this->redirect('peer_added');
    }

    public function handleRemove(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_REMOVE);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        if ($id !== '') {
            $this->registry->remove($id);
            $this->redirect('peer_removed');
        }

        $this->redirect('peer_not_found');
    }

    public function handleRegenerate(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_REGEN);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        $newToken = Peer::generateToken();
        $this->registry->update($peer->withToken($newToken));

        set_transient(self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'token' => $newToken,
        ], 60);

        $this->redirect('peer_regenerated');
    }

    public function handleUpdateProfile(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PROFILE);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        $newProfile = $this->profileFromPost();

        if ($newProfile->isEmpty()) {
            $this->redirect('profile_empty');
        }

        $this->registry->update($peer->withProfile($newProfile));
        $this->redirect('peer_profile_saved');
    }

    public function handleUpdatePermissions(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PERMISSIONS);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        $permissions = new SyncPermissions(
            allowPullFrom: !empty($_POST['allow_pull_from']),
            allowPushTo: !empty($_POST['allow_push_to']),
            allowInboundExport: !empty($_POST['allow_inbound_export']),
            allowInboundImport: !empty($_POST['allow_inbound_import']),
        );

        $this->registry->update($peer->withPermissions($permissions));
        $this->redirect('peer_permissions_saved');
    }

    public function handlePull(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PULL);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        if (! $peer->permissions->allowPullFrom) {
            $this->redirect('pull_forbidden');
        }

        $result = $this->pullOperation->execute($peer);

        set_transient(self::PULL_RESULT_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'peer_name' => $peer->name,
            'success' => $result->success,
            'bytes' => $result->bytes,
            'duration_ms' => $result->durationMs,
            'error' => $result->error,
        ], 60);

        $this->redirect($result->success ? 'pull_success' : 'pull_failed');
    }

    public function handlePush(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-sync'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PUSH);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        if (! $peer->permissions->allowPushTo) {
            $this->redirect('push_forbidden');
        }

        $result = $this->pushOperation->execute($peer);

        set_transient(self::PUSH_RESULT_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'peer_name' => $peer->name,
            'success' => $result->success,
            'bytes' => $result->bytes,
            'chunks' => $result->chunks,
            'duration_ms' => $result->durationMs,
            'remote_import_ms' => $result->remoteImportMs,
            'error' => $result->error,
        ], 60);

        $this->redirect($result->success ? 'push_success' : 'push_failed');
    }

    // ============================================================
    // AJAX-Handler für Premium-UX
    // ============================================================

    /**
     * Pre-Flight: Manifest vom Peer abrufen + UI-Daten zusammenstellen.
     */
    public function ajaxPreflight(): void
    {
        $this->checkAjax();

        $peer = $this->resolvePeerFromAjax();

        try {
            $response = $this->client->request($peer, 'GET', '/rhbp/v1/sync/manifest');
            if (!$response->isSuccess()) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Peer nicht erreichbar (HTTP %d): %s', 'rh-sync'),
                        $response->status,
                        $response->error ?? __('Unbekannter Fehler', 'rh-sync')
                    ),
                ], 502);
            }

            $manifest = $response->json() ?? [];

            wp_send_json_success([
                'manifest' => $manifest,
                'peer' => [
                    'id' => $peer->id,
                    'name' => $peer->name,
                    'url' => $peer->url,
                ],
                'profile' => $peer->profile->toArray(),
                'profile_summary' => $this->profileSummary($peer->profile),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Pull asynchron starten, gibt sofort job_id zurück, lange Operation läuft danach im selben Request weiter.
     */
    public function ajaxPullAsync(): void
    {
        $this->checkAjax();
        $peer = $this->resolvePeerFromAjax();

        $jobId = SyncStatus::start($peer->id, SyncStatus::DIRECTION_PULL, $peer->profile);

        // Sofort Response senden, Connection schließen, dann Operation ausfuehren
        $this->respondAndDetach(['job_id' => $jobId]);

        try {
            $this->pullOperation->execute($peer, $peer->profile, $jobId);
        } catch (\Throwable $e) {
            // execute() faengt eigentlich alles und schreibt es in SyncStatus, aber zur Sicherheit:
            SyncStatus::failed($jobId, $e->getMessage());
        }

        exit;
    }

    /**
     * Push asynchron starten, gibt sofort job_id zurück.
     */
    public function ajaxPushAsync(): void
    {
        $this->checkAjax();
        $peer = $this->resolvePeerFromAjax();

        $jobId = SyncStatus::start($peer->id, SyncStatus::DIRECTION_PUSH, $peer->profile);

        $this->respondAndDetach(['job_id' => $jobId]);

        try {
            $this->pushOperation->execute($peer, $peer->profile, $jobId);
        } catch (\Throwable $e) {
            SyncStatus::failed($jobId, $e->getMessage());
        }

        exit;
    }

    /**
     * Status pollen.
     */
    public function ajaxSyncStatus(): void
    {
        $this->checkAjax();

        $jobId = isset($_GET['job_id']) ? sanitize_text_field((string) $_GET['job_id']) : '';
        if ($jobId === '' || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            wp_send_json_error(['message' => __('Ungültige Job-ID.', 'rh-sync')], 400);
        }

        $status = SyncStatus::get($jobId);
        if ($status === null) {
            wp_send_json_error(['message' => __('Job nicht gefunden oder abgelaufen.', 'rh-sync')], 404);
        }

        wp_send_json_success($status);
    }

    /**
     * Status nach Anzeige aufraeumen (auf "Schließen" im Modal).
     */
    public function ajaxSyncClear(): void
    {
        $this->checkAjax();

        $jobId = isset($_POST['job_id']) ? sanitize_text_field((string) $_POST['job_id']) : '';
        if ($jobId !== '' && preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            SyncStatus::clear($jobId);
        }

        wp_send_json_success();
    }

    // ============================================================
    // AJAX-Helpers
    // ============================================================

    private function checkAjax(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'rh-sync')], 403);
        }

        $nonce = isset($_REQUEST['nonce']) ? (string) $_REQUEST['nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_AJAX)) {
            wp_send_json_error(['message' => __('Sicherheitsprüfung fehlgeschlagen.', 'rh-sync')], 403);
        }
    }

    private function resolvePeerFromAjax(): Peer
    {
        $id = isset($_REQUEST['peer_id']) ? sanitize_text_field((string) $_REQUEST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            wp_send_json_error(['message' => __('Peer nicht gefunden.', 'rh-sync')], 404);
        }
        /** @var Peer $peer */
        return $peer;
    }

    /**
     * Sendet sofort eine JSON-Response, schließt die Connection und gibt Steuerung
     * an den Caller zurück damit die lange Operation im selben Request weiterläuft.
     *
     * @param array<string, mixed> $payload
     */
    private function respondAndDetach(array $payload): void
    {
        ignore_user_abort(true);

        $response = wp_json_encode(['success' => true, 'data' => $payload]);
        if (!is_string($response)) {
            $response = '{"success":true,"data":{}}';
        }

        // Header + Body senden
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($response));
            header('Connection: close');
        }

        echo $response;

        // Output-Buffers leeren
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();

        // PHP-FPM: Connection sauber schließen, PHP arbeitet weiter
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    private function profileFromPost(): SyncProfile
    {
        return new SyncProfile(
            content: !empty($_POST['profile']['content']),
            taxonomies: !empty($_POST['profile']['taxonomies']),
            comments: !empty($_POST['profile']['comments']),
            users: !empty($_POST['profile']['users']),
            options: !empty($_POST['profile']['options']),
            links: !empty($_POST['profile']['links']),
            customTables: !empty($_POST['profile']['customTables']),
            uploads: !empty($_POST['profile']['uploads']),
        );
    }

    private function profileSummary(SyncProfile $profile): string
    {
        if ($profile->isFullSync()) {
            return __('Voll, alle Bereiche aktiv', 'rh-sync');
        }

        $labels = SyncProfile::groupLabels();
        $active = array_map(static fn (string $g): string => $labels[$g] ?? $g, $profile->activeGroups());

        return sprintf(
            /* translators: 1: count, 2: total, 3: list of active groups */
            __('%1$d von %2$d aktiv, %3$s', 'rh-sync'),
            $profile->activeCount(),
            8,
            implode(', ', $active)
        );
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => SettingsPage::MENU_SLUG,
            'tab' => self::TAB_ID,
            'rhbp_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    // ============================================================
    // Rendering
    // ============================================================

    /**
     * Code-Modal, öffnet automatisch nach "Verbindung erzeugen" und zeigt den
     * eigenen Pairing-Code zum Weitergeben an die Gegenseite.
     */
    private function renderCodeModal(): void
    {
        $transientKey = self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id();
        $data = get_transient($transientKey);

        if (!is_array($data) || !isset($data['token'], $data['peer_id'])) {
            return;
        }

        delete_transient($transientKey);

        $peer = $this->registry->get((string) $data['peer_id']);
        if ($peer === null) {
            return;
        }

        $pairingCode = $peer->makePairingCode();

        echo '<div class="rhbp-modal-backdrop is-open" id="rhbp-modal-code" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true">';

        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l">';
        echo '<span class="rhbp-modal__head-icon rhbp-modal__head-icon--ok">' . $this->icon('check') . '</span>';
        echo '<div>';
        echo '<h3 class="rhbp-modal__title">' . esc_html(sprintf(/* translators: %s: peer name */ __('Verbindung „%s" erstellt', 'rh-sync'), $peer->name)) . '</h3>';
        echo '<p class="rhbp-modal__sub">' . esc_html__('Ein Schritt fehlt noch auf der anderen Site.', 'rh-sync') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-sync') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<div class="rhbp-modal__body">';
        echo '<p style="margin-top:0;">' . wp_kses(
            sprintf(/* translators: %s: bold path "Sync -> Code eingeben" */ __('Öffne auf der anderen Site %s und füg diesen Code ein:', 'rh-sync'), '<strong>' . esc_html__('Sync → Code eingeben', 'rh-sync') . '</strong>'),
            ['strong' => []]
        ) . '</p>';

        echo '<div class="rhbp-codebox">';
        echo '<code id="rhbp-pairing-code">' . esc_html($pairingCode) . '</code>';
        echo '<button type="button" class="rhbp-btn" data-rhbp-copy="#rhbp-pairing-code" title="' . esc_attr__('Kopieren', 'rh-sync') . '" aria-label="' . esc_attr__('Code kopieren', 'rh-sync') . '">' . $this->icon('copy', 'sm') . '</button>';
        echo '</div>';

        echo '<div class="rhbp-callout rhbp-callout--warn" style="margin-top:14px;">' . $this->icon('lock', 'sm') . '<span>' . esc_html__('Der Code enthält ein geheimes Token. Gib ihn nur über einen sicheren Weg weiter (1Password, verschlüsselter Chat), nicht offen. Nach dem Schließen ist er nicht mehr abrufbar, dann nur noch neu erzeugen.', 'rh-sync') . '</span></div>';

        echo '</div>';

        echo '<div class="rhbp-modal__foot">';
        echo '<button type="button" class="rhbp-btn rhbp-btn--primary" data-rhbp-modal-close>' . esc_html__('Fertig', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private function renderPullResultNotice(): void
    {
        $transientKey = self::PULL_RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $data = get_transient($transientKey);

        // Fallback: wenn kein Transient (z.B. nach Auto-Logout durch users-Sync),
        // prüfen ob der letzte Log-Eintrag ein erfolgreicher Pull innerhalb der letzten 5 Min war.
        if (!is_array($data) || !isset($data['peer_name'])) {
            $this->renderPostLogoutSuccessNotice();
            return;
        }

        delete_transient($transientKey);

        $success = !empty($data['success']);
        $bytes = isset($data['bytes']) ? (int) $data['bytes'] : 0;
        $duration = isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0;
        $error = isset($data['error']) ? (string) $data['error'] : '';

        $modifier = $success ? 'success' : 'error';

        echo '<div class="rhbp-pull-result rhbp-pull-result--' . esc_attr($modifier) . '">';
        echo '<div class="rhbp-pull-result__header">';
        echo '<span class="dashicons dashicons-' . ($success ? 'yes-alt' : 'warning') . '" aria-hidden="true"></span>';
        echo '<strong>';
        if ($success) {
            printf(esc_html__('Pull von "%s" erfolgreich', 'rh-sync'), esc_html((string) $data['peer_name']));
        } else {
            printf(esc_html__('Pull von "%s" fehlgeschlagen', 'rh-sync'), esc_html((string) $data['peer_name']));
        }
        echo '</strong>';
        echo '</div>';

        if ($success) {
            echo '<p>';
            printf(
                esc_html__('%1$s in %2$d ms gezogen und importiert.', 'rh-sync'),
                esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
                $duration
            );
            echo '</p>';
        } else {
            echo '<p>' . esc_html($error) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Faengt den Fall ab, dass ein Pull mit users:true die Session gekillt hat:
     * Transient ist weg, aber der letzte SyncLog-Eintrag ist ein erfolgreicher Pull
     * innerhalb der letzten 5 Minuten. Dann zeigen wir den passenden Banner.
     *
     * Markiert sich danach in einer User-Meta damit der Banner nicht bei jedem Reload
     * wieder erscheint.
     */
    private function renderPostLogoutSuccessNotice(): void
    {
        $entries = $this->log->all();
        if ($entries === []) {
            return;
        }

        $latest = $entries[0];
        if (!is_array($latest)) {
            return;
        }

        $direction = (string) ($latest['direction'] ?? '');
        $status = (string) ($latest['status'] ?? '');
        $timestamp = (int) ($latest['timestamp'] ?? 0);
        $profileData = isset($latest['profile']) && is_array($latest['profile']) ? $latest['profile'] : null;

        $isRecentPullSuccess = $direction === 'pull'
            && $status === 'success'
            && (time() - $timestamp) < 300; // 5 Minuten

        $hadUserSync = $profileData !== null && !empty($profileData['users']);

        if (!$isRecentPullSuccess || !$hadUserSync) {
            return;
        }

        // Idempotenz: nur einmal anzeigen pro Eintrag
        $userId = get_current_user_id();
        $alreadySeen = (string) get_user_meta($userId, 'rhbp_last_seen_post_logout_sync', true);
        $marker = (string) $timestamp;
        if ($alreadySeen === $marker) {
            return;
        }
        update_user_meta($userId, 'rhbp_last_seen_post_logout_sync', $marker);

        $peerName = (string) ($latest['peer_name'] ?? ', ');
        $bytes = (int) ($latest['bytes'] ?? 0);
        $duration = (int) ($latest['duration_ms'] ?? 0);

        echo '<div class="rhbp-pull-result rhbp-pull-result--success">';
        echo '<div class="rhbp-pull-result__header">';
        echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
        echo '<strong>';
        printf(esc_html__('Pull von "%s" erfolgreich abgeschlossen', 'rh-sync'), esc_html($peerName));
        echo '</strong>';
        echo '</div>';
        echo '<p>';
        printf(
            esc_html__('%1$s in %2$d ms gezogen und importiert. Du wurdest abgemeldet weil die Benutzer-Tabelle ersetzt wurde, jetzt bist du mit den neuen Zugangsdaten angemeldet.', 'rh-sync'),
            esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
            $duration
        );
        echo '</p>';
        echo '</div>';
    }

    private function renderPushResultNotice(): void
    {
        $transientKey = self::PUSH_RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $data = get_transient($transientKey);

        if (!is_array($data) || !isset($data['peer_name'])) {
            return;
        }

        delete_transient($transientKey);

        $success = !empty($data['success']);
        $bytes = isset($data['bytes']) ? (int) $data['bytes'] : 0;
        $chunks = isset($data['chunks']) ? (int) $data['chunks'] : 0;
        $duration = isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0;
        $remoteMs = isset($data['remote_import_ms']) && $data['remote_import_ms'] !== null ? (int) $data['remote_import_ms'] : null;
        $error = isset($data['error']) ? (string) $data['error'] : '';

        $modifier = $success ? 'success' : 'error';

        echo '<div class="rhbp-pull-result rhbp-pull-result--' . esc_attr($modifier) . '">';
        echo '<div class="rhbp-pull-result__header">';
        echo '<span class="dashicons dashicons-' . ($success ? 'yes-alt' : 'warning') . '" aria-hidden="true"></span>';
        echo '<strong>';
        if ($success) {
            printf(esc_html__('Push zu "%s" erfolgreich', 'rh-sync'), esc_html((string) $data['peer_name']));
        } else {
            printf(esc_html__('Push zu "%s" fehlgeschlagen', 'rh-sync'), esc_html((string) $data['peer_name']));
        }
        echo '</strong>';
        echo '</div>';

        if ($success) {
            echo '<p>';
            if ($remoteMs !== null) {
                printf(
                    esc_html__('%1$s in %2$d Chunks hochgeladen, %3$d ms gesamt (davon %4$d ms Remote-Import).', 'rh-sync'),
                    esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
                    $chunks,
                    $duration,
                    $remoteMs
                );
            } else {
                printf(
                    esc_html__('%1$s in %2$d Chunks in %3$d ms hochgeladen.', 'rh-sync'),
                    esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
                    $chunks,
                    $duration
                );
            }
            echo '</p>';
        } else {
            echo '<p>' . esc_html($error) . '</p>';
        }

        echo '</div>';
    }

    /**
     * @param array<int, Peer> $peers
     */
    private function renderPeerList(array $peers): void
    {
        if ($peers === []) {
            $this->renderEmptyState();
            return;
        }

        echo '<div class="rhbp-card-grid">';
        foreach ($peers as $peer) {
            $this->renderPeerCard($peer);
        }
        echo '</div>';
    }

    /**
     * Leerzustand: die zwei Wege als grosse Auswahl-Kacheln.
     */
    private function renderEmptyState(): void
    {
        echo '<p class="rhbp-sync__empty-hint">' . esc_html__('Noch keine Verbindung eingerichtet. Wie willst du starten?', 'rh-sync') . '</p>';
        echo '<div class="rhbp-choices">';

        echo '<button type="button" class="rhbp-choice" data-rhbp-modal-open="rhbp-modal-create">';
        echo '<span class="rhbp-choice__icon">' . $this->icon('plus') . '</span>';
        echo '<span class="rhbp-choice__title">' . esc_html__('Verbindung erzeugen', 'rh-sync') . '</span>';
        echo '<span class="rhbp-choice__desc">' . esc_html__('Du startest. Du gibst der Gegenseite danach einen Code, mit dem sie sich koppelt.', 'rh-sync') . '</span>';
        echo '</button>';

        echo '<button type="button" class="rhbp-choice" data-rhbp-modal-open="rhbp-modal-join">';
        echo '<span class="rhbp-choice__icon">' . $this->icon('inbox') . '</span>';
        echo '<span class="rhbp-choice__title">' . esc_html__('Code eingeben', 'rh-sync') . '</span>';
        echo '<span class="rhbp-choice__desc">' . esc_html__('Die andere Site hat schon eine Verbindung erzeugt? Füg ihren Code hier ein.', 'rh-sync') . '</span>';
        echo '</button>';

        echo '</div>';
    }

    private function renderPeerCard(Peer $peer): void
    {
        $activeJob = SyncStatus::forPeer($peer->id);
        $isLocked = $activeJob !== null;
        $lockDirection = $isLocked ? (string) ($activeJob['direction'] ?? '') : '';

        // data-peer-id ist der JS-Hook (Pull/Push-Bindings, Lock-State), keine
        // eigene CSS-Klasse noetig, die Card nutzt nur die generische .rhbp-card.
        echo '<div class="rhbp-card" data-peer-id="' . esc_attr($peer->id) . '" data-peer-name="' . esc_attr($peer->name) . '"' . ($isLocked ? ' data-active-job="' . esc_attr((string) $activeJob['job_id']) . '"' : '') . '>';

        // Kopf: Name + Status
        echo '<div class="rhbp-card__head">';
        echo '<span class="rhbp-card__title">' . $this->icon('site') . '<strong>' . esc_html($peer->name) . '</strong></span>';
        if ($isLocked) {
            $directionLabel = $lockDirection === SyncStatus::DIRECTION_PULL
                ? __('Pull läuft', 'rh-sync')
                : __('Push läuft', 'rh-sync');
            echo '<span class="rhbp-pill rhbp-pill--accent"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html($directionLabel) . '</span>';
        } else {
            echo '<span class="rhbp-pill">' . esc_html__('Bereit', 'rh-sync') . '</span>';
        }
        echo '</div>';

        // URL
        echo '<a class="rhbp-extlink" style="margin:10px 0 14px;" href="' . esc_url($peer->url) . '" target="_blank" rel="noopener">' . esc_html($peer->url) . ' ' . $this->icon('external', 'sm') . '</a>';

        // Meta: letzter Sync + Profil-Pill
        echo '<dl class="rhbp-meta" style="margin-bottom:4px;">';
        echo '<dt>' . esc_html__('Letzter Sync', 'rh-sync') . '</dt>';
        echo '<dd>' . ($peer->lastSync === null ? esc_html__('noch nie', 'rh-sync') : esc_html(wp_date('d.m.Y, H:i', (int) $peer->lastSync['timestamp']))) . '</dd>';
        echo '<dt>' . esc_html__('Profil', 'rh-sync') . '</dt>';
        echo '<dd>' . $this->profilePill($peer->profile) . '</dd>';
        echo '</dl>';

        // Aktionen: Pull, Push, Einstellungen
        echo '<div class="rhbp-card__actions">';

        $pullDisabled = ($isLocked || ! $peer->permissions->allowPullFrom) ? ' disabled aria-disabled="true"' : '';
        $pushDisabled = ($isLocked || ! $peer->permissions->allowPushTo) ? ' disabled aria-disabled="true"' : '';
        $pullTitle = ! $peer->permissions->allowPullFrom ? ' title="' . esc_attr__('Pull von dieser Site ist in den Einstellungen deaktiviert.', 'rh-sync') . '"' : '';
        $pushTitle = ! $peer->permissions->allowPushTo ? ' title="' . esc_attr__('Push zu dieser Site ist in den Einstellungen deaktiviert.', 'rh-sync') . '"' : '';

        // Pull (JS hijackt submit -> Progress-Modal, fallback admin-post)
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-peer-action" data-action="pull">';
        wp_nonce_field(self::NONCE_PULL);
        echo '<input type="hidden" name="action" value="rhbp_peer_pull" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary"' . $pullDisabled . $pullTitle . '>' . $this->icon('pull', 'sm') . ' ' . esc_html__('Pull', 'rh-sync') . '</button>';
        echo '</form>';

        // Push
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-peer-action" data-action="push">';
        wp_nonce_field(self::NONCE_PUSH);
        echo '<input type="hidden" name="action" value="rhbp_peer_push" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="rhbp-btn"' . $pushDisabled . $pushTitle . '>' . $this->icon('push', 'sm') . ' ' . esc_html__('Push', 'rh-sync') . '</button>';
        echo '</form>';

        echo '<span class="rhbp-spacer"></span>';

        // Einstellungen (öffnet Settings-Modal dieses Peers)
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-open="rhbp-modal-settings-' . esc_attr($peer->id) . '" title="' . esc_attr__('Einstellungen', 'rh-sync') . '" aria-label="' . esc_attr__('Einstellungen', 'rh-sync') . '">' . $this->icon('gear') . '</button>';

        // Verbindung entfernen (Mülleimer direkt auf der Card)
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;">';
        wp_nonce_field(self::NONCE_REMOVE);
        echo '<input type="hidden" name="action" value="rhbp_peer_remove" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon rhbp-btn--trash" title="' . esc_attr__('Verbindung entfernen', 'rh-sync') . '" aria-label="' . esc_attr__('Verbindung entfernen', 'rh-sync') . '" onclick="return confirm(\'' . esc_js(__('Verbindung wirklich entfernen?', 'rh-sync')) . '\')">' . $this->icon('trash') . '</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>'; // .rhbp-card

        // Settings-Modal für diesen Peer (versteckt)
        $this->renderSettingsModal($peer);
    }

    /**
     * Profil-Pill für die Card: "Voll" oder benannte Schnellwahl + Anzahl.
     */
    private function profilePill(SyncProfile $profile): string
    {
        if ($profile->isFullSync()) {
            return '<span class="rhbp-pill rhbp-pill--ok">' . esc_html__('Voll', 'rh-sync') . '</span>';
        }

        $preset = $this->presetName($profile);
        $count = $profile->activeCount();
        $text = ($preset !== null ? $preset . ' · ' : '') . sprintf(
            /* translators: %d: number of active areas */
            __('%d von 8', 'rh-sync'),
            $count
        );

        return '<span class="rhbp-pill rhbp-pill--warn">' . esc_html($text) . '</span>';
    }

    /**
     * Erkennt ob das Profil einer bekannten Schnellwahl entspricht (für die Pill).
     */
    private function presetName(SyncProfile $profile): ?string
    {
        $flags = array_map('intval', $profile->toArray());

        $presets = [
            __('Ohne Benutzer', 'rh-sync') => ['content' => 1, 'taxonomies' => 1, 'comments' => 1, 'users' => 0, 'options' => 1, 'links' => 1, 'customTables' => 1, 'uploads' => 1],
            __('Nur Inhalte + Medien', 'rh-sync') => ['content' => 1, 'taxonomies' => 1, 'comments' => 0, 'users' => 0, 'options' => 0, 'links' => 0, 'customTables' => 0, 'uploads' => 1],
            __('Nur DB', 'rh-sync') => ['content' => 1, 'taxonomies' => 1, 'comments' => 1, 'users' => 1, 'options' => 1, 'links' => 1, 'customTables' => 1, 'uploads' => 0],
        ];

        foreach ($presets as $label => $cfg) {
            if ($flags === $cfg) {
                return (string) $label;
            }
        }

        return null;
    }

    /**
     * Einstellungen-Modal eines Peers: drei Tabs (Sync-Profil, Berechtigungen,
     * Verbindung). Versteckt, wird per Zahnrad-Klick geoeffnet. Jeder Tab ist ein
     * echtes admin-post-Form, funktioniert also auch ohne JS.
     */
    private function renderSettingsModal(Peer $peer): void
    {
        $modalId = 'rhbp-modal-settings-' . $peer->id;

        echo '<div class="rhbp-modal-backdrop" id="' . esc_attr($modalId) . '" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true" aria-label="' . esc_attr(sprintf(/* translators: %s: peer name */ __('Einstellungen für %s', 'rh-sync'), $peer->name)) . '">';

        // Kopf
        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l">';
        echo '<span class="rhbp-modal__head-icon">' . $this->icon('gear') . '</span>';
        echo '<div>';
        echo '<h3 class="rhbp-modal__title">' . esc_html(sprintf(/* translators: %s: peer name */ __('Einstellungen · %s', 'rh-sync'), $peer->name)) . '</h3>';
        echo '<p class="rhbp-modal__sub">' . esc_html($peer->url) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-sync') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<div class="rhbp-modal__body">';

        // Sub-Tabs
        echo '<div class="rhbp-subtabs">';
        echo '<button type="button" class="rhbp-subtab is-active" data-rhbp-subtab="profile">' . esc_html__('Sync-Profil', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-subtab" data-rhbp-subtab="perms">' . esc_html__('Berechtigungen', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-subtab" data-rhbp-subtab="conn">' . esc_html__('Verbindung', 'rh-sync') . '</button>';
        echo '</div>';

        $this->renderProfilePane($peer);
        $this->renderPermsPane($peer);
        $this->renderConnPane($peer);

        echo '</div>'; // body
        echo '</div>'; // modal
        echo '</div>'; // backdrop
    }

    private function renderProfilePane(Peer $peer): void
    {
        $labels = SyncProfile::groupLabels();
        $descriptions = SyncProfile::groupDescriptions();
        $flags = $peer->profile->toArray();

        echo '<div class="rhbp-tabpane is-active" data-rhbp-pane="profile">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-profile-form>';
        wp_nonce_field(self::NONCE_PROFILE);
        echo '<input type="hidden" name="action" value="rhbp_peer_update_profile" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';

        echo '<p class="rhbp-pane-intro">' . esc_html__('Welche Daten werden mit dieser Site abgeglichen? Gilt für Pull und Push.', 'rh-sync') . '</p>';

        echo '<div class="rhbp-option-grid">';
        foreach ($labels as $key => $label) {
            $checked = !empty($flags[$key]);
            $desc = $descriptions[$key] ?? '';
            echo '<label class="rhbp-option' . ($checked ? ' is-checked' : '') . '">';
            echo '<input type="checkbox" name="profile[' . esc_attr($key) . ']" value="1"' . ($checked ? ' checked' : '') . ' data-profile-flag="' . esc_attr($key) . '" />';
            echo '<span class="rhbp-option__text">';
            echo '<span class="rhbp-option__label">' . esc_html($label) . '</span>';
            echo '<span class="rhbp-option__desc">' . esc_html($desc) . '</span>';
            echo '</span>';
            echo '</label>';
        }
        echo '</div>';

        echo '<div class="rhbp-presets">';
        echo '<span class="rhbp-presets__label">' . esc_html__('Schnellwahl:', 'rh-sync') . '</span>';
        echo '<button type="button" class="rhbp-linkbtn" data-preset="all">' . esc_html__('Alles', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-linkbtn" data-preset="no-users">' . esc_html__('Ohne Benutzer', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-linkbtn" data-preset="content-only">' . esc_html__('Nur Inhalte + Medien', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-linkbtn" data-preset="db-only">' . esc_html__('Nur DB, keine Dateien', 'rh-sync') . '</button>';
        echo '</div>';

        echo '<div class="rhbp-modal__pane-actions">';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary rhbp-profile-save">' . esc_html__('Profil speichern', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    private function renderPermsPane(Peer $peer): void
    {
        $p = $peer->permissions;

        echo '<div class="rhbp-tabpane" data-rhbp-pane="perms">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_PERMISSIONS);
        echo '<input type="hidden" name="action" value="rhbp_peer_update_permissions" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';

        echo '<p class="rhbp-pane-intro">' . esc_html__('Zwei Richtungen, getrennt geregelt. Oben was du auslöst, unten was die andere Site bei dir auslösen darf.', 'rh-sync') . '</p>';

        // Outbound
        echo '<div class="rhbp-fieldset">';
        echo '<p class="rhbp-fieldset__title">' . $this->icon('arrow-right', 'sm') . ' ' . esc_html(sprintf(/* translators: %s: peer name */ __('Was ich bei %s darf', 'rh-sync'), $peer->name)) . '</p>';
        echo '<p class="rhbp-fieldset__sub">' . esc_html__('Sichert dich gegen versehentliche Aktionen. Du kannst eine Richtung sperren.', 'rh-sync') . '</p>';
        $this->renderCheckRow('allow_pull_from', sprintf(/* translators: %s: peer name */ __('Von %s ziehen (Pull)', 'rh-sync'), $peer->name), __('Daten von dort holen und hier einspielen.', 'rh-sync'), $p->allowPullFrom);
        $this->renderCheckRow('allow_push_to', sprintf(/* translators: %s: peer name */ __('Zu %s schieben (Push)', 'rh-sync'), $peer->name), __('Daten von hier dorthin hochladen.', 'rh-sync'), $p->allowPushTo);
        echo '</div>';

        // Inbound
        echo '<div class="rhbp-fieldset">';
        echo '<p class="rhbp-fieldset__title">' . $this->icon('lock', 'sm') . ' ' . esc_html(sprintf(/* translators: %s: peer name */ __('Was %s bei mir darf', 'rh-sync'), $peer->name)) . '</p>';
        echo '<p class="rhbp-fieldset__sub">' . esc_html__('Die echte Mauer: wird server-seitig erzwungen. Auf Produktion standardmäßig zu.', 'rh-sync') . '</p>';
        $this->renderCheckRow('allow_inbound_export', __('Bei mir abholen erlauben', 'rh-sync'), sprintf(/* translators: %s: peer name */ __('%s darf Daten von dieser Site ziehen.', 'rh-sync'), $peer->name), $p->allowInboundExport);
        $this->renderCheckRow('allow_inbound_import', __('Bei mir einspielen erlauben', 'rh-sync'), sprintf(/* translators: %s: peer name */ __('%s darf Daten in diese Site schreiben.', 'rh-sync'), $peer->name), $p->allowInboundImport);
        echo '<div class="rhbp-callout rhbp-callout--warn" style="margin-top:10px;">' . $this->icon('warning', 'sm') . '<span>' . esc_html__('Einspielen erlauben heißt: diese Gegenseite kann deine Datenbank komplett überschreiben, inklusive der Benutzer und Admin-Zugänge. Nur für Peers aktivieren, denen du voll vertraust. Der Pairing-Code ist dabei der Schlüssel und gehört nur über sichere Kanäle weitergegeben.', 'rh-sync') . '</span></div>';
        if (\RhBlueprint\Core\Environment::isProduction()) {
            echo '<div class="rhbp-callout rhbp-callout--warn" style="margin-top:10px;">' . $this->icon('warning', 'sm') . '<span>' . esc_html__('Diese Site ist als Produktion erkannt, Inbound ist standardmäßig aus.', 'rh-sync') . '</span></div>';
        }
        echo '</div>';

        echo '<div class="rhbp-modal__pane-actions">';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Berechtigungen speichern', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    private function renderConnPane(Peer $peer): void
    {
        echo '<div class="rhbp-tabpane" data-rhbp-pane="conn">';
        echo '<p class="rhbp-pane-intro">' . esc_html__('Technische Details der Kopplung.', 'rh-sync') . '</p>';

        echo '<dl class="rhbp-meta" style="grid-template-columns:auto 1fr; gap:10px 16px;">';
        echo '<dt>' . esc_html__('Token', 'rh-sync') . '</dt><dd><code>' . esc_html($peer->maskedToken()) . '</code></dd>';
        echo '<dt>' . esc_html__('Erstellt', 'rh-sync') . '</dt><dd>' . esc_html(wp_date('d.m.Y, H:i', $peer->createdAt)) . '</dd>';
        echo '<dt>' . esc_html__('Verbindungs-ID', 'rh-sync') . '</dt><dd><code>' . esc_html($peer->id) . '</code></dd>';
        echo '</dl>';

        echo '<div class="rhbp-modal__pane-actions" style="justify-content:flex-start; gap:8px;">';

        // Token neu erzeugen
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_REGEN);
        echo '<input type="hidden" name="action" value="rhbp_peer_regenerate" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="rhbp-btn" onclick="return confirm(\'' . esc_js(__('Token neu erzeugen? Der alte Code wird ungültig, die Gegenseite muss neu gekoppelt werden.', 'rh-sync')) . '\')">' . $this->icon('refresh', 'sm') . ' ' . esc_html__('Token neu erzeugen', 'rh-sync') . '</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';
    }

    private function renderCheckRow(string $name, string $label, string $desc, bool $checked): void
    {
        echo '<label class="rhbp-check-row">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . ' />';
        echo '<span class="rhbp-check-row__text">';
        echo '<span class="rhbp-check-row__label">' . esc_html($label) . '</span>';
        echo '<span class="rhbp-check-row__desc">' . esc_html($desc) . '</span>';
        echo '</span>';
        echo '</label>';
    }

    private function renderHistory(): void
    {
        $entries = $this->log->all();

        if ($entries === []) {
            return;
        }

        $limit = 20;
        $entries = array_slice($entries, 0, $limit);

        echo '<div class="rhbp-sync-history">';
        echo '<h3><span class="dashicons dashicons-backup" aria-hidden="true"></span> ' . esc_html__('Verlauf', 'rh-sync') . '</h3>';
        echo '<table class="rhbp-table rhbp-history-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Zeit', 'rh-sync') . '</th>';
        echo '<th>' . esc_html__('Peer', 'rh-sync') . '</th>';
        echo '<th>' . esc_html__('Richtung', 'rh-sync') . '</th>';
        echo '<th>' . esc_html__('Status', 'rh-sync') . '</th>';
        echo '<th>' . esc_html__('Größe', 'rh-sync') . '</th>';
        echo '<th>' . esc_html__('Dauer', 'rh-sync') . '</th>';
        echo '<th>' . esc_html__('Profil', 'rh-sync') . '</th>';
        echo '<th class="rhbp-history-toggle-col"></th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $i => $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $peerName = isset($entry['peer_name']) ? (string) $entry['peer_name'] : ', ';
            $peerUrl = isset($entry['peer_url']) ? (string) $entry['peer_url'] : '';
            $direction = isset($entry['direction']) ? (string) $entry['direction'] : '';
            $status = isset($entry['status']) ? (string) $entry['status'] : '';
            $bytes = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;
            $durationMs = isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : 0;
            $error = isset($entry['error']) ? (string) $entry['error'] : '';
            $profileData = isset($entry['profile']) && is_array($entry['profile']) ? $entry['profile'] : null;
            $manifest = isset($entry['manifest']) && is_array($entry['manifest']) ? $entry['manifest'] : null;
            $safetyBackup = isset($entry['safety_backup']) ? (string) $entry['safety_backup'] : '';

            $statusClass = $status === 'success' ? 'ok' : 'fail';
            $rowId = 'rhbp-history-row-' . $i;

            $profileSummary = '';
            if ($profileData !== null) {
                $p = SyncProfile::fromArray($profileData);
                $profileSummary = $p->isFullSync() ? __('Voll', 'rh-sync') : sprintf(__('%d von 8', 'rh-sync'), $p->activeCount());
            }

            $hasDetails = $profileData !== null || $manifest !== null || $safetyBackup !== '' || $error !== '';

            echo '<tr class="rhbp-history-row" data-history-row="' . esc_attr($rowId) . '">';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i', $timestamp)) . '</td>';
            echo '<td><strong>' . esc_html($peerName) . '</strong></td>';
            echo '<td><span class="rhbp-pill rhbp-pill--accent">' . esc_html($direction) . '</span></td>';
            echo '<td><span class="rhbp-pill ' . ($statusClass === 'ok' ? 'rhbp-pill--ok' : 'rhbp-pill--err') . '">' . esc_html($status) . '</span></td>';
            echo '<td>' . esc_html($bytes > 0 ? (size_format($bytes, 2) ?: $bytes . ' B') : ', ') . '</td>';
            echo '<td>' . esc_html($durationMs > 0 ? $durationMs . ' ms' : ', ') . '</td>';
            echo '<td>' . esc_html($profileSummary !== '' ? $profileSummary : ', ') . '</td>';
            echo '<td class="rhbp-history-toggle-col">';
            if ($hasDetails) {
                echo '<button type="button" class="button-link rhbp-history-toggle" data-target="' . esc_attr($rowId) . '" aria-label="' . esc_attr__('Details anzeigen', 'rh-sync') . '">';
                echo '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
                echo '</button>';
            }
            echo '</td>';
            echo '</tr>';

            if ($hasDetails) {
                echo '<tr class="rhbp-history-detail" id="' . esc_attr($rowId) . '" hidden>';
                echo '<td colspan="8">';
                echo '<div class="rhbp-history-detail__grid">';

                if ($error !== '') {
                    echo '<div class="rhbp-history-detail__block rhbp-history-detail__block--error">';
                    echo '<h5><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Fehler', 'rh-sync') . '</h5>';
                    echo '<code>' . esc_html($error) . '</code>';
                    echo '</div>';
                }

                if ($profileData !== null) {
                    $p = SyncProfile::fromArray($profileData);
                    $labels = SyncProfile::groupLabels();
                    echo '<div class="rhbp-history-detail__block">';
                    echo '<h5>' . esc_html__('Profil zum Zeitpunkt', 'rh-sync') . '</h5>';
                    echo '<ul class="rhbp-history-profile-list">';
                    foreach ($labels as $key => $label) {
                        $active = !empty($p->toArray()[$key]);
                        echo '<li class="' . ($active ? 'is-on' : 'is-off') . '">';
                        echo '<span class="dashicons dashicons-' . ($active ? 'yes' : 'no-alt') . '" aria-hidden="true"></span> ';
                        echo esc_html($label);
                        echo '</li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }

                if ($manifest !== null) {
                    echo '<div class="rhbp-history-detail__block">';
                    echo '<h5>' . esc_html__('Quellen-Manifest', 'rh-sync') . '</h5>';
                    echo '<dl class="rhbp-history-manifest">';
                    if (isset($manifest['wp_version'])) {
                        echo '<dt>WordPress</dt><dd>' . esc_html((string) $manifest['wp_version']) . '</dd>';
                    }
                    if (isset($manifest['plugin_version'])) {
                        echo '<dt>rh-blueprint</dt><dd>' . esc_html((string) $manifest['plugin_version']) . '</dd>';
                    }
                    if (isset($manifest['post_count'])) {
                        echo '<dt>' . esc_html__('Beiträge', 'rh-sync') . '</dt><dd>' . esc_html((string) $manifest['post_count']) . '</dd>';
                    }
                    if (isset($manifest['db_size'])) {
                        echo '<dt>' . esc_html__('DB-Größe', 'rh-sync') . '</dt><dd>' . esc_html(size_format((int) $manifest['db_size']) ?: $manifest['db_size'] . ' B') . '</dd>';
                    }
                    echo '</dl>';
                    echo '</div>';
                }

                if ($safetyBackup !== '') {
                    echo '<div class="rhbp-history-detail__block">';
                    echo '<h5>' . esc_html__('Sicherheits-Backup', 'rh-sync') . '</h5>';
                    echo '<code class="rhbp-history-safety">' . esc_html(basename($safetyBackup)) . '</code>';
                    echo '</div>';
                }

                if ($peerUrl !== '') {
                    echo '<div class="rhbp-history-detail__block">';
                    echo '<h5>' . esc_html__('Peer-URL', 'rh-sync') . '</h5>';
                    echo '<a href="' . esc_url($peerUrl) . '" target="_blank" rel="noopener">' . esc_html($peerUrl) . '</a>';
                    echo '</div>';
                }

                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * "Verbindung erzeugen": ich starte die Kopplung. Name + Adresse der Gegenseite,
     * danach zeigt das Code-Modal meinen eigenen Pairing-Code zum Weitergeben.
     */
    private function renderCreateModal(): void
    {
        echo '<div class="rhbp-modal-backdrop" id="rhbp-modal-create" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true">';

        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l">';
        echo '<span class="rhbp-modal__head-icon">' . $this->icon('plus') . '</span>';
        echo '<div>';
        echo '<h3 class="rhbp-modal__title">' . esc_html__('Verbindung erzeugen', 'rh-sync') . '</h3>';
        echo '<p class="rhbp-modal__sub">' . esc_html__('Du startest die Kopplung und gibst der Gegenseite danach einen Code.', 'rh-sync') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-sync') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ADD);
        echo '<input type="hidden" name="action" value="rhbp_peer_add" />';

        echo '<div class="rhbp-modal__body">';

        echo '<div class="rhbp-field">';
        echo '<label for="rhbp-create-name">' . esc_html__('Name der Gegenseite', 'rh-sync') . '</label>';
        echo '<input type="text" id="rhbp-create-name" name="peer_name" placeholder="' . esc_attr__('z.B. stage, prod, live', 'rh-sync') . '" required />';
        echo '<p class="rhbp-hint">' . esc_html__('Nur für dich, wie diese Verbindung in der Liste heißt.', 'rh-sync') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-field">';
        echo '<label for="rhbp-create-url">' . esc_html__('Adresse der Gegenseite', 'rh-sync') . '</label>';
        echo '<input type="url" id="rhbp-create-url" name="peer_url" placeholder="https://stage.example.com" required />';
        echo '<p class="rhbp-hint">' . esc_html__('Wohin diese Site synchronisiert. Die Gegenseite bekommt deine Adresse automatisch über den Code, du musst dort nichts eintippen.', 'rh-sync') . '</p>';
        echo '</div>';

        echo '</div>'; // body

        echo '<div class="rhbp-modal__foot">';
        echo '<button type="button" class="rhbp-btn" data-rhbp-modal-close>' . esc_html__('Abbrechen', 'rh-sync') . '</button>';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Erzeugen und Code anzeigen', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * "Code eingeben": die Gegenseite hat schon erzeugt. Name + Adresse kommen aus
     * dem Code.
     */
    private function renderJoinModal(): void
    {
        echo '<div class="rhbp-modal-backdrop" id="rhbp-modal-join" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true">';

        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l">';
        echo '<span class="rhbp-modal__head-icon">' . $this->icon('inbox') . '</span>';
        echo '<div>';
        echo '<h3 class="rhbp-modal__title">' . esc_html__('Code eingeben', 'rh-sync') . '</h3>';
        echo '<p class="rhbp-modal__sub">' . esc_html__('Den Code hat dir die andere Site beim Erzeugen angezeigt.', 'rh-sync') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-sync') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ADD);
        echo '<input type="hidden" name="action" value="rhbp_peer_add" />';

        echo '<div class="rhbp-modal__body">';
        echo '<div class="rhbp-field">';
        echo '<label for="rhbp-join-code">' . esc_html__('Code von der anderen Site', 'rh-sync') . '</label>';
        echo '<textarea id="rhbp-join-code" name="peer_pairing" rows="3" placeholder="' . esc_attr__('Code hier einfügen…', 'rh-sync') . '" required></textarea>';
        echo '<p class="rhbp-hint">' . esc_html__('Name und Adresse der Gegenseite werden automatisch übernommen, du musst nichts weiter eintippen.', 'rh-sync') . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="rhbp-modal__foot">';
        echo '<button type="button" class="rhbp-btn" data-rhbp-modal-close>' . esc_html__('Abbrechen', 'rh-sync') . '</button>';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Verbinden', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Modal-Template (versteckt), wird per JS gefuellt und sichtbar gemacht.
     */
    private function renderSyncModalTemplate(): void
    {
        // Hülle generisch (Overlay + .rhbp-modal). Das Overlay traegt eine eigene,
        // sync-spezifische Klasse, weil seine Sichtbarkeit über das hidden-Attribut
        // läuft (das JS schützt vor Schließen während eines laufenden Syncs) und
        // nicht über die generische .is-open-Mechanik.
        echo '<div class="rhbp-sync-overlay" data-rhbp-modal hidden role="dialog" aria-modal="true" aria-labelledby="rhbp-modal-title">';
        echo '<div class="rhbp-sync-overlay__backdrop" data-modal-close></div>';
        echo '<div class="rhbp-modal rhbp-sync-overlay__card">';

        // Header
        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l">';
        echo '<span class="rhbp-modal__head-icon" data-modal-icon><span class="dashicons dashicons-download" aria-hidden="true"></span></span>';
        echo '<div>';
        echo '<h3 class="rhbp-modal__title" id="rhbp-modal-title" data-modal-title>' . esc_html__('Sync vorbereiten', 'rh-sync') . '</h3>';
        echo '<p class="rhbp-modal__sub" data-modal-subtitle></p>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-modal-close aria-label="' . esc_attr__('Schließen', 'rh-sync') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        // Body, verschiedene States via data-state Attribut
        echo '<div class="rhbp-sync-modal__body">';

        // Loading-State (Pre-Flight)
        echo '<div class="rhbp-sync-modal__state" data-state="loading">';
        echo '<div class="rhbp-sync-modal__loader"><span class="rhbp-sync-modal__spinner" aria-hidden="true"></span></div>';
        echo '<p>' . esc_html__('Verbindung zur Quelle prüfen...', 'rh-sync') . '</p>';
        echo '</div>';

        // Preflight-State (Manifest-Daten + Bestätigung)
        echo '<div class="rhbp-sync-modal__state" data-state="preflight" hidden>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3>' . esc_html__('Quelle', 'rh-sync') . '</h3>';
        echo '<div class="rhbp-sync-modal__source" data-source></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3>' . esc_html__('Auf der Quelle verfügbar', 'rh-sync') . '</h3>';
        echo '<div class="rhbp-sync-modal__stats" data-source-stats></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3>' . esc_html__('Wird übertragen (Profil)', 'rh-sync') . '</h3>';
        echo '<div class="rhbp-sync-modal__profile" data-profile-list></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__warn">';
        echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
        echo esc_html__('Lokale Daten in den aktivierten Bereichen werden überschrieben. Ein Sicherheits-Backup wird automatisch erstellt.', 'rh-sync');
        echo '</div>';
        echo '<div class="rhbp-sync-modal__warn rhbp-sync-modal__warn--critical" data-critical-warn hidden>';
        echo '<span class="dashicons dashicons-shield" aria-hidden="true"></span> ';
        echo '<div>';
        echo '<strong>' . esc_html__('Achtung: deine Sitzung endet.', 'rh-sync') . '</strong><br>';
        echo esc_html__('Deine Benutzer-Tabelle wird ersetzt. Du wirst automatisch abgemeldet und musst dich danach mit den Zugangsdaten der Quell-Site neu anmelden.', 'rh-sync');
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Standalone-State (Pull mit users:true, kein Polling möglich)
        echo '<div class="rhbp-sync-modal__state" data-state="standalone" hidden>';
        echo '<div class="rhbp-sync-modal__loader"><span class="rhbp-sync-modal__spinner" aria-hidden="true"></span></div>';
        echo '<h3 class="rhbp-sync-modal__standalone-title">' . esc_html__('Pull läuft im Hintergrund', 'rh-sync') . '</h3>';
        echo '<p class="rhbp-sync-modal__standalone-text">';
        printf(
            esc_html__('Der Server kopiert gerade die Daten von %s. Das dauert üblicherweise 30 bis 90 Sekunden.', 'rh-sync'),
            '<strong data-standalone-peer></strong>'
        );
        echo '</p>';
        echo '<div class="rhbp-sync-modal__warn rhbp-sync-modal__warn--info">';
        echo '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ';
        echo esc_html__('Sobald deine Benutzer-Tabelle ersetzt wurde, wirst du automatisch abgemeldet. Das ist erwartet, der Sync läuft trotzdem zu Ende.', 'rh-sync');
        echo '</div>';
        echo '<p class="rhbp-sync-modal__hint">';
        echo esc_html__('Den vollständigen Verlauf siehst du nach der Neu-Anmeldung im Sync-Tab.', 'rh-sync');
        echo '</p>';
        echo '</div>';

        // Progress-State
        echo '<div class="rhbp-sync-modal__state" data-state="progress" hidden>';
        echo '<div class="rhbp-sync-modal__elapsed" data-elapsed></div>';
        echo '<ol class="rhbp-sync-modal__steps" data-steps></ol>';
        echo '<div class="rhbp-sync-modal__profile-active">';
        echo '<strong>' . esc_html__('Profil:', 'rh-sync') . '</strong> <span data-profile-summary></span>';
        echo '</div>';
        echo '</div>';

        // Success-State
        echo '<div class="rhbp-sync-modal__state" data-state="success" hidden>';
        echo '<div class="rhbp-sync-modal__success-icon"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></div>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3>' . esc_html__('Zusammenfassung', 'rh-sync') . '</h3>';
        echo '<div class="rhbp-sync-modal__summary" data-summary></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3>' . esc_html__('Phasen-Zeiten', 'rh-sync') . '</h3>';
        echo '<div class="rhbp-sync-modal__phase-timings" data-phase-timings></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section" data-success-profile-section>';
        echo '<h3>' . esc_html__('Eingespielte Bereiche', 'rh-sync') . '</h3>';
        echo '<div class="rhbp-sync-modal__profile" data-success-profile></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section" data-success-safety-section>';
        echo '<h3>' . esc_html__('Sicherheits-Backup', 'rh-sync') . '</h3>';
        echo '<code data-success-safety></code>';
        echo '</div>';
        echo '</div>';

        // Error-State
        echo '<div class="rhbp-sync-modal__state" data-state="error" hidden>';
        echo '<div class="rhbp-sync-modal__error-icon"><span class="dashicons dashicons-warning" aria-hidden="true"></span></div>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3 data-error-title>' . esc_html__('Fehler beim Sync', 'rh-sync') . '</h3>';
        echo '<p class="rhbp-sync-modal__error-phase" data-error-phase></p>';
        echo '<div class="rhbp-sync-modal__error-message" data-error-message></div>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section" data-error-safety-section hidden>';
        echo '<h3>' . esc_html__('Wiederherstellung', 'rh-sync') . '</h3>';
        echo '<p><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> ' . esc_html__('Safety-Backup verfügbar:', 'rh-sync') . '</p>';
        echo '<code data-error-safety></code>';
        echo '</div>';
        echo '<div class="rhbp-sync-modal__section">';
        echo '<h3>' . esc_html__('Was tun?', 'rh-sync') . '</h3>';
        echo '<ul>';
        echo '<li>' . esc_html__('Mit gleichem Profil erneut versuchen', 'rh-sync') . '</li>';
        echo '<li>' . esc_html__('Profil anpassen, z.B. einzelne Bereiche deaktivieren', 'rh-sync') . '</li>';
        echo '<li>' . esc_html__('Verbindung zur Quelle prüfen (URL, Token)', 'rh-sync') . '</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // body end

        // Footer
        echo '<div class="rhbp-modal__foot" data-footer>';
        echo '<button type="button" class="rhbp-btn" data-modal-close>' . esc_html__('Abbrechen', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--primary" data-modal-confirm hidden>' . esc_html__('Sync starten', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-btn" data-modal-retry hidden>' . esc_html__('Erneut versuchen', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--primary" data-modal-finish hidden>' . esc_html__('Schließen', 'rh-sync') . '</button>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--primary" data-modal-login hidden disabled>' . esc_html__('Zur Anmeldung', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</div>'; // card end
        echo '</div>'; // overlay end
    }
}

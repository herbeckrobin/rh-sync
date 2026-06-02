<?php

declare(strict_types=1);

namespace RhSync\Admin;

use RhBlueprint\Core\Settings\SettingsPage;
use RhSync\Sync\Peer;
use RhSync\Sync\PeerRegistry;
use RhSync\Sync\PullOperation;
use RhSync\Sync\PushOperation;
use RhSync\Sync\SyncClient;
use RhSync\Sync\SyncLog;
use RhSync\Sync\SyncPermissions;
use RhSync\Sync\SyncProfile;
use RhSync\Sync\SyncStatus;

final class SyncPeersPage
{
    public const TAB_ID = 'sync_network';
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

        echo '<div class="rhbp-sync" data-rhbp-sync data-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '" data-ajax-nonce="' . esc_attr(wp_create_nonce(self::NONCE_AJAX)) . '">';
        echo '<h2 class="rhbp-sync__heading">' . esc_html__('Sync Network', 'rh-sync') . '</h2>';
        echo '<p class="rhbp-sync__intro">' . esc_html__('Peers sind andere WordPress-Instanzen, mit denen diese Site Datenbank und Uploads synchronisieren kann. Jeder Peer hat ein eigenes Sync-Profil, du legst fest, welche Bereiche übertragen werden.', 'rh-sync') . '</p>';

        $this->renderNewTokenNotice();
        $this->renderPullResultNotice();
        $this->renderPushResultNotice();
        $this->renderPeerList();
        $this->renderAddForm();
        $this->renderHistory();

        // Modal-Template am Ende (versteckt, wird per JS aktiviert)
        $this->renderSyncModalTemplate();

        echo '</div>';
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

        set_transient(self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'token' => $peer->token,
        ], 60);

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

        // Sofort Response senden, Connection schliessen, dann Operation ausfuehren
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
     * Status nach Anzeige aufraeumen (auf "Schliessen" im Modal).
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
     * Sendet sofort eine JSON-Response, schliesst die Connection und gibt Steuerung
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

        // PHP-FPM: Connection sauber schliessen, PHP arbeitet weiter
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
        ], admin_url('options-general.php')));
        exit;
    }

    // ============================================================
    // Rendering
    // ============================================================

    private function renderNewTokenNotice(): void
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

        echo '<div class="rhbp-sync-token-notice">';
        echo '<div class="rhbp-sync-token-notice__header">';
        echo '<span class="dashicons dashicons-shield" aria-hidden="true"></span>';
        echo '<strong>' . esc_html__('Pairing-Code für Peer', 'rh-sync') . ' „' . esc_html($peer->name) . '"</strong>';
        echo '</div>';
        echo '<p>' . esc_html__('Kopiere diesen Code und fuege ihn auf der Gegenseite im Feld "Pairing-Code" ein. Er enthält die UUID + das geteilte Token für die HMAC-Authentifizierung. Wird nach dem Verlassen dieser Seite nicht mehr angezeigt.', 'rh-sync') . '</p>';
        echo '<code class="rhbp-sync-token-notice__token">' . esc_html($pairingCode) . '</code>';
        echo '<details class="rhbp-sync-token-notice__details">';
        echo '<summary>' . esc_html__('Nur das Rohtoken anzeigen', 'rh-sync') . '</summary>';
        echo '<code>' . esc_html((string) $data['token']) . '</code>';
        echo '</details>';
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

    private function renderPeerList(): void
    {
        $peers = $this->registry->all();

        if ($peers === []) {
            echo '<div class="rhbp-empty rhbp-sync__empty">';
            echo esc_html__('Noch keine Peers. Lege unten den ersten an um loszulegen.', 'rh-sync');
            echo '</div>';
            return;
        }

        echo '<div class="rhbp-peer-grid">';
        foreach ($peers as $peer) {
            $this->renderPeerCard($peer);
        }
        echo '</div>';
    }

    private function renderPeerCard(Peer $peer): void
    {
        $activeJob = SyncStatus::forPeer($peer->id);
        $isLocked = $activeJob !== null;
        $lockDirection = $isLocked ? (string) ($activeJob['direction'] ?? '') : '';

        echo '<div class="rhbp-peer-card" data-peer-id="' . esc_attr($peer->id) . '" data-peer-name="' . esc_attr($peer->name) . '"' . ($isLocked ? ' data-active-job="' . esc_attr((string) $activeJob['job_id']) . '"' : '') . '>';

        echo '<div class="rhbp-peer-card__header">';
        echo '<div class="rhbp-peer-card__title">';
        echo '<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>';
        echo '<strong>' . esc_html($peer->name) . '</strong>';
        echo '</div>';

        if ($isLocked) {
            $directionLabel = $lockDirection === SyncStatus::DIRECTION_PULL
                ? __('Pull läuft', 'rh-sync')
                : __('Push läuft', 'rh-sync');
            echo '<span class="rhbp-peer-card__status rhbp-peer-card__status--syncing"><span class="rhbp-spinner-dot" aria-hidden="true"></span> ' . esc_html($directionLabel) . '</span>';
        } else {
            echo '<span class="rhbp-peer-card__status rhbp-peer-card__status--idle">' . esc_html__('Bereit', 'rh-sync') . '</span>';
        }
        echo '</div>';

        echo '<a class="rhbp-peer-card__url" href="' . esc_url($peer->url) . '" target="_blank" rel="noopener">' . esc_html($peer->url) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';

        echo '<dl class="rhbp-peer-card__meta">';
        echo '<dt>' . esc_html__('Token', 'rh-sync') . '</dt>';
        echo '<dd><code>' . esc_html($peer->maskedToken()) . '</code></dd>';
        echo '<dt>' . esc_html__('Erstellt', 'rh-sync') . '</dt>';
        echo '<dd>' . esc_html(wp_date('Y-m-d H:i', $peer->createdAt)) . '</dd>';
        echo '<dt>' . esc_html__('Letzter Sync', 'rh-sync') . '</dt>';
        echo '<dd>' . esc_html($peer->lastSync === null ? ', ' : wp_date('Y-m-d H:i', (int) $peer->lastSync['timestamp'])) . '</dd>';
        echo '</dl>';

        // Profil-Sektion
        $this->renderProfileSection($peer);

        // Berechtigungen
        $this->renderPermissionsSection($peer);

        // Action-Bar
        echo '<div class="rhbp-peer-card__actions">';

        // Outbound-Gating: Buttons sind aus, wenn ein Sync läuft ODER die lokale
        // Permission die Richtung verbietet.
        $pullDisabled = ($isLocked || ! $peer->permissions->allowPullFrom) ? ' disabled aria-disabled="true"' : '';
        $pushDisabled = ($isLocked || ! $peer->permissions->allowPushTo) ? ' disabled aria-disabled="true"' : '';
        $pullTitle = ! $peer->permissions->allowPullFrom ? ' title="' . esc_attr__('Pull von diesem Peer ist deaktiviert.', 'rh-sync') . '"' : '';
        $pushTitle = ! $peer->permissions->allowPushTo ? ' title="' . esc_attr__('Push zu diesem Peer ist deaktiviert.', 'rh-sync') . '"' : '';

        // Pull (triggert JS-Modal, fallback admin-post)
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-peer-card__action-form" data-action="pull">';
        wp_nonce_field(self::NONCE_PULL);
        echo '<input type="hidden" name="action" value="rhbp_peer_pull" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button button-primary rhbp-peer-card__pull"' . $pullDisabled . $pullTitle . '>';
        echo '<span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Pull', 'rh-sync');
        echo '</button>';
        echo '</form>';

        // Push
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-peer-card__action-form" data-action="push">';
        wp_nonce_field(self::NONCE_PUSH);
        echo '<input type="hidden" name="action" value="rhbp_peer_push" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button rhbp-peer-card__push"' . $pushDisabled . $pushTitle . '>';
        echo '<span class="dashicons dashicons-upload" aria-hidden="true"></span> ' . esc_html__('Push', 'rh-sync');
        echo '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::NONCE_REGEN);
        echo '<input type="hidden" name="action" value="rhbp_peer_regenerate" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button" onclick="return confirm(\'Token neu generieren? Das alte Token wird ungültig.\')">';
        echo '<span class="dashicons dashicons-update" aria-hidden="true"></span> ' . esc_html__('Token neu', 'rh-sync');
        echo '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::NONCE_REMOVE);
        echo '<input type="hidden" name="action" value="rhbp_peer_remove" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Peer wirklich entfernen?\')">';
        echo '<span class="dashicons dashicons-trash" aria-hidden="true"></span> ' . esc_html__('Entfernen', 'rh-sync');
        echo '</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';
    }

    private function renderProfileSection(Peer $peer): void
    {
        $profile = $peer->profile;
        $labels = SyncProfile::groupLabels();
        $descriptions = SyncProfile::groupDescriptions();
        $flags = $profile->toArray();

        // Icons je Gruppe
        $icons = [
            'content' => 'admin-post',
            'taxonomies' => 'category',
            'comments' => 'admin-comments',
            'users' => 'admin-users',
            'options' => 'admin-settings',
            'links' => 'admin-links',
            'customTables' => 'database',
            'uploads' => 'images-alt2',
        ];

        echo '<div class="rhbp-peer-card__profile">';

        // Header mit Summary-Pill
        echo '<div class="rhbp-profile__header">';
        echo '<h4><span class="dashicons dashicons-filter" aria-hidden="true"></span> ' . esc_html__('Sync-Profil', 'rh-sync') . '</h4>';
        $summaryClass = $profile->isFullSync() ? 'rhbp-profile-pill--full' : 'rhbp-profile-pill--partial';
        echo '<span class="rhbp-profile-pill ' . esc_attr($summaryClass) . '">';
        if ($profile->isFullSync()) {
            echo esc_html__('Voll', 'rh-sync');
        } else {
            printf(esc_html__('%d von 8 aktiv', 'rh-sync'), $profile->activeCount());
        }
        echo '</span>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-profile-form" data-profile-form>';
        wp_nonce_field(self::NONCE_PROFILE);
        echo '<input type="hidden" name="action" value="rhbp_peer_update_profile" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';

        echo '<div class="rhbp-profile-grid">';
        foreach ($labels as $key => $label) {
            $checked = !empty($flags[$key]);
            $icon = $icons[$key] ?? 'marker';
            $desc = $descriptions[$key] ?? '';

            echo '<label class="rhbp-profile-item' . ($checked ? ' is-checked' : '') . '">';
            echo '<input type="checkbox" name="profile[' . esc_attr($key) . ']" value="1"' . ($checked ? ' checked' : '') . ' data-profile-flag="' . esc_attr($key) . '" />';
            echo '<span class="rhbp-profile-item__inner">';
            echo '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>';
            echo '<span class="rhbp-profile-item__text">';
            echo '<span class="rhbp-profile-item__label">' . esc_html($label) . '</span>';
            echo '<span class="rhbp-profile-item__desc">' . esc_html($desc) . '</span>';
            echo '</span>';
            echo '</span>';
            echo '</label>';
        }
        echo '</div>';

        // Preset-Buttons
        echo '<div class="rhbp-profile-presets">';
        echo '<span class="rhbp-profile-presets__label">' . esc_html__('Voreinstellungen:', 'rh-sync') . '</span>';
        echo '<button type="button" class="button-link rhbp-preset-button" data-preset="all">' . esc_html__('Alles', 'rh-sync') . '</button>';
        echo '<button type="button" class="button-link rhbp-preset-button" data-preset="no-users">' . esc_html__('Ohne Benutzer', 'rh-sync') . '</button>';
        echo '<button type="button" class="button-link rhbp-preset-button" data-preset="content-only">' . esc_html__('Nur Inhalte + Uploads', 'rh-sync') . '</button>';
        echo '<button type="button" class="button-link rhbp-preset-button" data-preset="db-only">' . esc_html__('Nur DB, keine Dateien', 'rh-sync') . '</button>';
        echo '</div>';

        echo '<div class="rhbp-profile-actions">';
        echo '<button type="submit" class="button rhbp-profile-save">';
        echo '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ' . esc_html__('Profil speichern', 'rh-sync');
        echo '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    private function renderPermissionsSection(Peer $peer): void
    {
        $p = $peer->permissions;

        echo '<div class="rhbp-peer-card__permissions">';

        echo '<div class="rhbp-profile__header">';
        echo '<h4><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__('Berechtigungen', 'rh-sync') . '</h4>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-permissions-form">';
        wp_nonce_field(self::NONCE_PERMISSIONS);
        echo '<input type="hidden" name="action" value="rhbp_peer_update_permissions" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';

        echo '<p class="rhbp-perm-group-label">' . esc_html__('Was ich bei diesem Peer darf', 'rh-sync') . '</p>';
        $this->renderPermCheckbox('allow_pull_from', __('Pull (von diesem Peer ziehen)', 'rh-sync'), $p->allowPullFrom);
        $this->renderPermCheckbox('allow_push_to', __('Push (zu diesem Peer schieben)', 'rh-sync'), $p->allowPushTo);

        echo '<p class="rhbp-perm-group-label">' . esc_html__('Was dieser Peer bei mir darf (server-seitig erzwungen)', 'rh-sync') . '</p>';
        $this->renderPermCheckbox('allow_inbound_export', __('Export erlauben (Peer pullt von mir)', 'rh-sync'), $p->allowInboundExport);
        $this->renderPermCheckbox('allow_inbound_import', __('Import erlauben (Peer pusht zu mir)', 'rh-sync'), $p->allowInboundImport);

        echo '<div class="rhbp-profile-actions">';
        echo '<button type="submit" class="button">';
        echo '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ' . esc_html__('Berechtigungen speichern', 'rh-sync');
        echo '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    private function renderPermCheckbox(string $name, string $label, bool $checked): void
    {
        printf(
            '<label class="rhbp-perm-toggle"><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($name),
            checked($checked, true, false),
            esc_html($label)
        );
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
        echo '<table class="rhbp-db-table rhbp-history-table">';
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
            echo '<td><span class="rhbp-history-direction rhbp-history-direction--' . esc_attr($direction) . '">' . esc_html($direction) . '</span></td>';
            echo '<td><span class="rhbp-history-status rhbp-history-status--' . esc_attr($statusClass) . '">' . esc_html($status) . '</span></td>';
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

    private function renderAddForm(): void
    {
        echo '<div class="rhbp-sync-add">';
        echo '<h3>' . esc_html__('Neuen Peer hinzufügen', 'rh-sync') . '</h3>';
        echo '<p class="description">' . esc_html__('Ein Peer ist eine andere WordPress-Site, die das rh-blueprint Plugin aktiv hat. Beide Seiten müssen die gleiche UUID + Token haben, am einfachsten über einen Pairing-Code.', 'rh-sync') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-sync-add__form">';
        wp_nonce_field(self::NONCE_ADD);
        echo '<input type="hidden" name="action" value="rhbp_peer_add" />';

        echo '<div class="rhbp-sync-add__field rhbp-sync-add__field--full">';
        echo '<label for="rhbp-peer-pairing">' . esc_html__('Pairing-Code (empfohlen)', 'rh-sync') . '</label>';
        echo '<textarea id="rhbp-peer-pairing" name="peer_pairing" rows="3" placeholder="' . esc_attr__('Pairing-Code von der anderen Site einfuegen, Name und URL werden automatisch übernommen.', 'rh-sync') . '"></textarea>';
        echo '<p class="description">' . esc_html__('Wurde auf der Gegenseite beim Anlegen des Peers angezeigt. Enthält UUID + Token + optional Name und URL.', 'rh-sync') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__divider"><span>' . esc_html__('oder manuell', 'rh-sync') . '</span></div>';

        echo '<div class="rhbp-sync-add__field">';
        echo '<label for="rhbp-peer-name">' . esc_html__('Name', 'rh-sync') . '</label>';
        echo '<input type="text" id="rhbp-peer-name" name="peer_name" placeholder="stage" />';
        echo '<p class="description">' . esc_html__('Kurzer Bezeichner, z.B. "stage" oder "prod".', 'rh-sync') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__field">';
        echo '<label for="rhbp-peer-url">' . esc_html__('URL', 'rh-sync') . '</label>';
        echo '<input type="url" id="rhbp-peer-url" name="peer_url" placeholder="https://stage.example.com" />';
        echo '<p class="description">' . esc_html__('Basis-URL der Ziel-Instanz (ohne trailing slash).', 'rh-sync') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__field">';
        echo '<label for="rhbp-peer-token">' . esc_html__('Token (optional)', 'rh-sync') . '</label>';
        echo '<input type="text" id="rhbp-peer-token" name="peer_token" placeholder="' . esc_attr__('Leer lassen für automatische Generierung', 'rh-sync') . '" />';
        echo '<p class="description">' . esc_html__('Nur verwenden wenn du KEINEN Pairing-Code hast und das Token manuell matchen willst.', 'rh-sync') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Peer hinzufügen', 'rh-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Modal-Template (versteckt), wird per JS gefuellt und sichtbar gemacht.
     */
    private function renderSyncModalTemplate(): void
    {
        echo '<div class="rhbp-sync-modal" data-rhbp-modal hidden role="dialog" aria-modal="true" aria-labelledby="rhbp-modal-title">';
        echo '<div class="rhbp-sync-modal__backdrop" data-modal-close></div>';
        echo '<div class="rhbp-sync-modal__card">';

        // Header
        echo '<header class="rhbp-sync-modal__header">';
        echo '<div class="rhbp-sync-modal__title-wrap">';
        echo '<span class="rhbp-sync-modal__icon" data-modal-icon><span class="dashicons dashicons-download" aria-hidden="true"></span></span>';
        echo '<div>';
        echo '<h2 id="rhbp-modal-title" data-modal-title>' . esc_html__('Sync vorbereiten', 'rh-sync') . '</h2>';
        echo '<p class="rhbp-sync-modal__subtitle" data-modal-subtitle></p>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-sync-modal__close" data-modal-close aria-label="' . esc_attr__('Schliessen', 'rh-sync') . '">';
        echo '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>';
        echo '</button>';
        echo '</header>';

        // Body, verschiedene States via data-modal-state Attribut
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
        echo '<footer class="rhbp-sync-modal__footer" data-footer>';
        echo '<button type="button" class="button" data-modal-close>' . esc_html__('Abbrechen', 'rh-sync') . '</button>';
        echo '<button type="button" class="button button-primary" data-modal-confirm hidden>' . esc_html__('Sync starten', 'rh-sync') . '</button>';
        echo '<button type="button" class="button" data-modal-retry hidden>' . esc_html__('Erneut versuchen', 'rh-sync') . '</button>';
        echo '<button type="button" class="button button-primary" data-modal-finish hidden>' . esc_html__('Schließen', 'rh-sync') . '</button>';
        echo '<button type="button" class="button button-primary" data-modal-login hidden disabled>' . esc_html__('Zur Anmeldung', 'rh-sync') . '</button>';
        echo '</footer>';

        echo '</div>'; // card end
        echo '</div>'; // modal end
    }
}

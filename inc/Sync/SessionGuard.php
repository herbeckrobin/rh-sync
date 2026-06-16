<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Rettet die Session des handelnden Admins über einen users-Pull hinweg.
 *
 * Ein Pull mit `users`-Profil ersetzt `wp_users`/`wp_usermeta` durch den Quell-Stand. Damit
 * verschwände das Session-Token (und ggf. der Account) des Admins, der den Sync ausgelöst hat,
 * sein Auth-Cookie würde ungültig, er wäre ausgeloggt.
 *
 * Analog zum {@see LocalOptionGuard}: snapshot() vor dem Import (im eingeloggten Trigger-Request,
 * da der Tick selbst userlos läuft), restore() nach dem Import (aus dem Tick heraus, anhand der
 * im Job-State gespeicherten Daten). Wiederhergestellt werden user-Row + alle usermeta des Admins,
 * sodass user_login, user_pass und session_tokens unverändert bleiben und das Cookie gültig bleibt.
 */
final class SessionGuard
{
    /**
     * Erfasst den aktuell eingeloggten Admin. Muss im eingeloggten Kontext laufen.
     *
     * @return array{id: int, login: string, user_row: array<string, mixed>, meta: array<int, array{meta_key: string, meta_value: string}>}|null
     */
    public function snapshot(): ?array
    {
        $user = wp_get_current_user();
        if (!$user instanceof \WP_User || $user->ID === 0) {
            return null;
        }

        global $wpdb;
        $id = (int) $user->ID;

        /** @var array<string, mixed>|null $userRow */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- gezielter Snapshot einer einzelnen User-Row für die Session-Rettung.
        $userRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID = %d", $id), ARRAY_A);
        if (!is_array($userRow)) {
            return null;
        }

        /** @var array<int, array{meta_key: string, meta_value: string}> $meta */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- gezielter Snapshot der usermeta des handelnden Admins.
        $meta = (array) $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $id), ARRAY_A);

        return [
            'id' => $id,
            'login' => (string) $user->user_login,
            'user_row' => $userRow,
            'meta' => $meta,
        ];
    }

    /**
     * Stellt den Admin aus dem Snapshot wieder her. Idempotent.
     *
     * @param array{id: int, login: string, user_row: array<string, mixed>, meta: array<int, array{meta_key: string, meta_value: string}>} $snapshot
     */
    public function restore(array $snapshot): void
    {
        global $wpdb;

        $id = (int) ($snapshot['id'] ?? 0);
        $login = (string) ($snapshot['login'] ?? '');
        $userRow = is_array($snapshot['user_row'] ?? null) ? $snapshot['user_row'] : [];
        if ($id === 0 || $login === '' || $userRow === []) {
            return;
        }

        // Kollisionen auflösen: ein Quell-User mit gleichem Login (andere ID) bzw. ein Eintrag
        // auf unserer Ziel-ID (anderer Login) würde den Restore blockieren.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-Rettung, gezielte Bereinigung kollidierender User-Rows.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->users} WHERE user_login = %s AND ID <> %d", $login, $id));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-Rettung.
        $wpdb->delete($wpdb->users, ['ID' => $id]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-Rettung.
        $wpdb->insert($wpdb->users, $userRow);

        // usermeta des Admins ersetzen (inkl. session_tokens + capabilities).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-Rettung.
        $wpdb->delete($wpdb->usermeta, ['user_id' => $id]);
        foreach ($snapshot['meta'] as $row) {
            if (!isset($row['meta_key'])) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-Rettung.
            $wpdb->insert($wpdb->usermeta, [
                'user_id' => $id,
                'meta_key' => (string) $row['meta_key'],
                'meta_value' => (string) ($row['meta_value'] ?? ''),
            ]);
        }

        if (function_exists('clean_user_cache')) {
            clean_user_cache($id);
        }
        wp_cache_flush();
    }
}

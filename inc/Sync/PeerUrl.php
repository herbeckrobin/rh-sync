<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Sicherheits-Validierung für Peer-URLs (HTTPS-Zwang + SSRF-Schutz).
 *
 * Eine Peer-URL wird vom Admin eingegeben (oder kommt aus einem Pairing-Code der
 * Gegenseite) und das Plugin macht serverseitig Requests dorthin. Zwei Risiken:
 *
 *   1. Klartext: über http reisen die signierten Requests samt Body (kompletter
 *      DB-Dump) offen. HMAC schützt die Integrität, nicht die Vertraulichkeit.
 *   2. SSRF: eine URL die auf localhost / interne IPs zeigt, lässt das Plugin als
 *      Proxy ins interne Netz feuern (Cloud-Metadata 169.254.169.254 etc.).
 *
 * Bewusst NICHT an `wp_get_environment_type()` gekoppelt: das defaultet ohne
 * gesetztes WP_ENVIRONMENT_TYPE auf "production", also auf den meisten Sites.
 * Stattdessen entscheidet der Host selbst: öffentliche Hosts müssen HTTPS sprechen,
 * lokale Dev-Hosts (.ddev.site, .test, .local, localhost) dürfen http.
 *
 * SSRF blockt nur LITERALE private/reservierte IPs in der URL. Hostnamen die per
 * DNS auf eine private IP zeigen, blocken wir absichtlich nicht: das wäre der
 * DNS-Rebinding-Vektor, der bei einem admin-only Feature nur eine Selbst-Attacke
 * ist, aber legitime Setups bricht (DDEV-Loopback über *.ddev.site -> 127.0.0.1,
 * zwei Sites auf einem Server mit Split-Horizon-DNS).
 */
final class PeerUrl
{
    /**
     * Prüft eine Peer-URL. Liefert `null` wenn ok, sonst einen Fehler-Code für die
     * Redirect-Message-Map ('peer_insecure_url' | 'peer_blocked_host' | 'peer_invalid_url').
     */
    public static function validate(string $url): ?string
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            return 'peer_invalid_url';
        }

        if (self::isBlockedHost($host) && ! self::allowPrivate($url)) {
            return 'peer_blocked_host';
        }

        if ($scheme !== 'https' && ! self::allowInsecure($host)) {
            return 'peer_insecure_url';
        }

        return null;
    }

    /**
     * Literale private/reservierte IP oder localhost-Hostname.
     */
    private static function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Literal-IP (v4/v6, evtl. in eckigen Klammern aus der URL).
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false; // Hostname, kein Literal: kein DNS-Lookup (siehe Klassen-Doc).
        }

        // Privat/reserviert? filter_var liefert false, wenn die IP NICHT öffentlich ist.
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $public === false;
    }

    private static function isLocalDevHost(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.ddev.site');
    }

    private static function allowInsecure(string $host): bool
    {
        /**
         * Erlaubt http-Peer-URLs. Default: nur für lokale Dev-Hosts. Öffentliche
         * Hosts müssen HTTPS sprechen.
         */
        return (bool) apply_filters('rh-blueprint/sync/allow_insecure_peer_url', self::isLocalDevHost($host), $host);
    }

    private static function allowPrivate(string $url): bool
    {
        /**
         * Erlaubt Peer-URLs auf literale private/reservierte IPs (z.B. direkter
         * Loopback-Test über https://127.0.0.1). Default: aus.
         */
        return (bool) apply_filters('rh-blueprint/sync/allow_private_peer_url', false, $url);
    }
}

<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;

final class ClientIpResolver {
    public const DEFAULT_TRUSTED_HEADERS = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
    ];

    public static function getClientIp(
        array $server,
        array $trustedProxies = [],
        array $trustedHeaders = self::DEFAULT_TRUSTED_HEADERS
    ): IpResult {
        $remoteAddr = $server['REMOTE_ADDR'] ?? null;
        if (!isset($remoteAddr) || empty($remoteAddr)) return new IpResult(null, "no ip address found", true);

        if (empty($trustedProxies)) {
            $ip = self::sanitizeIp($remoteAddr);
        }

        if (isset($ip)){
            return new IpResult($ip, null, false);
        } else {
            return new IpResult(null, "invalid ip address", true);
        }
    }

    private static function sanitizeIp(?string $ip): ?string {
        if ($ip === null) return null;

        $ip = trim($ip, " \t\n\r\0\x0B\"'[]");

        /**
         * spaties, tab, newline, carriage return, nulbyte, vertical tab
         * haken enkele en dubbel quotes
         */

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            return $ip;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            return $ip;

        return null;
    }
}
/**
    *   - Trim-regel: trim($ip, " \t\n\r\0\x0B\"'[]") verwijdert whitespace, quotes en brackets rondom IP’s (handig voor Forwarded/XFF headers met "1.2.3.4" of [IPv6]), zodat filter_var validatie niet faalt.

    *   - DEFAULT_TRUSTED_HEADERS: lijst met headers waar proxies/CDNs het echte client-IP in zetten (X-Forwarded-For, X-Real-IP, CF-Connecting-IP, Forwarded). Alleen gebruiken als de request via een “trusted proxy” komt.

    *   - REMOTE_ADDR nu gebruiken: veiligste optie zolang je niet achter een LB/CDN zit of geen trusted proxies hebt ingesteld; het is het directe peer-IP en niet spoofbaar door clients.

    *   - Wanneer headers gebruiken: als REMOTE_ADDR het proxy-IP is. Voorwaarden: je kent de proxy-IP’s/subnets (trustedProxies) en vertrouwt die; dan mag je de genoemde headers parsen.

    *   - Praktisch: start eenvoudig met REMOTE_ADDR + trim + filter_var. Breid later uit met trustedProxies en parsing van DEFAULT_TRUSTED_HEADERS zonder je API te veranderen.
 */
?>



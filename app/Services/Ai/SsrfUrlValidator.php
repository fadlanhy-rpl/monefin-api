<?php

namespace App\Services\Ai;

class SsrfUrlValidator
{
    /**
     * Private & reserved IPv4 ranges in CIDR format
     */
    private const BLOCKED_IPV4_RANGES = [
        '0.0.0.0/8',          // Current network
        '10.0.0.0/8',         // Private-Use
        '127.0.0.0/8',        // Loopback
        '169.254.0.0/16',     // Link-Local (Cloud metadata)
        '172.16.0.0/12',      // Private-Use
        '192.0.0.0/24',       // IETF Protocol Assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // 6to4 Relay Anycast
        '192.168.0.0/16',     // Private-Use
        '198.18.0.0/15',      // Benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // Multicast
        '240.0.0.0/4',        // Reserved
        '255.255.255.255/32', // Broadcast
    ];

    /**
     * Validate an external AI endpoint URL against SSRF vulnerabilities.
     * Returns the normalized clean URL or throws an exception.
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(?string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('Format URL endpoint AI tidak valid.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $isLocalEnv = app()->environment('local');

        // Enforce HTTPS in production. In local development, http://localhost is acceptable.
        if ($scheme !== 'https') {
            if (!$isLocalEnv || ($scheme !== 'http')) {
                throw new \InvalidArgumentException('URL endpoint AI kustom wajib menggunakan protokol HTTPS yang aman (https://).');
            }
        }

        $host = strtolower($parts['host']);

        // In production, block localhost names
        if (!$isLocalEnv) {
            if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
                throw new \InvalidArgumentException('URL endpoint AI tidak boleh mengarah ke server lokal (localhost).');
            }
        }

        // Port checks: disallow known internal database and administration ports
        if (!empty($parts['port'])) {
            $blockedPorts = [21, 22, 23, 25, 53, 80, 110, 143, 465, 587, 993, 995, 3306, 5432, 6379, 11211, 27017];
            if (!$isLocalEnv && in_array((int) $parts['port'], $blockedPorts, true)) {
                throw new \InvalidArgumentException("Port {$parts['port']} tidak diizinkan untuk endpoint AI.");
            }
        }

        // DNS Resolution check (skip in local environment if host is localhost)
        if (!($isLocalEnv && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            $ips = @gethostbynamel($host);
            if ($ips === false || empty($ips)) {
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    $ips = [$host];
                } else {
                    throw new \InvalidArgumentException("Tidak dapat menyelesaikan nama host: {$host}");
                }
            }

            foreach ($ips as $ip) {
                if (self::isPrivateOrReservedIp($ip)) {
                    throw new \InvalidArgumentException("URL endpoint AI terdeteksi mengarah ke alamat IP internal/privat yang diblokir ({$ip}).");
                }
            }
        }

        return $url;
    }

    /**
     * Check if an IP address belongs to private, loopback, or reserved ranges.
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        // Native filter check for IPv4 and IPv6
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // Manual CIDR checks for IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                return true;
            }

            foreach (self::BLOCKED_IPV4_RANGES as $range) {
                [$subnet, $mask] = explode('/', $range);
                $subnetLong = ip2long($subnet);
                $maskBits = ~((1 << (32 - (int) $mask)) - 1);
                if (($ipLong & $maskBits) === ($subnetLong & $maskBits)) {
                    return true;
                }
            }
        }

        return false;
    }
}

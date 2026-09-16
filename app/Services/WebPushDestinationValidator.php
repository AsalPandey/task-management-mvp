<?php

namespace App\Services;

class WebPushDestinationValidator
{
    /**
     * Suffixes of reserved, local, or special-use hostnames.
     *
     * @var list<string>
     */
    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.lan',
        '.home',
        '.corp',
        '.arpa',
        '.invalid',
    ];

    /**
     * Optional custom DNS resolver callback for testing/mocking.
     *
     * @var (callable(string): array<string>)|null
     */
    protected $dnsResolver = null;

    public function setDnsResolver(?callable $resolver): void
    {
        $this->dnsResolver = $resolver;
    }

    /**
     * Validate and resolve an endpoint to its safe host, port, and public IP addresses.
     * Returns null if the destination is unsafe, malformed, or fails resolution.
     *
     * @return array{host: string, port: int, ips: list<string>}|null
     */
    public function resolveSafeDestination(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        // Scheme must be strictly HTTPS
        if (($parts['scheme'] ?? null) !== 'https') {
            return null;
        }

        // Disallow credentials in URL
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // Web Push (RFC 8030 / RFC 8291 / RFC 8292) endpoints operate over HTTPS.
        // Standard browser push services (FCM, Mozilla Autopush, Apple Web Push, WNS) use
        // standard HTTPS port 443. Arbitrary non-standard ports (e.g., 22, 25, 8080, 3306)
        // are rejected to prevent SSRF port-scanning and internal service probing.
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $host = strtolower(trim($host));

        // Reject localhost and local/reserved names
        if ($host === 'localhost' || $this->hasBlockedSuffix($host)) {
            return null;
        }

        // Reject IP literals directly (both IPv4 and bracketed IPv6)
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $strippedHost = trim($host, '[]');
        if (filter_var($strippedHost, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // Host must conform to standard FQDN hostname syntax
        $isTestHost = app()->environment('testing') && $this->isAllowedTestHost($host);
        if (! $isTestHost) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.[a-z]{2,63}$/i', $host) !== 1) {
                return null;
            }
        }

        // Resolve DNS and check each IP
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            // Fail closed if hostname cannot be resolved
            return null;
        }

        $safeIps = [];
        foreach ($ips as $ip) {
            if (! $this->isSafeIp($ip)) {
                return null;
            }
            $safeIps[] = $ip;
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? 443),
            'ips' => array_values(array_unique($safeIps)),
        ];
    }

    /**
     * Validate whether a given Web Push endpoint URL is safe to persist and connect to.
     */
    public function validateUrl(string $url): bool
    {
        return $this->resolveSafeDestination($url) !== null;
    }

    /**
     * Check if an IP address belongs to a public, routable address space.
     */
    public function isSafeIp(string $ip): bool
    {
        // Handle IPv4-mapped IPv6 (e.g. ::ffff:127.0.0.1 or ::ffff:7f00:1)
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        // Basic IP format validation
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Filter private and reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // IPv4 explicit checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Loopback (127.0.0.0/8)
            if (str_starts_with($ip, '127.')) {
                return false;
            }

            // Current network / Zero prefix (0.0.0.0/8)
            if (str_starts_with($ip, '0.')) {
                return false;
            }

            // Carrier-grade NAT (100.64.0.0/10)
            if (preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./', $ip) === 1) {
                return false;
            }

            // Link-local (169.254.0.0/16)
            if (str_starts_with($ip, '169.254.')) {
                return false;
            }

            // Multicast (224.0.0.0/4) and Reserved / Class E (240.0.0.0/4)
            $firstOctet = (int) explode('.', $ip)[0];
            if ($firstOctet >= 224) {
                return false;
            }

            // Broadcast
            if ($ip === '255.255.255.255') {
                return false;
            }
        }

        // IPv6 explicit checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = inet_pton($ip);
            if ($normalized === false) {
                return false;
            }

            // Loopback (::1) and Unspecified (::)
            if ($ip === '::1' || $ip === '::') {
                return false;
            }

            $firstByte = ord($normalized[0]);

            // Unique local unicast (fc00::/7)
            if (($firstByte & 0xFE) === 0xFC) {
                return false;
            }

            // Link-local unicast (fe80::/10)
            if ($firstByte === 0xFE && (ord($normalized[1]) & 0xC0) === 0x80) {
                return false;
            }

            // Multicast (ff00::/8)
            if ($firstByte === 0xFF) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve hostnames into a unique list of IP addresses.
     *
     * @return array<string>
     */
    public function resolveHostIps(string $host): array
    {
        if ($this->dnsResolver !== null) {
            $customIps = ($this->dnsResolver)($host);
            if (is_array($customIps)) {
                return array_values(array_unique($customIps));
            }
        }

        if (app()->environment('testing') && $this->isAllowedTestHost($host)) {
            return ['93.184.216.34']; // Deterministic public example IP
        }

        $ips = [];

        // IPv4 via dns_get_record
        $recordsA = @dns_get_record($host, DNS_A);
        if (is_array($recordsA)) {
            foreach ($recordsA as $rec) {
                if (! empty($rec['ip'])) {
                    $ips[] = $rec['ip'];
                }
            }
        }

        // Fallback IPv4 via gethostbynamel
        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = array_merge($ips, $resolved);
            }
        }

        // IPv6 via dns_get_record
        $recordsAaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($recordsAaaa)) {
            foreach ($recordsAaaa as $rec) {
                if (! empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function hasBlockedSuffix(string $host): bool
    {
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedTestHost(string $host): bool
    {
        $allowed = config('webpush.allowed_test_hosts', ['push.example.test']);
        if (! is_array($allowed)) {
            $allowed = ['push.example.test'];
        }

        return in_array(strtolower($host), array_map('strtolower', $allowed), true);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Str;

class UrlSafetyValidator
{
    /**
     * Block obviously unsafe URLs (SSRF / internal networks). Hostnames are
     * resolved and every returned A/AAAA record must be public (not RFC1918,
     * loopback, link-local, etc.).
     */
    public function assertPublicHttpUrl(string $url): void
    {
        $normalized = trim($url);
        if ($normalized === '') {
            throw new \InvalidArgumentException('URL is empty.');
        }

        $parts = parse_url($normalized);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('URL is malformed.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP and HTTPS URLs are allowed.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || Str::contains($host, '@')) {
            throw new \InvalidArgumentException('URL host is invalid.');
        }

        if ($this->allowLocalhostInDev() && $this->isLocalDevHost($host)) {
            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                throw new \InvalidArgumentException('That IP address is not allowed.');
            }

            return;
        }

        $hostAscii = $host;
        if (function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46')) {
            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false && $converted !== '') {
                $hostAscii = $converted;
            }
        }

        $resolved = $this->resolveAllIps((string) $hostAscii);
        if ($resolved === []) {
            throw new \InvalidArgumentException('That hostname could not be resolved.');
        }

        foreach ($resolved as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new \InvalidArgumentException('That hostname resolves to a blocked network.');
            }
        }
    }

    private function allowLocalhostInDev(): bool
    {
        return app()->environment('local');
    }

    private function isLocalDevHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    /**
     * @return list<string>
     */
    private function resolveAllIps(string $asciiHost): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $a = @dns_get_record($asciiHost, DNS_A) ?: [];
            $aaaa = @dns_get_record($asciiHost, DNS_AAAA) ?: [];
            foreach (array_merge($a, $aaaa) as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbyname($asciiHost);
            if (is_string($fallback) && $fallback !== $asciiHost) {
                $ips[] = $fallback;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if (Str::startsWith($lower, 'fc') || Str::startsWith($lower, 'fd')) {
                return true;
            }
            if (Str::startsWith($lower, 'fe80:')) {
                return true;
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return true;
    }
}

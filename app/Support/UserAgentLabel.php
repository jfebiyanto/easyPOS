<?php

namespace App\Support;

/**
 * Ringkasan User-Agent untuk audit sesi POS, mis. "Chrome 142 · Windows".
 */
class UserAgentLabel
{
    public static function describe(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Tidak diketahui';
        }

        $browser = match (true) {
            (bool) preg_match('/Edg(?:e|A|iOS)?\/(\d+)/', $userAgent, $m) => "Edge {$m[1]}",
            (bool) preg_match('/OPR\/(\d+)/', $userAgent, $m) => "Opera {$m[1]}",
            (bool) preg_match('/(?:Chrome|CriOS)\/(\d+)/', $userAgent, $m) => "Chrome {$m[1]}",
            (bool) preg_match('/(?:Firefox|FxiOS)\/(\d+)/', $userAgent, $m) => "Firefox {$m[1]}",
            (bool) preg_match('/Version\/(\d+).*Safari/', $userAgent, $m) => "Safari {$m[1]}",
            default => 'Browser lain',
        };

        $os = match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} · {$os}" : $browser;
    }
}

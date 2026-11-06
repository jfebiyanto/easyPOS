<?php

namespace Tests\Unit;

use App\Support\UserAgentLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentLabelTest extends TestCase
{
    #[DataProvider('agents')]
    public function test_ringkasan_browser_dan_sistem_operasi(string $userAgent, string $expected): void
    {
        $this->assertSame($expected, UserAgentLabel::describe($userAgent));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function agents(): array
    {
        return [
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'Chrome 142 · Windows'],
            'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'Edge 142 · Windows'],
            'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox 130 · Linux'],
            'safari mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari 17 · macOS'],
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome 141 · Android'],
            'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari 17 · iOS'],
            'tidak dikenal' => ['curl/8.0', 'Browser lain'],
        ];
    }

    public function test_user_agent_kosong_tidak_diketahui(): void
    {
        $this->assertSame('Unknown', UserAgentLabel::describe(null));
        $this->assertSame('Unknown', UserAgentLabel::describe(''));
    }
}

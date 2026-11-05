<?php

namespace Tests;

use App\Models\PosSession;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Klien tes berperilaku seperti satu browser yang menyimpan cookie identitas perangkat POS; tanpanya
        // setiap request dianggap perangkat baru dan ditolak oleh aturan satu sesi POS aktif per kasir.
        $this->withCredentials()->withUnencryptedCookie(PosSession::DEVICE_COOKIE, str_repeat('t', 40));
    }
}

<?php

namespace App\Models;

use App\Support\ActivityLogger;
use App\Support\UserAgentLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Sesi POS satu kasir. Hanya satu sesi aktif per kasir; sesi lain harus mengambil alih (atau menunggu
 * sesi lama kedaluwarsa karena tidak ada detak) dan semuanya tercatat untuk audit.
 */
class PosSession extends MasterModel
{
    /** Detik tanpa detak sebelum sesi dianggap mati (browser ditutup, komputer mati, jaringan putus). */
    public const STALE_AFTER_SECONDS = 120;

    public const ENDED_LOGOUT = 'logout';

    public const ENDED_TAKEN_OVER = 'taken_over';

    public const ENDED_EXPIRED = 'expired';

    public const ENDED_LABELS = [
        self::ENDED_LOGOUT => 'Logged out',
        self::ENDED_TAKEN_OVER => 'Taken over',
        self::ENDED_EXPIRED => 'Expired',
    ];

    protected $fillable = [
        'company_id', 'user_id', 'store_id', 'session_hash', 'ip_address', 'user_agent', 'browser',
        'started_at', 'last_seen_at', 'ended_at', 'ended_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /** Cookie identitas browser/perangkat untuk POS (acak, tahan lama, tidak ikut berubah saat login ulang). */
    public const DEVICE_COOKIE = 'pos_device';

    /**
     * Hash identitas browser/perangkat ini. Cookie dibuat saat pertama kali POS diakses; browser lain, mode
     * penyamaran, atau cookie yang dihapus dihitung sebagai perangkat baru.
     */
    public static function hashFor(Request $request): string
    {
        $token = $request->cookie(self::DEVICE_COOKIE);

        if (! is_string($token) || strlen($token) < 20) {
            $token = Str::random(40);
            $request->cookies->set(self::DEVICE_COOKIE, $token);
            Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $token, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return hash('sha256', $token);
    }

    /** Sesi yang belum berakhir milik kasir (maksimal satu). */
    public static function activeFor(User $user): ?self
    {
        return static::where('user_id', $user->id)->whereNull('ended_at')->latest('started_at')->first();
    }

    public function isStale(): bool
    {
        return $this->last_seen_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && ! $this->isStale();
    }

    public function statusLabel(): string
    {
        if ($this->ended_at !== null) {
            return 'Ended · '.(self::ENDED_LABELS[$this->ended_reason] ?? $this->ended_reason);
        }

        return $this->isStale() ? 'Inactive (no heartbeat)' : 'Active';
    }

    /** Mulai sesi baru untuk request ini. */
    public static function startFor(Request $request): self
    {
        $user = $request->user();

        return static::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'store_id' => $user->store_id,
            'session_hash' => static::hashFor($request),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'browser' => UserAgentLabel::describe($request->userAgent()),
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function end(string $reason): void
    {
        $this->forceFill(['ended_at' => now(), 'ended_reason' => $reason])->save();
    }

    /** Ringkasan untuk layar pemblokir dan audit. */
    public function summary(): array
    {
        return [
            'browser' => $this->browser,
            'ip' => $this->ip_address,
            'started_at' => $this->started_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
        ];
    }

    /**
     * Catat ke Log Aktivitas dengan browser dan IP sesi ini.
     */
    public function log(string $action, string $description, ?array $extra = null): void
    {
        ActivityLogger::log($action, $description, null, [
            'session_id' => $this->id,
            'browser' => $this->browser,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
        ] + ($extra ?? []));
    }
}

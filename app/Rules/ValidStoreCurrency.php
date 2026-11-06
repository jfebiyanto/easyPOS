<?php

namespace App\Rules;

use App\Models\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Mata uang gerai harus terhubung ke modul Mata Uang (tabel currencies).
 * - Gerai baru / pergantian mata uang: kode harus terdaftar & aktif.
 * - Gerai existing: nilai yang sudah tersimpan tetap diterima meski
 *   nonaktif atau belum terdaftar (data lama sebelum modul Mata Uang ada).
 */
class ValidStoreCurrency implements ValidationRule
{
    public function __construct(private readonly ?string $currentCode = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = strtoupper(trim((string) $value));
        $current = strtoupper(trim((string) $this->currentCode));

        $currency = Currency::query()->where('code', $code)->first();

        if (! $currency) {
            // Data lama sebelum modul Mata Uang ada — tetap dipertahankan.
            if ($current !== '' && $code === $current) {
                return;
            }

            $fail('The selected currency is not registered in the Currencies module.');

            return;
        }

        if (! $currency->is_active && $code !== $current) {
            $fail('The selected currency is inactive in the Currencies module.');
        }
    }
}

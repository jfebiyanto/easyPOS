<?php

namespace App\Support\Payment;

use App\Models\SalePayment;
use RuntimeException;

/**
 * Adapter Xendit (QRIS dinamis). Integrasi live menyusul setelah pendaftaran;
 * kredensial disimpan terenkripsi di company.payment_providers['xendit'].
 */
class XenditPaymentProvider implements PaymentProvider
{
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'xendit';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['secret_key'] ?? null);
    }

    public function createQrisCharge(SalePayment $payment): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Xendit is not configured (not registered).');
        }

        // Placeholder integrasi live: panggil POST /qr_codes (dynamic QRIS).
        // Diimplementasikan saat kredensial produksi tersedia.
        return [
            'provider' => 'xendit',
            'provider_ref' => null,
            'qr_string' => null,
            'status' => 'pending',
        ];
    }

    /**
     * Verifikasi header webhook Xendit (x-callback-token).
     *
     * @param  array<string,array<int,string>>  $headers
     */
    public function verifyWebhook(array $headers): bool
    {
        $token = $this->config['callback_token'] ?? null;

        if (blank($token)) {
            return false;
        }

        $received = $headers['x-callback-token'][0] ?? '';

        return hash_equals((string) $token, (string) $received);
    }
}

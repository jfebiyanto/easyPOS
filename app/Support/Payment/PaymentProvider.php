<?php

namespace App\Support\Payment;

use App\Models\SalePayment;

interface PaymentProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Buat tagihan QRIS dinamis untuk pembayaran. Mengembalikan
     * ['provider_ref' => ?string, 'qr_string' => ?string, 'status' => string].
     */
    public function createQrisCharge(SalePayment $payment): array;
}

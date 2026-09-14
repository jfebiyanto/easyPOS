<?php

namespace App\Support\Payment;

use App\Models\SalePayment;
use RuntimeException;

class NullPaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createQrisCharge(SalePayment $payment): array
    {
        throw new RuntimeException('Payment gateway belum dikonfigurasi.');
    }
}

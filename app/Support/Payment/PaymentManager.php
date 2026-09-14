<?php

namespace App\Support\Payment;

use App\Models\CompanyDetail;

class PaymentManager
{
    public static function for(?string $name, ?string $companyId = null): PaymentProvider
    {
        $companyId = $companyId ?? current_company_id();
        $configs = CompanyDetail::query()->find($companyId)?->payment_providers ?? [];

        return match ($name) {
            'xendit' => new XenditPaymentProvider($configs['xendit'] ?? []),
            default => new NullPaymentProvider(),
        };
    }
}

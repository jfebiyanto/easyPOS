<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Support\Sap\SapCustomerSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushCustomerToSap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $customerId) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        $company = $customer ? CompanyDetail::find($customer->company_id) : null;

        if (! $customer || ! $company?->sapIntegrationActive() || $customer->isFromSap()) {
            return;
        }

        (new SapCustomerSync($company))->push($customer);
    }
}

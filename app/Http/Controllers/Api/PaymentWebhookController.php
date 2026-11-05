<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalePayment;
use App\Support\Payment\PaymentManager;
use App\Support\Payment\XenditPaymentProvider;
use App\Support\Sap\SapSalePosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook payment gateway. Tidak memakai auth:sanctum; diverifikasi lewat
 * callback token provider. Siap untuk Xendit QRIS (live menyusul).
 */
class PaymentWebhookController extends Controller
{
    public function xendit(Request $request): JsonResponse
    {
        $provider = PaymentManager::for('xendit');

        if (! $provider instanceof XenditPaymentProvider || ! $provider->isConfigured()) {
            return response()->json(['message' => 'Payment provider belum dikonfigurasi.'], 503);
        }

        if (! $provider->verifyWebhook($request->headers->all())) {
            return response()->json(['message' => 'Token webhook tidak valid.'], 403);
        }

        $data = $request->validate([
            'external_id' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $payment = SalePayment::where('provider', 'xendit')
            ->where('provider_ref', $data['external_id'] ?? null)
            ->first();

        if ($payment) {
            $paid = in_array(strtoupper($data['status'] ?? ''), ['PAID', 'SUCCEEDED', 'SETTLED'], true);
            $payment->update([
                'status' => $paid ? 'confirmed' : 'pending',
                'confirmed_at' => $paid ? now() : null,
            ]);

            // Posting SAP menunggu pembayaran non-tunai terkonfirmasi; antrekan ulang setelah konfirmasi.
            if ($paid && $payment->sale) {
                SapSalePosting::queue($payment->sale);
            }
        }

        return response()->json(['message' => 'ok']);
    }
}

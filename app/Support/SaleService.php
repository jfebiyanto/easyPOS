<?php

namespace App\Support;

use App\Models\CompanyDetail;
use App\Models\Delivery;
use App\Models\NumberingConfig;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Models\User;
use App\Support\Sap\SapSalePosting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pembuatan transaksi penjualan POS: hitung pajak per baris (dibulatkan di
 * level dokumen), simpan snapshot harga, kurangi stok, dan catat pembayaran.
 */
class SaleService
{
    public static function create(array $data, User $cashier): Sale
    {
        $companyId = $cashier->company_id;
        $storeId = $data['store_id'];
        $clientUuid = $data['client_uuid'] ?? null;

        if ($clientUuid) {
            $existing = Sale::where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $existing->load('lines', 'payments', 'delivery');
            }
        }

        $company = CompanyDetail::with('taxGroup')->findOrFail($companyId);
        // PPN 0% tetap PPN (tercatat dengan kode Tax Group-nya), bukan PPN nonaktif.
        $taxEnabled = (bool) ($company->ppn_enabled ?? false);
        $rate = $taxEnabled ? (float) ($company->taxGroup?->rate ?? $company->ppn_rate ?? 0) : 0.0;
        $taxCode = $taxEnabled ? $company->taxGroup?->code : null;
        $inclusive = (bool) ($company->ppn_inclusive ?? false);

        $origin = $data['origin'] ?? 'online';
        $enforceStock = $origin !== 'offline';
        $soldAt = isset($data['sold_at']) ? Carbon::parse($data['sold_at']) : now();
        // Tanggal posting = tanggal bisnis yang belum di-EOD bila ada (juga dipakai sebagai posting date SAP).
        $businessDate = StoreClosure::postingDate(
            $storeId,
            $soldAt->copy()->setTimezone($company->timezone ?: 'Asia/Jakarta')->toDateString()
        );

        return DB::transaction(function () use ($data, $cashier, $company, $companyId, $storeId, $clientUuid, $rate, $taxCode, $inclusive, $taxEnabled, $origin, $enforceStock, $soldAt, $businessDate) {
            $subtotal = 0.0;
            $taxSum = 0.0;
            $discountTotal = 0.0;
            $reviewNeeded = false;
            $reviewReason = null;
            $prepared = [];

            foreach ($data['lines'] as $key => $line) {
                $product = Product::where('company_id', $companyId)->findOrFail($line['product_id']);
                $qty = (float) $line['qty'];
                $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== null
                    ? (float) $line['unit_price']
                    : PricingService::resolve($storeId, $product, $qty);

                $prepared[$key] = compact('product', 'qty', 'unitPrice');
            }

            $promotions = self::resolvePromotions($data['lines'], $prepared, $companyId, $soldAt, $origin);
            if ($promotions['review_reason']) {
                $reviewNeeded = true;
                $reviewReason = $promotions['review_reason'];
            }

            foreach ($prepared as $key => $p) {
                $promotion = $promotions['lines'][$key];
                $discount = $promotion['promotion_discount'];
                $base = max(0, round($p['unitPrice'] * $p['qty'] - $discount, 2));

                if ($taxEnabled) {
                    if ($inclusive) {
                        $tax = $base - ($base / (1 + $rate / 100));
                        $lineTotal = $base;
                    } else {
                        $tax = $base * $rate / 100;
                        $lineTotal = $base + $tax;
                    }
                } else {
                    $tax = 0.0;
                    $lineTotal = $base;
                }

                $subtotal += $base;
                $taxSum += $tax;
                $discountTotal += $discount;

                $prepared[$key] += compact('discount', 'promotion', 'tax', 'lineTotal');
            }

            $taxTotal = round($taxSum, 2);
            $shippingCost = (float) ($data['shipping_cost'] ?? 0);
            $total = round($subtotal + ($inclusive ? 0 : $taxTotal) + $shippingCost, 2);

            $paidTotal = round((float) collect($data['payments'] ?? [])->sum('amount'), 2);
            $change = round(max(0, $paidTotal - $total), 2);

            $sale = Sale::create([
                'company_id' => $companyId,
                'store_id' => $storeId,
                'number' => NumberingConfig::nextNumber('sales', null, $companyId),
                'client_uuid' => $clientUuid,
                'origin' => $origin,
                'sync_status' => 'synced',
                'business_date' => $businessDate,
                'type' => 'sale',
                'customer_id' => $data['customer_id'] ?? Store::whereKey($storeId)->value('default_customer_id'),
                'cashier_id' => $cashier->id,
                'status' => Sale::STATUS_COMPLETED,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'paid_total' => $paidTotal,
                'change_total' => $change,
                'currency_code' => $company->base_currency_code ?? 'IDR',
                'sold_at' => $soldAt,
            ]);

            foreach ($prepared as $p) {
                $sale->lines()->create([
                    'product_id' => $p['product']->id,
                    'sku_snapshot' => $p['product']->sku,
                    'name_snapshot' => $p['product']->name,
                    'unit_price' => $p['unitPrice'],
                    'qty' => $p['qty'],
                    'discount' => $p['discount'],
                    'promotion_id' => $p['promotion']['promotion_id'],
                    'promotion_name_snapshot' => $p['promotion']['promotion_name'],
                    'promotion_discount' => $p['promotion']['promotion_discount'],
                    'tax_amount' => round($p['tax'], 2),
                    'tax_group_code' => $taxCode,
                    'tax_rate' => $taxEnabled ? $rate : null,
                    'line_total' => round($p['lineTotal'], 2),
                ]);

                if ($p['product']->track_stock) {
                    if ($enforceStock) {
                        SaleStock::assertAvailable($storeId, $p['product']->id, -$p['qty']);
                    } elseif (! StockService::allowsNegative()
                        && (SaleStock::balance($storeId, $p['product']->id) - $p['qty']) < 0) {
                        $reviewNeeded = true;
                        $reviewReason = trim(($reviewReason ?? '')." Stok tidak mencukupi saat sinkron untuk {$p['product']->sku}.");
                    }

                    SaleStock::record($storeId, $p['product']->id, StockLedger::TYPE_SALE, -$p['qty'], [
                        'company_id' => $companyId,
                        'ref_type' => 'sale',
                        'ref_id' => $sale->id,
                        'created_by' => $cashier->id,
                    ]);
                }
            }

            if ($reviewNeeded) {
                $sale->update([
                    'stock_review_status' => 'pending',
                    'review_reason' => $reviewReason,
                ]);
            }

            foreach ($data['payments'] ?? [] as $pay) {
                $sale->payments()->create([
                    'payment_type_id' => $pay['payment_type_id'] ?? null,
                    'amount' => (float) $pay['amount'],
                    'status' => $pay['status'] ?? 'confirmed',
                    'provider' => $pay['provider'] ?? null,
                    'approval_code' => filled($pay['approval_code'] ?? null) ? trim((string) $pay['approval_code']) : null,
                ]);
            }

            if (! empty($data['delivery'])) {
                $d = $data['delivery'];
                Delivery::create([
                    'sale_id' => $sale->id,
                    'recipient_name' => $d['recipient_name'],
                    'phone' => $d['phone'] ?? null,
                    'address' => $d['address'],
                    'shipping_cost' => $shippingCost,
                    'status' => 'pending',
                    'note' => $d['note'] ?? null,
                ]);
            }

            LoyaltyService::earnForSale($sale);

            // Posting ke SAP B1 (A/R Invoice + Incoming Payment) setelah transaksi tersimpan.
            SapSalePosting::queue($sale);

            return $sale->load('lines', 'payments', 'delivery', 'customer');
        });
    }

    /**
     * Tentukan promosi tiap baris. Online: hasil server yang dipakai, dan bila kasir mengirim hasil
     * promosinya sendiri yang berbeda, transaksi ditolak (409) beserta hasil server agar keranjang
     * dihitung ulang. Offline: hasil kasir yang dipakai (itu yang dibayar pelanggan); bila berbeda
     * dengan server, transaksi masuk antrean review.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  array<int|string, array{product: Product, qty: float, unitPrice: float}>  $prepared
     * @return array{
     *     lines: array<int|string, array{promotion_id: ?string, promotion_name: ?string, promotion_discount: float}>,
     *     review_reason: ?string
     * }
     */
    private static function resolvePromotions(array $lines, array $prepared, string $companyId, Carbon $soldAt, string $origin): array
    {
        $calculated = PromotionService::apply(
            array_map(fn (array $p) => ['product_id' => $p['product']->id, 'qty' => $p['qty'], 'unit_price' => $p['unitPrice']], $prepared),
            PromotionService::activeFor($companyId, $soldAt),
        );

        $clientSentPromotions = collect($lines)->contains(fn (array $line) => array_key_exists('promotion_id', $line));

        $claimedNames = Promotion::where('company_id', $companyId)
            ->whereIn('id', collect($lines)->pluck('promotion_id')->filter()->unique())
            ->pluck('name', 'id');

        $claimed = [];
        $mismatched = [];
        foreach ($lines as $key => $line) {
            $promotionId = $line['promotion_id'] ?? null;
            $claimed[$key] = [
                'promotion_id' => $promotionId,
                'promotion_name' => $promotionId ? $claimedNames[$promotionId] ?? null : null,
                'promotion_discount' => round((float) ($line['discount'] ?? 0), 2),
            ];

            $server = $calculated['lines'][$key];
            if ($claimed[$key]['promotion_id'] !== $server['promotion_id']
                || abs($claimed[$key]['promotion_discount'] - $server['promotion_discount']) > 0.009) {
                $mismatched[] = $prepared[$key]['product']->sku;
            }
        }

        if ($origin === 'offline') {
            return [
                'lines' => $claimed,
                'review_reason' => $mismatched === [] ? null : 'Promosi berbeda dengan perhitungan server untuk '.implode(', ', $mismatched).'.',
            ];
        }

        if ($clientSentPromotions && $mismatched !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Promosi berubah. Keranjang dihitung ulang, periksa total sebelum membayar.',
                'lines' => $calculated['lines'],
                'bonus_hints' => $calculated['bonus_hints'],
            ], 409));
        }

        return ['lines' => $calculated['lines'], 'review_reason' => null];
    }

    /**
     * Void transaksi: kembalikan stok, tandai void.
     */
    public static function void(Sale $sale, User $user, string $reason): Sale
    {
        abort_if($sale->isVoid(), 422, 'Transaksi sudah void.');
        // Pembatalan dokumen SAP B1 belum didukung: stok easyPOS akan berselisih dengan SAP.
        abort_if($sale->sap_invoice_doc_entry !== null, 422, 'Transaksi sudah diposting ke SAP B1 dan tidak dapat di-void dari easyPOS.');

        return DB::transaction(function () use ($sale, $user, $reason) {
            $usesWarehouse = SaleStock::usesWarehouseLedger($sale->id);

            foreach ($sale->lines as $line) {
                if ($line->product_id && $usesWarehouse) {
                    SaleStock::record($sale->store_id, $line->product_id, StockLedger::TYPE_SALE_RETURN, (float) $line->qty, [
                        'company_id' => $sale->company_id,
                        'ref_type' => 'sale_void',
                        'ref_id' => $sale->id,
                        'note' => $reason,
                        'created_by' => $user->id,
                    ]);
                } elseif ($line->product_id) {
                    StockService::record($sale->store_id, $line->product_id, StockLedger::TYPE_SALE_RETURN, (float) $line->qty, [
                        'company_id' => $sale->company_id,
                        'ref_type' => 'sale_void',
                        'ref_id' => $sale->id,
                        'note' => $reason,
                        'created_by' => $user->id,
                    ]);
                }
            }

            $sale->update([
                'status' => Sale::STATUS_VOID,
                'voided_at' => now(),
                'void_reason' => $reason,
                // Belum terkirim ke SAP: posting dibatalkan.
                'sap_post_status' => null,
                'sap_post_error' => null,
            ]);

            LoyaltyService::reverseForVoid($sale);

            return $sale->fresh(['lines', 'payments', 'delivery']);
        });
    }
}

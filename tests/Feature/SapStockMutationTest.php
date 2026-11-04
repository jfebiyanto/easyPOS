<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SapSyncRun;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\Sap\SapStockMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SapStockMutationTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Warehouse $retail;

    private Warehouse $central;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'sap_enabled' => true,
            'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);
        $this->company->forceFill(['sap_initial_imported_at' => now()->subDay(), 'sap_last_synced_at' => now()])->save();

        $this->retail = Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'WH-01']);
        $this->central = Warehouse::factory()->nonRetail()->create(['company_id' => $this->company->id, 'code' => 'DC']);

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->product = Product::create([
            'company_id' => $this->company->id,
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'sku' => 'ITM-001',
            'name' => 'Mie Instan',
            'base_price' => 3500,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{rows: array<int, array<string, mixed>>, hasMore?: bool}>  $pages  halaman hasil List berurutan
     * @param  ?string  $storedSql  teks query yang sudah ada di SAP; null = belum dibuat (404)
     */
    private function fakeSap(array $pages, ?string $storedSql = SapStockMutationService::QUERY_SQL): void
    {
        Http::swap(new HttpFactory);

        Http::fake(function (Request $request) use (&$pages, $storedSql) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/Login') => Http::response(['SessionId' => 'abc']),
                str_ends_with($url, '/Logout') => Http::response('', 204),
                str_ends_with($url, '/List') => Http::response(
                    ($page = array_shift($pages) ?? ['rows' => []]) && ($page['hasMore'] ?? false)
                        ? ['value' => $page['rows'], 'odata.nextLink' => 'next']
                        : ['value' => $page['rows']]
                ),
                $request->method() === 'GET' && $storedSql === null => Http::response(['error' => ['message' => ['value' => 'No matching records found']]], 404),
                $request->method() === 'GET' => Http::response(['SqlCode' => SapStockMutationService::QUERY_CODE, 'SqlText' => $storedSql]),
                default => Http::response([], $request->method() === 'POST' ? 201 : 204),
            };
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $seq, int $transType, string $warehouse, float $in, float $out, string $item = 'ITM-001', string $docNum = '100'): array
    {
        return [
            'TransSeq' => $seq, 'TransType' => $transType, 'CreatedBy' => 7, 'BASE_REF' => $docNum, 'DocLineNum' => 0,
            'ItemCode' => $item, 'LocCode' => $warehouse, 'InQty' => $in, 'OutQty' => $out,
            'DocDate' => '20261102',
        ];
    }

    private function onHand(Warehouse $warehouse): string
    {
        return WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $this->product->id)->value('on_hand');
    }

    public function test_mutasi_sap_disalin_ke_buku_besar_dan_saldo_dibangun_ulang_dari_mutasi(): void
    {
        // Saldo lama hasil timpa OITW dibuang pada sinkronisasi mutasi pertama.
        WarehouseStock::create(['company_id' => $this->company->id, 'warehouse_id' => $this->retail->id, 'product_id' => $this->product->id, 'on_hand' => 25]);
        $this->fakeSap([['rows' => [
            $this->row(1, 20, 'WH-01', 200, 0, docNum: '2600001'),
            $this->row(2, 60, 'WH-01', 0, 2),
            $this->row(3, 67, 'WH-01', 0, 5),
            $this->row(4, 67, 'DC', 5, 0),
        ]]], storedSql: null);

        $this->artisan('sap:sync-stock')->assertSuccessful();

        $this->assertSame('193.000', $this->onHand($this->retail));
        $this->assertSame('5.000', $this->onHand($this->central));
        $this->assertSame(
            [WarehouseStockLedger::TYPE_PURCHASE, WarehouseStockLedger::TYPE_GOODS_ISSUE, WarehouseStockLedger::TYPE_TRANSFER_OUT, WarehouseStockLedger::TYPE_TRANSFER_IN],
            WarehouseStockLedger::orderBy('sap_trans_seq')->pluck('type')->all()
        );

        $purchase = WarehouseStockLedger::where('sap_trans_seq', 1)->firstOrFail();
        $this->assertSame('200.000', $purchase->balance_after);
        $this->assertSame('2600001', $purchase->sap_doc_num);
        $this->assertSame(20, $purchase->sap_trans_type);
        $this->assertSame('2026-11-02', $purchase->doc_date->toDateString());
        $this->assertNull($purchase->unit_cost);

        $this->assertNotNull($this->company->fresh()->sap_stock_synced_at);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/SQLQueries')
            && $request['SqlCode'] === SapStockMutationService::QUERY_CODE);
    }

    public function test_sinkronisasi_ulang_tidak_menggandakan_mutasi_dan_mengambil_baris_yang_terlambat(): void
    {
        $this->fakeSap([['rows' => [$this->row(1, 20, 'WH-01', 10, 0), $this->row(3, 60, 'WH-01', 0, 1)]]]);
        $this->artisan('sap:sync-stock')->assertSuccessful();

        // TransSeq 2 baru ter-commit setelah 3 terbaca; pembacaan ulang menemukannya.
        $this->fakeSap([['rows' => [
            $this->row(1, 20, 'WH-01', 10, 0),
            $this->row(2, 60, 'WH-01', 0, 4),
            $this->row(3, 60, 'WH-01', 0, 1),
            $this->row(4, 59, 'WH-01', 2, 0),
        ]]]);
        $this->artisan('sap:sync-stock')->assertSuccessful();

        $this->assertSame(4, WarehouseStockLedger::count());
        $this->assertSame('7.000', $this->onHand($this->retail));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/List') && $request['ParamList'] === 'lastTransSeq=0');
    }

    public function test_halaman_berikutnya_diminta_dari_transseq_terakhir_dan_query_lama_diperbarui(): void
    {
        $this->fakeSap([
            ['rows' => [$this->row(1, 20, 'WH-01', 10, 0), $this->row(2, 60, 'WH-01', 0, 1)], 'hasMore' => true],
            ['rows' => [$this->row(3, 60, 'WH-01', 0, 1)]],
        ], storedSql: 'SELECT 1');

        $this->artisan('sap:sync-stock')->assertSuccessful();

        $this->assertSame(3, WarehouseStockLedger::count());
        $this->assertSame('8.000', $this->onHand($this->retail));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/List') && $request['ParamList'] === 'lastTransSeq=2');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['SqlText'] === SapStockMutationService::QUERY_SQL);
    }

    public function test_query_yang_diformat_ulang_oleh_sap_tidak_diperbarui_lagi(): void
    {
        // SAP menyimpan teks query dengan format sendiri; PATCH berulang membuat eksekusi berikutnya macet.
        $reformatted = str_replace(['"OIVL"', ' FROM '], ['OIVL', "\n  FROM "], SapStockMutationService::QUERY_SQL);
        $this->fakeSap([['rows' => [$this->row(1, 20, 'WH-01', 10, 0)]]], storedSql: $reformatted);

        $this->artisan('sap:sync-stock')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
        $this->assertSame(1, WarehouseStockLedger::count());
    }

    public function test_mutasi_berhenti_di_item_yang_belum_ada_dan_memicu_sinkronisasi_master(): void
    {
        Queue::fake();
        $rows = [['rows' => [
            $this->row(1, 20, 'WH-01', 10, 0),
            $this->row(2, 20, 'WH-01', 5, 0, item: 'ITM-BARU'),
            $this->row(3, 60, 'WH-01', 0, 1),
        ]]];

        $this->fakeSap($rows);
        $this->artisan('sap:sync-stock')->assertSuccessful();

        // Baris sesudah item yang belum ada tidak dilewati, tetapi menunggu.
        $this->assertSame([1], WarehouseStockLedger::pluck('sap_trans_seq')->all());
        $this->assertSame('10.000', $this->onHand($this->retail));
        $this->assertStringContainsString('ITM-BARU', $this->company->fresh()->sap_stock_sync_error);
        $this->assertSame(1, SapSyncRun::where('mode', SapSyncRun::MODE_DELTA)->where('trigger', 'stock')->count());

        // Run master masih antre: mutasi tidak disalin dua kali oleh perintah per menit.
        $this->fakeSap($rows);
        $this->artisan('sap:sync-stock')->assertSuccessful();
        Http::assertNothingSent();

        // Run master selesai tetapi item belum juga ada: tidak memicu run baru dalam 10 menit.
        SapSyncRun::query()->update(['status' => SapSyncRun::STATUS_SUCCESS]);
        $this->fakeSap($rows);
        $this->artisan('sap:sync-stock')->assertSuccessful();
        $this->assertSame(1, SapSyncRun::count());
    }

    public function test_perintah_menunggu_impor_awal(): void
    {
        $this->company->forceFill(['sap_initial_imported_at' => null])->save();
        $this->fakeSap([['rows' => [$this->row(1, 20, 'WH-01', 10, 0)]]]);

        $this->artisan('sap:sync-stock')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, WarehouseStockLedger::count());
    }
}

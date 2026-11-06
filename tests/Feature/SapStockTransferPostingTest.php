<?php

namespace Tests\Feature;

use App\Jobs\PostStockTransferToSap;
use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\Sap\SapStockMutationService;
use App\Support\Sap\SapStockTransferPosting;
use App\Support\StockTransferService;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SapStockTransferPostingTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Warehouse $source;

    private Warehouse $transit;

    private Warehouse $destination;

    private Store $fromStore;

    private Store $toStore;

    private User $owner;

    private Product $product;

    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'timezone' => 'Asia/Jakarta',
            'allow_negative_stock' => false,
            'sap_enabled' => true,
            'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);

        $this->transit = Warehouse::factory()->transit()->create(['company_id' => $this->company->id, 'code' => 'G.001.IT']);
        $this->source = Warehouse::factory()->create([
            'company_id' => $this->company->id, 'code' => 'G.001.MN', 'transit_warehouse_code' => 'G.001.IT',
        ]);
        $this->destination = Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'G.002.MN']);

        $this->fromStore = Store::create([
            'company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Store 1', 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $this->source->id,
        ]);
        $this->toStore = Store::create([
            'company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Store 2', 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $this->destination->id,
        ]);

        $this->owner = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->fromStore->id, 'name' => 'Owner',
            'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->product = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => 'INDF-0001', 'name' => 'Indomie', 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);
        $this->otherProduct = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => 'INDF-0002', 'name' => 'Indomie Goreng', 'base_price' => 4500, 'track_stock' => true, 'is_active' => true,
        ]);

        WarehouseStockService::record($this->source, $this->product->id, WarehouseStockLedger::TYPE_OPENING, 10, ['created_by' => null]);
        WarehouseStockService::record($this->source, $this->otherProduct->id, WarehouseStockLedger::TYPE_OPENING, 10, ['created_by' => null]);
    }

    /**
     * Service Layer palsu: dokumen StockTransfers baru diberi DocEntry 31, 32, ... dan DocNum 900001, ...
     *
     * @param  array{existing?: array<string, array{DocEntry: int, DocNum: int}>, error?: string}  $options  dokumen yang sudah ada per U_SOL_TRKY; pesan error SAP saat membuat
     */
    private function fakeSap(array $options = []): void
    {
        Http::swap(new HttpFactory);
        $next = 0;

        Http::fake(function (Request $request) use ($options, &$next) {
            $url = $request->url();
            $get = $request->method() === 'GET';

            return match (true) {
                str_ends_with($url, '/Login') => Http::response(['SessionId' => 'abc']),
                str_ends_with($url, '/Logout') => Http::response('', 204),
                $get && str_contains($url, '/StockTransfers') => Http::response(['value' => array_values(array_filter(
                    $options['existing'] ?? [],
                    fn (string $trackingKey) => str_contains(urldecode($url), "U_SOL_TRKY eq '{$trackingKey}'"),
                    ARRAY_FILTER_USE_KEY,
                ))]),
                str_ends_with($url, '/StockTransfers') && isset($options['error']) => Http::response(['error' => ['code' => -10, 'message' => ['lang' => 'en-us', 'value' => $options['error']]]], 400),
                str_ends_with($url, '/StockTransfers') => Http::response(['DocEntry' => 31 + $next, 'DocNum' => 900001 + $next++], 201),
                default => Http::response('', 204),
            };
        });
    }

    /**
     * Service Layer palsu untuk sinkronisasi mutasi stok: List mengembalikan baris OIVL yang diberikan.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeOivl(array $rows): void
    {
        Http::swap(new HttpFactory);

        Http::fake(function (Request $request) use (&$rows) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/List') => Http::response(['value' => array_splice($rows, 0)]),
                $request->method() === 'GET' && str_contains($url, 'SQLQueries') => Http::response(['SqlText' => SapStockMutationService::QUERY_SQL]),
                default => Http::response(['SessionId' => 'abc']),
            };
        });
        $this->company->forceFill(['sap_initial_imported_at' => now(), 'sap_stock_synced_at' => now()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function oivlRow(int $seq, int $createdBy, string $warehouse, float $in, float $out, string $item = 'INDF-0001'): array
    {
        return [
            'TransSeq' => $seq, 'TransType' => 67, 'CreatedBy' => $createdBy, 'BASE_REF' => (string) (899970 + $createdBy), 'DocLineNum' => 0,
            'ItemCode' => $item, 'LocCode' => $warehouse, 'InQty' => $in, 'OutQty' => $out, 'DocDate' => '20261106',
        ];
    }

    /**
     * @param  array<int, array{0: Product, 1: float}>  $lines
     */
    private function postShipment(array $lines, ?string $note = null): StockTransferShipment
    {
        $shipment = StockTransferShipment::create([
            'company_id' => $this->company->id,
            'from_store_id' => $this->fromStore->id,
            'to_store_id' => $this->toStore->id,
            'status' => StockTransferShipment::STATUS_DRAFT,
            'note' => $note,
            'created_by' => $this->owner->id,
        ]);

        foreach ($lines as [$product, $qty]) {
            StockTransferShipmentLine::create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'qty' => $qty, 'qty_received' => 0]);
        }

        (new StockTransferService)->post($shipment, $this->owner);

        return $shipment->fresh();
    }

    private function receiveAll(StockTransferShipment $shipment): StockTransferReceipt
    {
        $quantities = $shipment->lines()->get()->mapWithKeys(fn (StockTransferShipmentLine $line) => [$line->id => $line->remaining()])->all();

        return (new StockTransferService)->receive($shipment, $quantities, $this->owner);
    }

    private function runJob(StockTransferShipment|StockTransferReceipt $doc): void
    {
        (new PostStockTransferToSap($doc::class, $doc->id))->handle();
    }

    private function onHand(Warehouse $warehouse, ?Product $product = null): string
    {
        return WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', ($product ?? $this->product)->id)->value('on_hand');
    }

    private function assertNoStockTransferCreated(): void
    {
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers'));
    }

    public function test_shipment_diposting_dengan_payload_benar(): void
    {
        $this->fakeSap();

        $shipment = $this->postShipment([[$this->product, 5], [$this->otherProduct, 2.5]]);

        $this->assertSame(StockTransferShipment::SAP_POSTED, $shipment->sap_post_status);
        $this->assertSame([31, '900001'], [(int) $shipment->sap_doc_entry, (string) $shipment->sap_doc_num]);
        $this->assertNotNull($shipment->sap_posted_at);
        $this->assertNull($shipment->sap_post_error);

        $bodies = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers'))
            ->map(fn (array $pair) => $pair[0]->data());
        $this->assertCount(1, $bodies);
        $this->assertEquals([
            'U_SOL_FRST' => 'Y',
            'U_SOL_TRKY' => $shipment->id,
            'U_SOL_STID' => $this->fromStore->id,
            'FromWarehouse' => 'G.001.MN',
            'ToWarehouse' => 'G.001.IT',
            'DocDate' => $shipment->posted_at->copy()->setTimezone('Asia/Jakarta')->toDateString(),
            'JournalMemo' => $shipment->number,
            'StockTransferLines' => [
                ['ItemCode' => 'INDF-0001', 'Quantity' => 5.0, 'FromWarehouseCode' => 'G.001.MN', 'WarehouseCode' => 'G.001.IT'],
                ['ItemCode' => 'INDF-0002', 'Quantity' => 2.5, 'FromWarehouseCode' => 'G.001.MN', 'WarehouseCode' => 'G.001.IT'],
            ],
        ], $bodies->first());
    }

    public function test_notes_shipment_mengisi_comments_sap(): void
    {
        $this->fakeSap();

        $shipment = $this->postShipment([[$this->product, 5]], 'Barang rusak, retur ke gudang pusat');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers')
            && $request['Comments'] === 'Barang rusak, retur ke gudang pusat'
            && $request['JournalMemo'] === $shipment->number);
    }

    public function test_notes_kosong_tidak_mengirim_comments_dan_nomor_tetap_di_journal_memo(): void
    {
        $this->fakeSap();

        $shipment = $this->postShipment([[$this->product, 5]], '   ');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers')
            && ! isset($request['Comments']) && $request['JournalMemo'] === $shipment->number);
    }

    public function test_notes_receipt_mengisi_comments_sap(): void
    {
        $this->fakeSap();
        $shipment = $this->postShipment([[$this->product, 5]]);

        (new StockTransferService)->receive($shipment, [$shipment->lines()->value('id') => 5], $this->owner, 'Diterima lengkap oleh Budi');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers')
            && $request['FromWarehouse'] === 'G.001.IT' && $request['Comments'] === 'Diterima lengkap oleh Budi');
    }

    public function test_receipt_memakai_in_transit_ke_warehouse_penerima(): void
    {
        $this->fakeSap();
        $shipment = $this->postShipment([[$this->product, 5]]);

        $receipt = (new StockTransferService)->receive($shipment, [$shipment->lines()->value('id') => 3], $this->owner)->fresh();

        $this->assertSame(StockTransferReceipt::SAP_POSTED, $receipt->sap_post_status);
        $this->assertSame([32, '900002'], [(int) $receipt->sap_doc_entry, (string) $receipt->sap_doc_num]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers')
            && $request['U_SOL_TRKY'] === $receipt->id
            && $request['U_SOL_STID'] === $this->toStore->id
            && $request['FromWarehouse'] === 'G.001.IT'
            && $request['ToWarehouse'] === 'G.002.MN'
            && ! isset($request['Comments']) && $request['JournalMemo'] === $receipt->number
            && $request['StockTransferLines'] == [['ItemCode' => 'INDF-0001', 'Quantity' => 3.0, 'FromWarehouseCode' => 'G.001.IT', 'WarehouseCode' => 'G.002.MN']]);
    }

    public function test_kirim_ulang_idempoten_memakai_u_sol_trky(): void
    {
        Queue::fake();
        $shipment = $this->postShipment([[$this->product, 5]]);
        $shipment->forceFill(['sap_post_status' => StockTransferShipment::SAP_FAILED, 'sap_post_error' => 'Timeout'])->save();

        // Dokumen sudah dibuat di SAP pada percobaan sebelumnya (respons hilang).
        $this->fakeSap(['existing' => [$shipment->id => ['DocEntry' => 55, 'DocNum' => 900055]]]);
        SapStockTransferPosting::queue($shipment);

        $shipment->refresh();
        $this->assertSame(StockTransferShipment::SAP_PENDING, $shipment->sap_post_status);
        $this->assertNull($shipment->sap_post_error);
        Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentClass === StockTransferShipment::class && $job->documentId === $shipment->id);

        $this->runJob($shipment);

        $shipment->refresh();
        $this->assertSame(StockTransferShipment::SAP_POSTED, $shipment->sap_post_status);
        $this->assertSame([55, '900055'], [(int) $shipment->sap_doc_entry, (string) $shipment->sap_doc_num]);
        $this->assertNoStockTransferCreated();
    }

    public function test_kegagalan_sap_menandai_failed_dengan_pesan(): void
    {
        $this->fakeSap(['error' => 'Quantity falls into negative inventory '.str_repeat('x', 600)]);

        $shipment = $this->postShipment([[$this->product, 5]]);

        $this->assertSame(StockTransferShipment::SAP_FAILED, $shipment->sap_post_status);
        $this->assertStringContainsString('Quantity falls into negative inventory', $shipment->sap_post_error);
        $this->assertLessThanOrEqual(500, mb_strlen($shipment->sap_post_error));
        $this->assertNull($shipment->sap_doc_entry);
        $this->assertSame(StockTransferShipment::STATUS_POSTED, $shipment->status);
    }

    public function test_receipt_menunggu_shipment_terposting(): void
    {
        Queue::fake();
        $shipment = $this->postShipment([[$this->product, 5]]);
        $receipt = $this->receiveAll($shipment);
        $this->fakeSap();

        $this->runJob($receipt);

        $this->assertSame(StockTransferReceipt::SAP_PENDING, $receipt->fresh()->sap_post_status);
        Http::assertNothingSent();

        $this->runJob($shipment);

        $this->assertSame(StockTransferShipment::SAP_POSTED, $shipment->fresh()->sap_post_status);
        Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentClass === StockTransferReceipt::class && $job->documentId === $receipt->id);

        $this->runJob($receipt);

        $receipt->refresh();
        $this->assertSame(StockTransferReceipt::SAP_POSTED, $receipt->sap_post_status);
        $this->assertSame(32, (int) $receipt->sap_doc_entry);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/StockTransfers') && $request['U_SOL_TRKY'] === $receipt->id);
    }

    public function test_shipment_gagal_receipt_menunggu_lalu_kirim_ulang_shipment_mengantrekan_receipt(): void
    {
        $this->fakeSap(['error' => 'Timeout']);
        $shipment = $this->postShipment([[$this->product, 5]]);
        $this->assertSame(StockTransferShipment::SAP_FAILED, $shipment->sap_post_status);

        $receipt = $this->receiveAll($shipment);
        $this->assertSame(StockTransferReceipt::SAP_PENDING, $receipt->fresh()->sap_post_status);

        Queue::fake();
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.retry', $shipment))->assertSessionHas('success');
        Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentId === $shipment->id);

        $this->runJob($shipment);

        $this->assertSame(StockTransferShipment::SAP_POSTED, $shipment->fresh()->sap_post_status);
        Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentId === $receipt->id);

        $this->runJob($receipt);

        $this->assertSame(StockTransferReceipt::SAP_POSTED, $receipt->fresh()->sap_post_status);
    }

    public function test_job_tidak_berjalan_tumpang_tindih_per_dokumen(): void
    {
        $job = new PostStockTransferToSap(StockTransferShipment::class, 'abc-123');
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(StockTransferShipment::class.':abc-123', $middleware[0]->key);
        $this->assertSame(30, $middleware[0]->releaseAfter);
        $this->assertSame(300, $middleware[0]->expiresAfter);
        $this->assertSame(1, $job->maxExceptions);
    }

    public function test_receipt_tanpa_status_sap_diantrekan_setelah_shipment_terposting(): void
    {
        Queue::fake();
        $shipment = $this->postShipment([[$this->product, 5]]);

        // Integrasi sempat mati saat barang diterima: Receipt tidak diantrekan (status kosong).
        $this->company->update(['sap_enabled' => false]);
        $receipt = $this->receiveAll($shipment);
        $this->assertNull($receipt->sap_post_status);
        $this->company->update(['sap_enabled' => true]);

        $this->fakeSap();
        $this->runJob($shipment);

        $this->assertSame(StockTransferReceipt::SAP_PENDING, $receipt->fresh()->sap_post_status);
        Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentId === $receipt->id);
    }

    public function test_oivl_transtype_67_menaut_bukan_menggandakan(): void
    {
        $this->fakeSap();
        $shipment = $this->postShipment([[$this->product, 5]]);
        $receipt = $this->receiveAll($shipment)->fresh();
        $this->assertSame([31, 32], [(int) $shipment->sap_doc_entry, (int) $receipt->sap_doc_entry]);

        $ledgerCount = WarehouseStockLedger::count();
        $balances = [$this->onHand($this->source), $this->onHand($this->transit), $this->onHand($this->destination)];

        $this->fakeOivl([
            $this->oivlRow(1, 31, 'G.001.MN', 0, 5),
            $this->oivlRow(2, 31, 'G.001.IT', 5, 0),
            $this->oivlRow(3, 32, 'G.001.IT', 0, 5),
            $this->oivlRow(4, 32, 'G.002.MN', 5, 0),
        ]);
        $this->artisan('sap:sync-stock')->assertSuccessful();

        $this->assertSame($ledgerCount, WarehouseStockLedger::count());
        $this->assertSame($balances, [$this->onHand($this->source), $this->onHand($this->transit), $this->onHand($this->destination)]);
        $this->assertSame([[$this->source->id, -5.0, 1], [$this->transit->id, 5.0, 2]], $this->linkedRows(StockTransferService::REF_SHIPMENT, $shipment->id));
        $this->assertSame([[$this->transit->id, -5.0, 3], [$this->destination->id, 5.0, 4]], $this->linkedRows(StockTransferService::REF_RECEIPT, $receipt->id));

        $linked = WarehouseStockLedger::where('sap_trans_seq', 1)->sole();
        $this->assertSame([67, 31, '900001'], [$linked->sap_trans_type, $linked->sap_doc_entry, $linked->sap_doc_num]);
    }

    public function test_oivl_transfer_dari_sap_tanpa_dokumen_easypos_tetap_disalin(): void
    {
        $this->fakeSap();
        $shipment = $this->postShipment([[$this->product, 5]]);
        $ledgerCount = WarehouseStockLedger::count();

        // Stock Transfer dibuat langsung di SAP (DocEntry 77) dengan gudang, item, dan qty yang sama.
        $this->fakeOivl([
            $this->oivlRow(1, 77, 'G.001.MN', 0, 5),
            $this->oivlRow(2, 77, 'G.001.IT', 5, 0),
        ]);
        $this->artisan('sap:sync-stock')->assertSuccessful();

        $this->assertSame($ledgerCount + 2, WarehouseStockLedger::count());
        $this->assertSame('0.000', $this->onHand($this->source));
        $this->assertSame('10.000', $this->onHand($this->transit));
        $this->assertSame([[$this->source->id, -5.0, null], [$this->transit->id, 5.0, null]], $this->linkedRows(StockTransferService::REF_SHIPMENT, $shipment->id));
        $this->assertSame(
            [WarehouseStockLedger::TYPE_TRANSFER_OUT, WarehouseStockLedger::TYPE_TRANSFER_IN],
            WarehouseStockLedger::whereNotNull('sap_trans_seq')->orderBy('sap_trans_seq')->pluck('type')->all()
        );
    }

    /**
     * @return list<array{0: string, 1: float, 2: ?int}> [warehouse, qty, sap_trans_seq] urut qty
     */
    private function linkedRows(string $refType, string $refId): array
    {
        return WarehouseStockLedger::where('ref_type', $refType)->where('ref_id', $refId)->orderBy('qty')->get()
            ->map(fn (WarehouseStockLedger $row) => [$row->warehouse_id, (float) $row->qty, $row->sap_trans_seq === null ? null : (int) $row->sap_trans_seq])
            ->all();
    }
}

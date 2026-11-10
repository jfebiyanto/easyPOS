<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\CreditCard;
use App\Models\PaymentType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentTypeTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $admin;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);

        $this->storeA = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'address' => 'Jl. Testing No. 1',
            'pic_name' => 'PIC Satu',
            'pic_phone' => '081200000001',
            'ppn_enabled' => false,
            'ppn_rate' => 11,
            'currency' => 'IDR',
        ]);

        $this->storeB = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR02',
            'name' => 'Gerai Dua',
            'address' => 'Jl. Testing No. 2',
            'pic_name' => 'PIC Dua',
            'pic_phone' => '081200000002',
            'ppn_enabled' => false,
            'ppn_rate' => 11,
            'currency' => 'IDR',
        ]);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->storeA->id,
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'phone' => '081200000000',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CASH',
            'kind' => 'cash',
            'description' => 'Pembayaran tunai',
            'order_number' => 1,
            'count_type' => 'summary',
            'store_ids' => [$this->storeA->id, $this->storeB->id],
            'is_active' => '1',
        ], $overrides);
    }

    public function test_halaman_index_dan_form_dapat_diakses(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.payment-types.index'))->assertOk();
        $this->get(route('admin.payment-types.create'))->assertOk();
    }

    public function test_admin_dapat_menambahkan_tipe_pembayaran(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload())
            ->assertRedirect(route('admin.payment-types.index'));

        $paymentType = PaymentType::where('code', 'CASH')->first();
        $this->assertNotNull($paymentType);
        $this->assertSame('Pembayaran tunai', $paymentType->description);
        $this->assertSame(1, $paymentType->order_number);
        $this->assertSame('summary', $paymentType->count_type);
        $this->assertTrue($paymentType->is_active);
        $this->assertSame(
            [$this->storeA->id, $this->storeB->id],
            $paymentType->stores()->orderBy('code')->pluck('stores.id')->all()
        );
    }

    public function test_tipe_pembayaran_dipetakan_ke_kartu_kredit_sap(): void
    {
        $card = CreditCard::factory()->create(['company_id' => $this->company->id, 'code' => '2', 'name' => 'EDC BANK']);

        $this->actingAs($this->admin)->get(route('admin.payment-types.create'))->assertOk()->assertSee('2 · EDC BANK');
        $this->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'EDC', 'kind' => 'custom', 'credit_card_id' => $card->id]))
            ->assertRedirect(route('admin.payment-types.index'));

        $this->assertSame($card->id, PaymentType::where('code', 'EDC')->value('credit_card_id'));

        $paymentType = PaymentType::where('code', 'EDC')->firstOrFail();
        $this->put(route('admin.payment-types.update', $paymentType), $this->validPayload(['code' => 'EDC', 'kind' => 'cash', 'credit_card_id' => '']))
            ->assertRedirect(route('admin.payment-types.index'));
        $this->assertNull($paymentType->fresh()->credit_card_id);
    }

    public function test_kartu_kredit_perusahaan_lain_tidak_dapat_dipetakan(): void
    {
        $other = CompanyDetail::create(['name' => 'Other Co']);
        $foreign = CreditCard::factory()->create(['company_id' => $other->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['kind' => 'custom', 'credit_card_id' => $foreign->id]))
            ->assertSessionHasErrors('credit_card_id');
    }

    public function test_kode_tipe_pembayaran_harus_unik(): void
    {
        PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'description' => null,
            'order_number' => 1,
            'count_type' => 'summary',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload())
            ->assertSessionHasErrors('code');
    }

    public function test_validasi_field_wajib(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), [])
            ->assertSessionHasErrors(['code', 'kind', 'order_number', 'count_type', 'store_ids']);
    }

    public function test_update_menyinkronkan_gerai_dan_status_aktif(): void
    {
        $paymentType = PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'QRIS',
            'description' => null,
            'order_number' => 2,
            'count_type' => 'detail',
            'is_active' => true,
        ]);
        $paymentType->stores()->sync([$this->storeA->id]);

        $payload = $this->validPayload([
            'code' => 'QRIS',
            'order_number' => 3,
            'count_type' => 'detail',
        ]);
        unset($payload['is_active']);

        $this->actingAs($this->admin)
            ->put(route('admin.payment-types.update', $paymentType), $payload)
            ->assertRedirect(route('admin.payment-types.index'));

        $paymentType->refresh();
        $this->assertSame(3, $paymentType->order_number);
        $this->assertSame('detail', $paymentType->count_type);
        $this->assertFalse($paymentType->is_active);
        $this->assertSame(2, $paymentType->stores()->count());
    }

    public function test_hapus_tipe_pembayaran_menghapus_relasi_gerai(): void
    {
        $paymentType = PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'VOUCHER',
            'description' => null,
            'order_number' => 4,
            'count_type' => 'summary',
            'is_active' => true,
        ]);
        $paymentType->stores()->sync([$this->storeA->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.payment-types.destroy', $paymentType))
            ->assertRedirect(route('admin.payment-types.index'));

        $this->assertDatabaseMissing('payment_types', ['code' => 'VOUCHER']);
        $this->assertDatabaseCount('payment_type_store', 0);
    }

    private function enableSap(): void
    {
        $this->company->update([
            'sap_enabled' => true, 'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1', 'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager', 'sap_password' => 'secret',
        ]);
    }

    public function test_jenis_custom_wajib_dipetakan_ke_credit_card_saat_integrasi_sap_aktif(): void
    {
        $this->enableSap();
        $card = CreditCard::factory()->create(['company_id' => $this->company->id, 'code' => '1', 'name' => 'QRIS']);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom']))
            ->assertSessionHasErrors('credit_card_id');
        $this->assertDatabaseMissing('payment_types', ['code' => 'QRIS']);

        $this->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'credit_card_id' => $card->id]))
            ->assertRedirect(route('admin.payment-types.index'));
        $this->assertSame('custom', PaymentType::where('code', 'QRIS')->value('kind'));
    }

    public function test_jenis_cash_tidak_perlu_dan_tidak_menyimpan_mapping_credit_card(): void
    {
        $this->enableSap();
        $card = CreditCard::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'TUNAI', 'kind' => 'cash']))
            ->assertRedirect(route('admin.payment-types.index'));
        $this->assertNull(PaymentType::where('code', 'TUNAI')->value('credit_card_id'));

        $this->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'KAS', 'kind' => 'cash', 'credit_card_id' => $card->id]))
            ->assertRedirect(route('admin.payment-types.index'));
        $this->assertNull(PaymentType::where('code', 'KAS')->value('credit_card_id'));
    }

    public function test_tanpa_integrasi_sap_custom_boleh_tanpa_mapping(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'VOUCHER', 'kind' => 'custom']))
            ->assertRedirect(route('admin.payment-types.index'));
    }

    public function test_jenis_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['kind' => 'lain']))
            ->assertSessionHasErrors('kind');
    }

    public function test_tipe_tanpa_jenis_eksplisit_mengikuti_kode(): void
    {
        $cash = PaymentType::create(['company_id' => $this->company->id, 'code' => 'cash', 'order_number' => 1, 'count_type' => 'summary', 'is_active' => true]);
        $other = PaymentType::create(['company_id' => $this->company->id, 'code' => 'GOPAY', 'order_number' => 2, 'count_type' => 'summary', 'is_active' => true]);

        $this->assertTrue($cash->fresh()->isCash());
        $this->assertSame('custom', $other->fresh()->kind);
    }

    public function test_gambar_qr_diunggah_diganti_dan_dihapus(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1', 'qr_image' => UploadedFile::fake()->image('qris.png')]))
            ->assertRedirect(route('admin.payment-types.index'));

        $paymentType = PaymentType::where('code', 'QRIS')->firstOrFail();
        $oldPath = $paymentType->qr_image_path;
        Storage::disk('public')->assertExists($oldPath);
        $this->assertNotNull($paymentType->qr_image_url);

        $this->put(route('admin.payment-types.update', $paymentType), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1', 'qr_image' => UploadedFile::fake()->image('baru.png')]));
        $newPath = $paymentType->fresh()->qr_image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $this->put(route('admin.payment-types.update', $paymentType), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom']));
        $this->assertNull($paymentType->fresh()->qr_image_path);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_gambar_qr_dibuang_saat_bukan_pembayaran_qr(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'qr_image' => UploadedFile::fake()->image('qris.png')]));
        $this->assertNull(PaymentType::where('code', 'QRIS')->value('qr_image_path'));

        $this->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QR2', 'kind' => 'custom', 'is_qr_payment' => '1', 'qr_image' => UploadedFile::fake()->image('qris.png')]));
        $paymentType = PaymentType::where('code', 'QR2')->firstOrFail();
        $this->assertTrue($paymentType->is_qr_payment);
        $path = $paymentType->qr_image_path;
        Storage::disk('public')->assertExists($path);

        $this->put(route('admin.payment-types.update', $paymentType), $this->validPayload(['code' => 'QR2', 'kind' => 'custom']));
        $paymentType->refresh();
        $this->assertFalse($paymentType->is_qr_payment);
        $this->assertNull($paymentType->qr_image_path);
        $this->assertNull($paymentType->qr_image_url);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_pembayaran_qr_wajib_punya_gambar(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1']))
            ->assertSessionHasErrors('qr_image');
        $this->assertDatabaseMissing('payment_types', ['code' => 'QRIS']);

        $this->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1', 'qr_image' => UploadedFile::fake()->image('qris.png')]));
        $paymentType = PaymentType::where('code', 'QRIS')->firstOrFail();

        // Gambar lama cukup, tetapi menghapusnya tanpa pengganti ditolak.
        $this->put(route('admin.payment-types.update', $paymentType), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1']))
            ->assertSessionDoesntHaveErrors();
        $this->put(route('admin.payment-types.update', $paymentType), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1', 'remove_qr_image' => '1']))
            ->assertSessionHasErrors('qr_image');
        $this->assertNotNull($paymentType->fresh()->qr_image_path);

        // Bukan pembayaran QR: gambar tidak wajib.
        $this->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'VCR', 'kind' => 'custom']))->assertSessionDoesntHaveErrors();
    }

    public function test_berkas_qr_bukan_gambar_ditolak(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload(['code' => 'QRIS', 'kind' => 'custom', 'is_qr_payment' => '1', 'qr_image' => UploadedFile::fake()->create('qr.pdf', 10, 'application/pdf')]))
            ->assertSessionHasErrors('qr_image');
    }
}

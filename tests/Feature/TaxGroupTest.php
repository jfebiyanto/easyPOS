<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\TaxGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxGroupTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'base_currency_code' => 'IDR', 'timezone' => 'Asia/Jakarta']);
        $this->owner = User::create([
            'company_id' => $this->company->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Co',
            'base_currency_code' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'ppn_enabled' => '1',
        ], $overrides);
    }

    public function test_tax_group_manual_bisa_dibuat_dan_diubah(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.tax-groups.store'), ['code' => 'PPN-11', 'name' => 'PPN 11%', 'rate' => 11, 'is_active' => 1])
            ->assertRedirect(route('admin.tax-groups.index'));

        $taxGroup = TaxGroup::where('code', 'PPN-11')->firstOrFail();
        $this->assertSame(TaxGroup::SOURCE_MANUAL, $taxGroup->source);

        $this->put(route('admin.tax-groups.update', $taxGroup), ['code' => 'PPN-11', 'name' => 'PPN 12%', 'rate' => 12, 'is_active' => 1])
            ->assertRedirect(route('admin.tax-groups.index'));

        $this->assertSame('12.0000', $taxGroup->refresh()->rate);
    }

    public function test_tax_group_dari_sap_tidak_bisa_diubah_atau_dihapus(): void
    {
        $taxGroup = TaxGroup::factory()->fromSap()->create(['company_id' => $this->company->id, 'code' => 'PPNK-11']);

        $this->actingAs($this->owner)
            ->put(route('admin.tax-groups.update', $taxGroup), ['code' => 'PPNK-11', 'name' => 'Ubah', 'rate' => 5, 'is_active' => 1])
            ->assertForbidden();
        $this->delete(route('admin.tax-groups.destroy', $taxGroup))->assertForbidden();

        $this->assertSame('11.0000', $taxGroup->refresh()->rate);
        $this->get(route('admin.tax-groups.index'))->assertOk()->assertSee('Read-only (from SAP)');
    }

    public function test_setting_pajak_memakai_tarif_dari_tax_group_terpilih(): void
    {
        $ppn11 = TaxGroup::factory()->create(['company_id' => $this->company->id, 'code' => 'PPNK-11', 'rate' => 11]);

        $this->actingAs($this->owner)
            ->put(route('admin.company.update'), $this->companyPayload())
            ->assertSessionHasErrors('tax_group_id');

        $this->put(route('admin.company.update'), $this->companyPayload(['tax_group_id' => $ppn11->id]))
            ->assertSessionHasNoErrors();

        $company = $this->company->refresh();
        $this->assertSame($ppn11->id, $company->tax_group_id);
        $this->assertSame('11.00', $company->ppn_rate);

        // Mengubah tarif Tax Group yang dipakai ikut memperbarui tarif PPN perusahaan.
        $this->put(route('admin.tax-groups.update', $ppn11), ['code' => 'PPNK-11', 'name' => 'PPN 12%', 'rate' => 12, 'is_active' => 1]);
        $this->assertSame('12.00', $company->refresh()->ppn_rate);

        $this->get(route('admin.company.edit'))->assertOk()->assertSee('PPNK-11 · PPN 12% (12%)');
    }
}

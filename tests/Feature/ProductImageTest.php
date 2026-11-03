<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    private ProductGroup $group;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->owner = User::create([
            'company_id' => $this->company->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $this->group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'GRP', 'name' => 'Grup', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_group_id' => $this->group->id,
            'unit_id' => $this->unit->id,
            'sku' => 'SKU-IMG',
            'name' => 'Produk Bergambar',
            'base_price' => 5000,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_upload_gambar_saat_membuat_produk(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->image('foto.jpg', 200, 200),
            ]))
            ->assertRedirect(route('admin.products.index'));

        $product = Product::where('sku', 'SKU-IMG')->first();
        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertStringContainsString('/storage/', (string) $product->image_url);
    }

    public function test_gambar_dapat_dihapus(): void
    {
        $product = Product::create($this->payload([
            'company_id' => $this->company->id,
            'image_path' => 'products/old.jpg',
        ]));
        Storage::disk('public')->put('products/old.jpg', 'x');

        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $product), $this->payload(['remove_image' => '1']))
            ->assertRedirect(route('admin.products.index'));

        $this->assertNull($product->refresh()->image_path);
        Storage::disk('public')->assertMissing('products/old.jpg');
    }

    public function test_ganti_gambar_menghapus_file_lama(): void
    {
        $product = Product::create($this->payload([
            'company_id' => $this->company->id,
            'image_path' => 'products/old.jpg',
        ]));
        Storage::disk('public')->put('products/old.jpg', 'x');

        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $product), $this->payload([
                'image' => UploadedFile::fake()->image('baru.jpg', 200, 200),
            ]))
            ->assertRedirect(route('admin.products.index'));

        $newPath = $product->refresh()->image_path;
        $this->assertNotSame('products/old.jpg', $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing('products/old.jpg');
    }

    public function test_gambar_tetap_ada_bila_hapus_produk_gagal(): void
    {
        $product = Product::create($this->payload([
            'company_id' => $this->company->id,
            'image_path' => 'products/keep.jpg',
        ]));
        Storage::disk('public')->put('products/keep.jpg', 'x');

        Product::deleting(fn () => throw new \RuntimeException('FK constraint'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->owner)->delete(route('admin.products.destroy', $product));
            $this->fail('Exception tidak dilempar.');
        } catch (\RuntimeException) {
            // diharapkan
        }

        Storage::disk('public')->assertExists('products/keep.jpg');
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_hapus_produk_menghapus_file_gambar(): void
    {
        $product = Product::create($this->payload([
            'company_id' => $this->company->id,
            'image_path' => 'products/del.jpg',
        ]));
        Storage::disk('public')->put('products/del.jpg', 'x');

        $this->actingAs($this->owner)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        Storage::disk('public')->assertMissing('products/del.jpg');
    }
}

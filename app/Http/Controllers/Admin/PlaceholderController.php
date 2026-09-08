<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Halaman placeholder untuk fitur yang belum dibangun.
 */
class PlaceholderController extends Controller
{
    private const PAGES = [
        'currencies' => ['Mata Uang', 'payments', 'Konfigurasi mata uang yang dipakai aplikasi (kode, simbol, nilai tukar).'],
        'payment-types' => ['Tipe Pembayaran', 'credit_card', 'Konfigurasi metode pembayaran di gerai: tunai, QRIS, debit/kredit, voucher, dll.'],
        'bundles' => ['Produk Paket', 'workspaces', 'Bundling beberapa produk menjadi satu paket dengan harga khusus.'],
        'customer-groups' => ['Grup Pelanggan', 'diversity_3', 'Kategori pelanggan: member reguler, member grosir, korporat, dll.'],
        'supplier-groups' => ['Grup Pemasok', 'groups_3', 'Kategori pemasok untuk segmentasi pembelian.'],
        'customers' => ['Daftar Pelanggan', 'person', 'Master data pelanggan: nama, kontak, poin member, dan batas piutang.'],
        'suppliers' => ['Daftar Pemasok', 'local_shipping', 'Master data pemasok: nama, kontak, dan termin pembayaran.'],
    ];

    public function show(string $page): View
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        [$title, $icon, $description] = self::PAGES[$page];

        return view('admin.placeholders.index', [
            'pageTitle' => $title,
            'icon' => $icon,
            'description' => $description,
        ]);
    }
}
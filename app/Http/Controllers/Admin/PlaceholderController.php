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
        'bundles' => ['Bundles', 'workspaces', 'Bundle several products into one package at a special price.'],
        'customer-groups' => ['Customer Groups', 'diversity_3', 'Customer categories: regular members, wholesale members, corporate, etc.'],
        'supplier-groups' => ['Supplier Groups', 'groups_3', 'Supplier categories for purchasing segmentation.'],
        'customers' => ['Customers', 'person', 'Customer master data: name, contact, member points and credit limit.'],
        'suppliers' => ['Suppliers', 'local_shipping', 'Supplier master data: name, contact and payment terms.'],
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'easyPOS') · easyPOS Backoffice</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#1E4E8C">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;600&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0..1,0" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/md3.css') }}?v={{ filemtime(public_path('css/md3.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/md3-components.css') }}?v={{ filemtime(public_path('css/md3-components.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ filemtime(public_path('css/tokens.css')) }}">
</head>
<body>
<div class="app-shell">
    <aside class="nav-drawer" id="nav-drawer">
        <div class="nav-brand">
            <span class="material-symbols-rounded">point_of_sale</span>
            <span class="nav-brand-text">easyPOS</span>
        </div>
                    <nav class="nav-list">
            <div>
                    {!! navitem('admin.dashboard', 'dashboard', 'Dashboard') !!}
                </div>

            @php
                $navGroups = [
                    'konfigurasi' => ['Settings', [
                        ['admin.company.edit', 'apartment', 'Company Profile', true],
                        ['admin.sap-import.index', 'cloud_download', 'SAP B1 Import', \App\Models\CompanyDetail::current()->sapIntegrationActive()],
                        ['admin.sap-postings.index', 'cloud_upload', 'Integration Monitor', \App\Models\CompanyDetail::current()->sapIntegrationActive()],
                        ['admin.stores.index', 'store', 'Stores', true],
                        ['admin.warehouses.index', 'warehouse', 'Warehouses', true],
                        ['admin.tax-groups.index', 'percent', 'Tax Groups', true],
                        ['admin.users.index', 'group', 'Users', true],
                        ['admin.member-levels.index', 'workspace_premium', 'Member Tiers', true],
                        ['admin.numberings.index', 'pin', 'Numbering', true],
                        ['admin.currencies.index', 'payments', 'Currencies', true],
                        ['admin.payment-types.index', 'credit_card', 'Payment Methods', true],
                    ]],
                    'produk' => ['Products', [
                        ['admin.groups.index', 'category', 'Categories', true],
                        ['admin.units.index', 'straighten', 'Units', true],
                        ['admin.brands.index', 'verified', 'Brands', true],
                        ['admin.products.index', 'inventory_2', 'Product Catalog', true],
                        ['admin.bundles.index', 'workspaces', 'Bundles', true],
                        ['admin.price-lists.index', 'sell', 'Price Lists', true],
                        ['admin.promotions.index', 'percent', 'Promotions', true],
                        ['admin.promotion-hierarchy.edit', 'low_priority', 'Promo Priority', true],
                    ]],
                    'stok' => ['Inventory', [
                        ['admin.stock.index', 'warehouse', 'Stock Balance', true],
                        ['admin.stock-adjustments.index', 'tune', 'Stock Adjustments', true],
                        ['admin.stock-transfers.index', 'swap_horiz', 'Stock Transfers', true],
                    ]],
                    'operasional' => ['Operations', [
                        ['admin.customer-groups.index', 'diversity_3', 'Customer Groups', true],
                        ['admin.supplier-groups.index', 'groups_3', 'Supplier Groups', true],
                        ['admin.customers.index', 'person', 'Customers', true],
                        ['admin.suppliers.index', 'local_shipping', 'Suppliers', true],
                        ['admin.deliveries.index', 'local_shipping', 'Deliveries', true],
                        ['admin.stock-review.index', 'rule', 'Stock Review', true],
                        ['admin.eod.index', 'lock', 'End of Day', true],
                    ]],
                    'laporan' => ['Reports', [
                        ['admin.reports.index', 'bar_chart', 'Sales Reports', true],
                        ['admin.cancellations.index', 'block', 'Voids & Cancellations', true],
                        ['admin.logs.index', 'history', 'Activity Log', true],
                        ['admin.pos-sessions.index', 'devices', 'Cashier Sessions', true],
                    ]],
                ];
            @endphp

            {{-- Semua grup tertutup secara default; hanya grup berisi halaman aktif yang terbuka. --}}
            @foreach ($navGroups as $section => [$sectionLabel, $items])
                @php
                    $items = array_filter($items, fn ($item) => $item[3] && \App\Support\BackofficeAccess::visible(auth()->user(), $item[0]));
                    $isOpen = collect($items)->contains(fn ($item) => navitem_active($item[0]));
                @endphp
                <div class="nav-group {{ $isOpen ? '' : 'collapsed' }}" data-section="{{ $section }}">
                    <button type="button" class="nav-group-header" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                        <span class="nav-section-label">{{ $sectionLabel }}</span>
                        <span class="material-symbols-rounded nav-chevron">expand_more</span>
                    </button>
                    <div class="nav-group-body">
                        @foreach ($items as [$routeName, $icon, $label])
                            {!! navitem($routeName, $icon, $label) !!}
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
    </aside>
    <script>
        // Pulihkan posisi scroll sidebar sebelum halaman tampil, lalu pastikan menu aktif terlihat.
        (function () {
            var drawer = document.getElementById('nav-drawer');
            try { drawer.scrollTop = Number(sessionStorage.getItem('easypos.nav-scroll')) || 0; } catch (e) {}
            var active = drawer.querySelector('a.active');
            if (active) {
                var top = active.offsetTop, bottom = top + active.offsetHeight;
                if (top < drawer.scrollTop || bottom > drawer.scrollTop + drawer.clientHeight) {
                    drawer.scrollTop = top - drawer.clientHeight / 3;
                }
            }
        })();
    </script>
    <div class="scrim" id="scrim"></div>
    <div class="app-main">
        <header class="top-app-bar">
            <button class="icon-btn" id="menu-toggle" aria-label="Menu">
                <span class="material-symbols-rounded">menu</span>
            </button>
            <h1 class="top-app-bar-title">@yield('page_title', 'Dashboard')</h1>
            @php
                $activeStore = \App\Models\Store::find(active_store_id());
            @endphp
            @if ($activeStore)
                @php
                    $otherStores = auth()->user()->isSuperuser()
                        ? \App\Models\Store::where('company_id', current_company_id())->whereKeyNot($activeStore->id)->orderBy('code')->get()
                        : collect();
                @endphp
                {{-- Informasi & pergantian store aktif (sebelumnya di kartu dashboard). --}}
                <div class="store-switcher" data-dropdown>
                    <button type="button" class="user-chip store-switcher-toggle" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" title="Active store for master data">
                        <span class="material-symbols-rounded" style="color: var(--md-primary);">store</span>
                        <div class="user-chip-text">
                            <span class="user-chip-name">{{ $activeStore->code }}</span>
                            <span class="user-chip-role">Store Aktif</span>
                        </div>
                        <span class="material-symbols-rounded store-switcher-chevron">expand_more</span>
                    </button>
                    <div class="store-switcher-panel" data-dropdown-panel hidden>
                        <div class="store-switcher-info">
                            <strong>{{ $activeStore->code }} · {{ $activeStore->name }}</strong>
                            <dl>
                                <dt>Store Manager</dt><dd>{{ $activeStore->pic_name ?: '-' }}</dd>
                                <dt>PPN</dt><dd>{{ $activeStore->ppn_enabled ? $activeStore->ppn_rate.'%' : 'Inactive' }}</dd>
                            </dl>
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.stores.edit', $activeStore) }}" class="btn btn-tonal btn-sm">
                                    <span class="material-symbols-rounded">edit</span> Edit Store Ini
                                </a>
                            @endif
                        </div>
                        @if ($otherStores->isNotEmpty())
                            <div class="store-switcher-label">Ganti Store Aktif</div>
                            <ul class="store-switcher-list">
                                @foreach ($otherStores as $otherStore)
                                    <li>
                                        <form method="POST" action="{{ route('admin.stores.switch', $otherStore) }}">
                                            @csrf
                                            <button type="submit">
                                                <span class="material-symbols-rounded">swap_horiz</span>
                                                <span><strong>{{ $otherStore->code }}</strong> · {{ $otherStore->name }}</span>
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
            <div class="top-app-bar-actions">
                <div class="user-chip" title="{{ auth()->user()->email ?? '' }}">
                    <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <div class="user-chip-text">
                        <span class="user-chip-name">{{ auth()->user()->name }}</span>
                        <span class="user-chip-role">{{ ucfirst(str_replace('_', ' ', auth()->user()->role)) }}</span>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn" title="Log out">
                        <span class="material-symbols-rounded">logout</span>
                    </button>
                </form>
            </div>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
        @php
            $business = store_business_status($activeStore?->id);
        @endphp
        {{-- Tanggal bisnis & jam mengikuti zona waktu perusahaan dan jam server (diperbarui tiap detik oleh admin.js). --}}
        <footer class="status-bar" data-business-clock data-timezone="{{ $business['timezone'] }}" data-server-time="{{ $business['server_time'] }}">
            <span class="status-bar-item">
                <span class="material-symbols-rounded">event</span>
                Business Date: <strong data-business-date>{{ \Illuminate\Support\Carbon::parse($business['business_date'])->locale('id')->translatedFormat('l, j F Y') }}</strong>
            </span>
            <span class="status-bar-item">
                <span class="material-symbols-rounded">schedule</span>
                <strong data-business-time>{{ now($business['timezone'])->format('H:i:s') }}</strong>
            </span>
            @if ($business['closed'])
                <a class="status-bar-item status-bar-warning" href="{{ route('admin.eod.index', ['store' => $activeStore->id, 'date' => $business['business_date']]) }}">
                    <span class="material-symbols-rounded">lock</span>
                    EOD {{ $activeStore->code }} hari ini sudah ditutup — transaksi POS terkunci sampai EOD dibuka ulang
                </a>
            @endif
            @if ($business['locked_date'])
                <a class="status-bar-item status-bar-warning" href="{{ route('admin.eod.index', ['store' => $activeStore->id, 'date' => $business['locked_date']]) }}">
                    <span class="material-symbols-rounded">lock</span>
                    @php($lockedDateLabel = \Illuminate\Support\Carbon::parse($business['locked_date'])->locale('id')->translatedFormat('j F Y'))
                    EOD for {{ $activeStore->code }} {{ $lockedDateLabel }} has not been run. All transactions will be recorded as {{ $lockedDateLabel }} transactions.
                </a>
            @endif
        </footer>
    </div>
</div>
<script src="{{ asset('js/admin.js') }}?v={{ filemtime(public_path('js/admin.js')) }}"></script>
@stack('scripts')
</body>
</html>
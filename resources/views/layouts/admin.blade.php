<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'easyPOS') · easyPOS Backoffice</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0..1,0" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/md3.css') }}?v={{ filemtime(public_path('css/md3.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/md3-components.css') }}?v={{ filemtime(public_path('css/md3-components.css')) }}">
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

            <div class="nav-group" data-section="konfigurasi">
                <button type="button" class="nav-group-header" aria-expanded="true">
                    <span class="nav-section-label">Konfigurasi</span>
                    <span class="material-symbols-rounded nav-chevron">expand_more</span>
                </button>
                <div class="nav-group-body">
                    {!! navitem('admin.company.edit', 'apartment', 'Identitas Perusahaan') !!}
                    {!! navitem('admin.stores.index', 'store', 'Daftar Gerai') !!}
                    {!! navitem('admin.users.index', 'group', 'Daftar User') !!}
                    @if (auth()->user()->isSuperuser())
                        {!! navitem('admin.numberings.index', 'pin', 'Penomoran') !!}
                    @endif
                    {!! navitem('admin.currencies.index', 'payments', 'Mata Uang') !!}
                    {!! navitem('admin.payment-types.index', 'credit_card', 'Tipe Pembayaran') !!}
                </div>
            </div>

            <div class="nav-group" data-section="produk">
                <button type="button" class="nav-group-header" aria-expanded="true">
                    <span class="nav-section-label">Produk</span>
                    <span class="material-symbols-rounded nav-chevron">expand_more</span>
                </button>
                <div class="nav-group-body">
                    {!! navitem('admin.groups.index', 'category', 'Kategori Produk') !!}
                    {!! navitem('admin.brands.index', 'verified', 'Daftar Merk') !!}
                    {!! navitem('admin.products.index', 'inventory_2', 'Daftar Produk') !!}
                    {!! navitem('admin.bundles.index', 'workspaces', 'Produk Paket') !!}
                    {!! navitem('admin.price-lists.index', 'sell', 'Daftar Harga') !!}
                    {!! navitem('admin.promotions.index', 'percent', 'Promosi') !!}
                </div>
            </div>

            <div class="nav-group" data-section="operasional">
                <button type="button" class="nav-group-header" aria-expanded="true">
                    <span class="nav-section-label">Operasional</span>
                    <span class="material-symbols-rounded nav-chevron">expand_more</span>
                </button>
                <div class="nav-group-body">
                    {!! navitem('admin.customer-groups.index', 'diversity_3', 'Grup Pelanggan') !!}
                    {!! navitem('admin.supplier-groups.index', 'groups_3', 'Grup Pemasok') !!}
                    {!! navitem('admin.customers.index', 'person', 'Daftar Pelanggan') !!}
                    {!! navitem('admin.suppliers.index', 'local_shipping', 'Daftar Pemasok') !!}
                </div>
            </div>

            <div class="nav-group" data-section="laporan">
                <button type="button" class="nav-group-header" aria-expanded="true">
                    <span class="nav-section-label">Laporan</span>
                    <span class="material-symbols-rounded nav-chevron">expand_more</span>
                </button>
                <div class="nav-group-body">
                    @if (auth()->user()->isSuperuser())
                        {!! navitem('admin.logs.index', 'history', 'Log Aktivitas') !!}
                    @endif
                </div>
            </div>
        </nav>
    </aside>
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
                <div class="user-chip" title="Store aktif untuk data master">
                    <span class="material-symbols-rounded" style="color: var(--md-primary);">store</span>
                    <div class="user-chip-text">
                        <span class="user-chip-name">{{ $activeStore->code }}</span>
                        <span class="user-chip-role">Store Aktif</span>
                    </div>
                </div>
            @endif
            <div class="top-app-bar-actions">
                <div class="user-chip" title="{{ auth()->user()->email ?? '' }}">
                    <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <div class="user-chip-text">
                        <span class="user-chip-name">{{ auth()->user()->name }}</span>
                        <span class="user-chip-role">{{ ucfirst(auth()->user()->type) }}</span>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn" title="Keluar">
                        <span class="material-symbols-rounded">logout</span>
                    </button>
                </form>
            </div>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('js/admin.js') }}"></script>
@stack('scripts')
</body>
</html>
@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@section('content')
@php
    $cardMenu = '<button type="button" class="ts-menu-btn" data-ts-menu aria-label="Menu kartu"><span class="material-symbols-rounded">menu</span></button>
        <ul class="ts-menu-list" hidden>
            <li><button type="button" data-ts-refresh><span class="material-symbols-rounded">refresh</span>Muat ulang</button></li>
            <li><a href="'.e(route('admin.reports.index')).'"><span class="material-symbols-rounded">bar_chart</span>Buka laporan</a></li>
        </ul>';
@endphp

@if ($store)
    {{-- Semua angka diisi oleh resources/js/admin/dashboard/main.js dari route admin.dashboard.today. --}}
    <section class="today-sales is-loading" id="today-sales" data-url="{{ route('admin.dashboard.today') }}" aria-busy="true" aria-label="Penjualan Hari Ini">
        <div class="ts-heading">
            <h2>Penjualan Hari Ini · {{ $store->code }}</h2>
            <span class="ts-date" data-ts-date></span>
        </div>

        <div class="ts-error" data-ts-error hidden role="alert">
            <span class="material-symbols-rounded">error</span>
            <span data-ts-error-text></span>
            <button type="button" data-ts-refresh>Coba lagi</button>
        </div>

        <div class="ts-grid">
            <article class="ts-card ts-accent-green">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">payments</span></span>
                    <h3>Total Penjualan Hari Ini</h3>
                    {!! $cardMenu !!}
                </header>
                <div class="ts-label">Total Transaksi Hari Ini</div>
                <div class="ts-metric">
                    <span class="ts-arrow material-symbols-rounded" data-ts-sales-arrow>trending_flat</span>
                    <span class="ts-value" data-ts-sales-total>Rp 0</span>
                    <span class="ts-delta" data-ts-sales-delta>&nbsp;</span>
                </div>
                <div class="ts-sub" data-ts-sales-count>&nbsp;</div>
                <div class="ts-chart" data-ts-chart="sales"></div>
            </article>

            <article class="ts-card ts-accent-amber">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">local_fire_department</span></span>
                    <h3>Produk Terlaris</h3>
                    {!! $cardMenu !!}
                </header>
                <div class="ts-label"><span class="ts-dot"></span><span data-ts-product-name>&nbsp;</span></div>
                <div class="ts-metric">
                    <span class="ts-value" data-ts-product-total>Rp 0</span>
                    <span class="ts-delta" data-ts-product-delta>&nbsp;</span>
                </div>
                <div class="ts-sub" data-ts-product-qty>&nbsp;</div>
                <div class="ts-chart" data-ts-chart="products"></div>
            </article>

            <article class="ts-card ts-accent-blue">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">insights</span></span>
                    <h3>Ringkasan Performa</h3>
                    {!! $cardMenu !!}
                </header>
                <div class="ts-perf">
                    <span data-ts-ring="net"></span>
                    <div>
                        <div class="ts-perf-label">Penjualan Bersih</div>
                        <div class="ts-value ts-value-income" data-ts-net>Rp 0</div>
                        <span class="ts-delta" data-ts-net-delta>&nbsp;</span>
                    </div>
                </div>
                <div class="ts-perf">
                    <span data-ts-ring="deductions"></span>
                    <div>
                        <div class="ts-perf-label">Retur / Diskon</div>
                        <div class="ts-value ts-value-expense" data-ts-deductions>Rp 0</div>
                        <span class="ts-delta" data-ts-deductions-delta>&nbsp;</span>
                    </div>
                </div>
            </article>
        </div>
    </section>

    @php
        $isOwner = auth()->user()->isOwner();
    @endphp
    {{-- Ringkasan semua gerai (Owner) atau gerai sendiri (Store Manager); diisi oleh resources/js/admin/dashboard/overview.js. --}}
    <section class="today-sales ov-section is-loading" id="store-overview" data-ov-url="{{ route('admin.dashboard.overview') }}" aria-busy="true" aria-label="Ringkasan Hari Ini">
        <div class="ts-heading">
            <h2>{{ $isOwner ? 'Ringkasan Semua Gerai Hari Ini' : 'Ringkasan Gerai '.$store->code.' Hari Ini' }}</h2>
            <span class="ts-date" data-ov-date></span>
        </div>

        <div class="ts-error" data-ov-error hidden role="alert">
            <span class="material-symbols-rounded">error</span>
            <span data-ov-error-text></span>
        </div>

        <div class="ov-grid {{ $isOwner ? '' : 'ov-grid-counts' }}">
            <article class="ts-card ts-accent-green ov-count">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">receipt_long</span></span>
                    <h3>Transaksi Penjualan Hari Ini</h3>
                </header>
                <div class="ts-value ov-number" data-ov-sales-count>0</div>
            </article>

            <article class="ts-card ts-accent-red ov-count">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">assignment_return</span></span>
                    <h3>Transaksi Retur Hari Ini</h3>
                </header>
                <div class="ts-value ov-number" data-ov-returns-count>0</div>
            </article>

            @if ($isOwner)
                <article class="ts-card ts-accent-blue ov-chart-card">
                    <header class="ts-card-head">
                        <span class="ts-card-icon"><span class="material-symbols-rounded">bar_chart</span></span>
                        <h3>Perbandingan Penjualan Antar Gerai</h3>
                    </header>
                    <div class="ov-chart" data-ov-chart="stores"></div>
                </article>
            @endif
        </div>
    </section>

    @push('scripts')
        @vite('resources/js/admin/dashboard/main.js')
    @endpush
@else
    <div class="card">
        <div class="empty-state">
            <span class="material-symbols-rounded">store</span>
            <p>Belum ada konfigurasi store. <a href="{{ route('admin.stores.create') }}">Konfigurasi store sekarang</a>.</p>
        </div>
    </div>
@endif
@endsection
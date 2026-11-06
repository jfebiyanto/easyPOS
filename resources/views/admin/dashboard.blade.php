@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@section('content')
@php
    $cardMenu = '<button type="button" class="ts-menu-btn" data-ts-menu aria-label="Card menu"><span class="material-symbols-rounded">menu</span></button>
        <ul class="ts-menu-list" hidden>
            <li><button type="button" data-ts-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></li>
            <li><a href="'.e(route('admin.reports.index')).'"><span class="material-symbols-rounded">bar_chart</span>Open reports</a></li>
        </ul>';
@endphp

@if ($store)
    {{-- Semua angka diisi oleh resources/js/admin/dashboard/main.js dari route admin.dashboard.today. --}}
    <section class="today-sales is-loading" id="today-sales" data-url="{{ route('admin.dashboard.today') }}" aria-busy="true" aria-label="Today's Sales">
        <div class="ts-heading">
            <h2>Today's Sales · {{ $store->code }}</h2>
            <span class="ts-date" data-ts-date></span>
        </div>

        <div class="ts-error" data-ts-error hidden role="alert">
            <span class="material-symbols-rounded">error</span>
            <span data-ts-error-text></span>
            <button type="button" data-ts-refresh>Try again</button>
        </div>

        <div class="ts-grid">
            <article class="ts-card ts-accent-green">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">payments</span></span>
                    <h3>Today's Total Sales</h3>
                    {!! $cardMenu !!}
                </header>
                <div class="ts-label">Today's Total Transactions</div>
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
                    <h3>Best Sellers</h3>
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
                    <h3>Performance Summary</h3>
                    {!! $cardMenu !!}
                </header>
                <div class="ts-perf">
                    <span data-ts-ring="net"></span>
                    <div>
                        <div class="ts-perf-label">Net Sales</div>
                        <div class="ts-value ts-value-income" data-ts-net>Rp 0</div>
                        <span class="ts-delta" data-ts-net-delta>&nbsp;</span>
                    </div>
                </div>
                <div class="ts-perf">
                    <span data-ts-ring="deductions"></span>
                    <div>
                        <div class="ts-perf-label">Returns / Discounts</div>
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
    <section class="today-sales ov-section is-loading" id="store-overview" data-ov-url="{{ route('admin.dashboard.overview') }}" aria-busy="true" aria-label="Today's Summary">
        <div class="ts-heading">
            <h2>{{ $isOwner ? "Today's Summary · All Stores" : "Today's Summary · Store ".$store->code }}</h2>
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
                    <h3>Today's Sales Transactions</h3>
                </header>
                <div class="ts-value ov-number" data-ov-sales-count>0</div>
            </article>

            <article class="ts-card ts-accent-red ov-count">
                <header class="ts-card-head">
                    <span class="ts-card-icon"><span class="material-symbols-rounded">assignment_return</span></span>
                    <h3>Today's Return Transactions</h3>
                </header>
                <div class="ts-value ov-number" data-ov-returns-count>0</div>
            </article>

            @if ($isOwner)
                <article class="ts-card ts-accent-blue ov-chart-card">
                    <header class="ts-card-head">
                        <span class="ts-card-icon"><span class="material-symbols-rounded">bar_chart</span></span>
                        <h3>Sales Comparison Across Stores</h3>
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
            <p>No store configured yet. <a href="{{ route('admin.stores.create') }}">Configure a store now</a>.</p>
        </div>
    </div>
@endif
@endsection
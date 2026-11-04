@extends('layouts.admin')

@section('title', 'Impor Data SAP B1')
@section('page_title', 'Impor Data SAP B1')

@section('content')

@php
    $canManage = auth()->user()->isOwner();
    $entities = \App\Support\Sap\SapImportService::ENTITIES;
    $entityLabels = array_map(fn ($e) => $e[0], $entities);
    $progressRun = $activeRun ?? $runs->first();
    $progressIcons = ['pending' => 'radio_button_unchecked', 'running' => 'progress_activity', 'done' => 'check_circle', 'failed' => 'error'];
    $progressLabels = ['pending' => 'Menunggu', 'running' => 'Sedang diimpor', 'done' => 'Selesai', 'failed' => 'Gagal'];
    $statusLabels = ['queued' => 'Antre', 'running' => 'Berjalan', 'success' => 'Berhasil', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan'];
@endphp

@if (! $canManage)
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">lock</span>
        Anda tidak memiliki hak Owner: halaman ini bersifat read-only.
    </div>
@endif

<div class="card">
    <h2 class="card-title">Impor Data Awal</h2>

    <p>
        Impor awal terakhir:
        <strong>{{ $company->sap_initial_imported_at ? $company->sap_initial_imported_at->timezone(company_timezone())->format('d M Y H:i') : 'Belum pernah' }}</strong>
        · Sinkronisasi terakhir:
        <strong>{{ $company->sap_last_synced_at ? $company->sap_last_synced_at->timezone(company_timezone())->format('d M Y H:i') : '-' }}</strong>
        · Mutasi stok terakhir:
        <strong>{{ $company->sap_stock_synced_at ? $company->sap_stock_synced_at->timezone(company_timezone())->format('d M Y H:i') : '-' }}</strong>
    </p>

    @if ($company->sap_stock_sync_error)
        <div class="md-snackbar error">
            <span class="material-symbols-rounded">warning</span> {{ $company->sap_stock_sync_error }}
        </div>
    @endif

    @if ($canManage)
        <div class="form-actions" style="margin-top: 8px;">
            @if ($company->sap_initial_imported_at)
                <form method="POST" action="{{ route('admin.sap-import.run') }}">
                    @csrf
                    <input type="hidden" name="mode" value="delta">
                    <button type="submit" class="btn btn-filled" @disabled($activeRun)>
                        <span class="material-symbols-rounded">sync</span> Sinkronkan Perubahan Sekarang
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.sap-import.run') }}">
                    @csrf
                    <input type="hidden" name="mode" value="initial">
                    <button type="submit" class="btn btn-filled" @disabled($activeRun)>
                        <span class="material-symbols-rounded">cloud_download</span> Mulai Impor Data Awal
                    </button>
                </form>
            @endif
        </div>
    @endif

    <div id="sap-run-status" style="margin-top: 12px; {{ $activeRun ? '' : 'display: none;' }}" class="md-snackbar info">
        <span class="material-symbols-rounded">hourglass_top</span>
        <span id="sap-run-status-text">Impor sedang berjalan di latar belakang...</span>
        @if ($canManage)
            <form method="POST" action="{{ route('admin.sap-import.cancel') }}" style="margin-left: auto;"
                  onsubmit="return confirm('Hentikan impor yang sedang berjalan?');">
                @csrf
                <button type="submit" class="btn btn-text">
                    <span class="material-symbols-rounded">cancel</span> Batalkan
                </button>
            </form>
        @endif
    </div>
    @if ($activeRun && $activeRun->status === 'queued' && $activeRun->created_at->lt(now()->subMinute()))
        <p class="hint">Impor belum dimulai. Pastikan queue worker aktif (<code>php artisan queue:listen</code> atau <code>composer run dev</code>).</p>
    @endif
</div>

<div class="card" id="sap-progress-card">
    <h2 class="card-title">Progres Impor</h2>
    <p class="card-subtitle">
        @if ($progressRun)
            Impor {{ $progressRun->mode === 'initial' ? 'awal' : 'perubahan' }} ·
            {{ $progressRun->created_at->timezone(company_timezone())->format('d M Y H:i') }} ·
            {{ $statusLabels[$progressRun->status] ?? $progressRun->status }}
        @else
            Belum ada impor yang dijalankan.
        @endif
    </p>

    @if ($progressRun?->error)
        <div class="md-snackbar error">
            <span class="material-symbols-rounded">error</span> {{ $progressRun->error }}
        </div>
    @endif

    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th style="width: 48px;"></th>
                    <th>Objek</th>
                    <th>Tabel SAP</th>
                    <th>Status</th>
                    <th>Diambil</th>
                    <th>Baru</th>
                    <th>Diubah</th>
                    <th>Dilewati</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entities as $key => [$label, $sapTable])
                    @php
                        $p = $progressRun?->summary[$key] ?? ['status' => 'pending', 'fetched' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
                        $st = $p['status'] ?? 'pending';
                    @endphp
                    <tr data-entity="{{ $key }}">
                        <td><span class="material-symbols-rounded" data-role="icon">{{ $progressIcons[$st] ?? $progressIcons['pending'] }}</span></td>
                        <td>{{ $label }}</td>
                        <td>{{ $sapTable }}</td>
                        <td data-role="status">{{ $progressLabels[$st] ?? $progressLabels['pending'] }}</td>
                        <td data-role="fetched">{{ $p['fetched'] ?? 0 }}</td>
                        <td data-role="created">{{ $p['created'] ?? 0 }}</td>
                        <td data-role="updated">{{ $p['updated'] ?? 0 }}</td>
                        <td data-role="skipped">{{ $p['skipped'] ?? 0 }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Sinkronisasi Berkala</h2>
    <p class="card-subtitle">
        Setelah impor awal, perubahan data dari SAP B1 ditarik otomatis sesuai interval (default 30 menit).
        Membutuhkan scheduler (<code>php artisan schedule:run</code> tiap menit) dan queue worker aktif.
    </p>

    <form method="POST" action="{{ route('admin.sap-import.settings') }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="field @error('sap_sync_interval_minutes') has-error @enderror">
                <label for="sap_sync_interval_minutes">Interval sinkronisasi (menit)</label>
                <input type="number" min="1" max="10080" id="sap_sync_interval_minutes" name="sap_sync_interval_minutes"
                       value="{{ old('sap_sync_interval_minutes', $company->sap_sync_interval_minutes) }}" required @disabled(! $canManage)>
                @error('sap_sync_interval_minutes') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="sap_auto_sync" name="sap_auto_sync" value="1"
                           @checked(old('sap_auto_sync', $company->sap_auto_sync)) @disabled(! $canManage)>
                    <label for="sap_auto_sync">Aktifkan sinkronisasi otomatis</label>
                </div>
            </div>
        </div>
        @if ($canManage)
            <div class="form-actions">
                <button type="submit" class="btn btn-filled">
                    <span class="material-symbols-rounded">save</span> Simpan Pengaturan
                </button>
            </div>
        @endif
    </form>
</div>

<div class="card">
    <h2 class="card-title">Riwayat Impor</h2>
    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Mode</th>
                    <th>Pemicu</th>
                    <th>Status</th>
                    <th>Objek selesai</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($runs as $run)
                    @php $doneCount = collect($run->summary ?? [])->where('status', 'done')->count(); @endphp
                    <tr>
                        <td>{{ $run->created_at->timezone(company_timezone())->format('d M Y H:i') }}</td>
                        <td>{{ $run->mode === 'initial' ? 'Awal' : 'Perubahan' }}</td>
                        <td>{{ ['schedule' => 'Terjadwal', 'stock' => 'Mutasi stok'][$run->trigger] ?? 'Manual' }}</td>
                        <td>
                            {{ $statusLabels[$run->status] ?? $run->status }}
                            @if ($run->error)
                                <div class="hint">{{ $run->error }}</div>
                            @endif
                        </td>
                        <td>{{ $doneCount }} / {{ count($entities) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Belum ada riwayat impor.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($activeRun)
    @push('scripts')
    <script>
        (function () {
            var url = @json(route('admin.sap-import.status'));
            var icons = @json($progressIcons);
            var labels = @json($progressLabels);
            var text = document.getElementById('sap-run-status-text');

            function render(summary) {
                var done = 0;
                var keys = Object.keys(summary || {});
                keys.forEach(function (key) {
                    var p = summary[key];
                    var row = document.querySelector('#sap-progress-card tr[data-entity="' + key + '"]');
                    if (! row) return;
                    if (p.status === 'done') done++;
                    row.querySelector('[data-role="icon"]').textContent = icons[p.status] || icons.pending;
                    row.querySelector('[data-role="status"]').textContent = labels[p.status] || labels.pending;
                    ['fetched', 'created', 'updated', 'skipped'].forEach(function (f) {
                        row.querySelector('[data-role="' + f + '"]').textContent = p[f] || 0;
                    });
                });
                text.textContent = 'Impor sedang berjalan (' + done + ' dari ' + keys.length + ' objek selesai)...';
            }

            var timer = setInterval(function () {
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        render(data.summary);
                        if (! data.in_progress) {
                            clearInterval(timer);
                            window.location.reload();
                        }
                    });
            }, 2000);
        })();
    </script>
    @endpush
@endif
@endsection

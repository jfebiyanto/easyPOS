@extends('layouts.admin')

@section('title', 'Identitas Perusahaan')
@section('page_title', 'Identitas Perusahaan')

@section('content')

@php $canManage = auth()->user()->isOwner(); @endphp

@if (! $canManage)
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">lock</span>
        Anda tidak memiliki hak Owner: halaman ini bersifat read-only.
    </div>
@endif

<form method="POST" action="{{ route('admin.company.update') }}">
    @csrf
    @method('PUT')

    <div class="card">
        <h2 class="card-title">Informasi Perusahaan</h2>
        <p class="card-subtitle">Identitas badan usaha perusahaan (Head Office).</p>

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="name">Nama Perusahaan *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $company->name) }}" required @disabled(! $canManage)>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('address') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="address">Alamat Perusahaan</label>
                <textarea id="address" name="address" rows="2" @disabled(! $canManage)>{{ old('address', $company->address) }}</textarea>
                @error('address') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('npwp_number') has-error @enderror">
                <label for="npwp_number">Nomor NPWP</label>
                <input type="text" id="npwp_number" name="npwp_number" value="{{ old('npwp_number', $company->npwp_number) }}" maxlength="30" placeholder="00.000.000.0-000.000" @disabled(! $canManage)>
                @error('npwp_number') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('phone') has-error @enderror">
                <label for="phone">Telepon</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $company->phone) }}" maxlength="30" @disabled(! $canManage)>
                @error('phone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('email') has-error @enderror">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $company->email) }}" @disabled(! $canManage)>
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Setting Pajak</h2>
        <p class="card-subtitle">Setting PPN level perusahaan akan menjadi acuan default untuk store.</p>

        <div class="form-grid">
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="ppn_enabled" name="ppn_enabled" value="1" @checked(old('ppn_enabled', $company->ppn_enabled)) @disabled(! $canManage)>
                    <label for="ppn_enabled">Aktifkan PPN</label>
                </div>
                <div class="hint">Jika aktif, transaksi penjualan dikenakan PPN sesuai tarif di bawah.</div>
            </div>
            <div class="field @error('ppn_rate') has-error @enderror">
                <label for="ppn_rate">Tarif PPN (%)</label>
                <input type="number" id="ppn_rate" name="ppn_rate" step="0.01" min="0" max="100" value="{{ old('ppn_rate', $company->ppn_rate ?? 11) }}" @disabled(! $canManage)>
                @error('ppn_rate') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Kebijakan Global</h2>
        <p class="card-subtitle">Mata uang dasar, timezone, kebijakan stok, dan retur berlaku untuk seluruh gerai.</p>

        <div class="form-grid">
            <div class="field @error('base_currency_code') has-error @enderror">
                <label for="base_currency_code">Mata Uang Dasar *</label>
                <input type="text" id="base_currency_code" name="base_currency_code" value="{{ old('base_currency_code', $company->base_currency_code ?? 'IDR') }}" maxlength="10" @disabled(! $canManage)>
                @error('base_currency_code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('timezone') has-error @enderror">
                <label for="timezone">Timezone *</label>
                <input type="text" id="timezone" name="timezone" value="{{ old('timezone', $company->timezone ?? 'Asia/Jakarta') }}" maxlength="40" @disabled(! $canManage)>
                @error('timezone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="ppn_inclusive" name="ppn_inclusive" value="1" @checked(old('ppn_inclusive', $company->ppn_inclusive)) @disabled(! $canManage)>
                    <label for="ppn_inclusive">Harga jual sudah termasuk PPN (inclusive)</label>
                </div>
                <div class="hint">Jika tidak dicentang, PPN ditambahkan di atas harga (exclusive).</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="allow_negative_stock" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $company->allow_negative_stock)) @disabled(! $canManage)>
                    <label for="allow_negative_stock">Izinkan stok negatif</label>
                </div>
                <div class="hint">Jika aktif, transaksi offline yang membuat stok negatif dianggap sah tanpa review.</div>
            </div>
            <div class="field @error('return_window_days') has-error @enderror">
                <label for="return_window_days">Batas waktu retur (hari)</label>
                <input type="number" id="return_window_days" name="return_window_days" min="0" max="3650" value="{{ old('return_window_days', $company->return_window_days ?? 30) }}" @disabled(! $canManage)>
                @error('return_window_days') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="return_require_receipt" name="return_require_receipt" value="1" @checked(old('return_require_receipt', $company->return_require_receipt ?? true)) @disabled(! $canManage)>
                    <label for="return_require_receipt">Retur wajib menunjukkan struk</label>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Integrasi SAP Business One (Opsional)</h2>
        <p class="card-subtitle">
            Sinkronisasi dengan SAP Business One.
            Jika tidak diaktifkan, aplikasi berjalan <strong>standalone</strong> (tanpa SAP).
        </p>

        <div class="field">
            <div class="checkbox-row">
                <input type="checkbox" id="sap_enabled" name="sap_enabled" value="1"
                       @checked(old('sap_enabled', $company->sap_enabled))
                       @disabled(! $canManage)>
                <label for="sap_enabled"><strong>Aktifkan Integrasi SAP Business One</strong></label>
                @if ($company->sapIntegrationActive())
                    <span class="badge active">Aktif</span>
                @elseif ($company->sap_enabled && ! $company->hasSapConfig())
                    <span class="badge inactive">ON — kredensial belum lengkap</span>
                @else
                    <span class="badge inactive">Nonaktif (standalone)</span>
                @endif
            </div>
        </div>

        <div id="sap-fields" style="{{ $company->sap_enabled ? '' : 'display: none;' }}">
            <div class="form-grid">
                <div class="field @error('sap_endpoint_url') has-error @enderror" style="grid-column: 1 / -1;">
                    <label for="sap_endpoint_url">SAP Business One Endpoint URL</label>
                    <input type="url" id="sap_endpoint_url" name="sap_endpoint_url" value="{{ old('sap_endpoint_url', $company->sap_endpoint_url) }}"
                           placeholder="https://sapb1.company.co.id:50000/b1s/v1" @disabled(! $canManage)>
                    @error('sap_endpoint_url') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('sap_db_name') has-error @enderror">
                    <label for="sap_db_name">SAP Business One Database</label>
                    <input type="text" id="sap_db_name" name="sap_db_name" value="{{ old('sap_db_name', $company->sap_db_name) }}" maxlength="60" @disabled(! $canManage)>
                    @error('sap_db_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('sap_username') has-error @enderror">
                    <label for="sap_username">Username</label>
                    <input type="text" id="sap_username" name="sap_username" value="{{ old('sap_username', $company->sap_username) }}" maxlength="60" @disabled(! $canManage)>
                    @error('sap_username') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('sap_password') has-error @enderror">
                    <label for="sap_password">Password @if ($company->sap_password)(biarkan kosong jika tidak diubah)@endif</label>
                    <input type="password" id="sap_password" name="sap_password" autocomplete="new-password" @disabled(! $canManage)>
                    @error('sap_password') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="sap_allow_self_signed" name="sap_allow_self_signed" value="1"
                           @checked(old('sap_allow_self_signed', ! $company->sap_verify_ssl))
                           @disabled(! $canManage)>
                    <label for="sap_allow_self_signed">Izinkan sertifikat self-signed (klik untuk mematikan verifikasi SSL, sama seperti default Postman)</label>
                </div>
                <div class="hint">
                    Server SAP B1 internal biasanya memakai self-signed certificate — centang ini agar tes koneksi tidak
                    gagal dengan error SSL. <strong>Nonaktifkan hanya untuk jaringan internal yang tepercaya.</strong>
                </div>
            </div>

            <div class="form-actions" style="margin-top: 4px;">
                <button type="button" class="btn btn-tonal" id="btn-test-sap" data-url="{{ route('admin.company.test-sap') }}" data-csrf="{{ csrf_token() }}">
                    <span class="material-symbols-rounded">network_check</span> Tes Koneksi SAP
                </button>
                <div id="sap-test-result" style="display: none; flex: 1;"></div>
            </div>
        </div>
    </div>

    @if ($canManage)
        <div class="form-actions">
            <button type="submit" class="btn btn-filled">
                <span class="material-symbols-rounded">save</span> Simpan Konfigurasi
            </button>
        </div>
    @endif
</form>
    @if ($canManage)
        @push('scripts')
        <script>
            (function () {
                var toggle = document.getElementById('sap_enabled');
                var fields = document.getElementById('sap-fields');

                function refresh() {
                    if (! toggle || ! fields) return;
                    fields.style.display = toggle.checked ? '' : 'none';
                }

                if (toggle) {
                    toggle.addEventListener('change', refresh);
                }
                refresh();
            })();

            // Test koneksi SAP B1
            (function () {
                var btn = document.getElementById('btn-test-sap');
                var result = document.getElementById('sap-test-result');
                if (! btn) return;

                function show(text, cls) {
                    result.style.display = '';
                    result.className = cls;
                    result.textContent = text;
                }

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    show('Menguji koneksi ke SAP B1...', 'md-snackbar info');

                    fetch(btn.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': btn.dataset.csrf,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({})
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        show(data.message, data.success ? 'md-snackbar success' : 'md-snackbar error');
                    }).catch(function (e) {
                        show('Gagal memproses tes koneksi: ' + e, 'md-snackbar error');
                    }).finally(function () {
                        btn.disabled = false;
                    });
                });
            })();
        </script>
        @endpush
    @endif
@endsection

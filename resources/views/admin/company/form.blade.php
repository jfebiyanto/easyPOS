@extends('layouts.admin')

@section('title', 'Company Profile')
@section('page_title', 'Company Profile')

@section('content')

@php $canManage = auth()->user()->isOwner(); @endphp

@if (! $canManage)
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">lock</span>
        You do not have Owner rights: this page is read-only.
    </div>
@endif

<form method="POST" action="{{ route('admin.company.update') }}">
    @csrf
    @method('PUT')

    <div class="card">
        <h2 class="card-title">Company Information</h2>
        <p class="card-subtitle">Legal identity of the company (Head Office).</p>

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="name">Company Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $company->name) }}" required @disabled(! $canManage)>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('address') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="address">Company Address</label>
                <textarea id="address" name="address" rows="2" @disabled(! $canManage)>{{ old('address', $company->address) }}</textarea>
                @error('address') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('npwp_number') has-error @enderror">
                <label for="npwp_number">Tax ID (NPWP)</label>
                <input type="text" id="npwp_number" name="npwp_number" value="{{ old('npwp_number', $company->npwp_number) }}" maxlength="30" placeholder="00.000.000.0-000.000" @disabled(! $canManage)>
                @error('npwp_number') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('phone') has-error @enderror">
                <label for="phone">Phone</label>
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
        <h2 class="card-title">Tax Settings</h2>
        <p class="card-subtitle">The company-level VAT setting is the default for every store.</p>

        <div class="form-grid">
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="ppn_enabled" name="ppn_enabled" value="1" @checked(old('ppn_enabled', $company->ppn_enabled)) @disabled(! $canManage)>
                    <label for="ppn_enabled">Enable VAT</label>
                </div>
                <div class="hint">When enabled, sales are charged VAT according to the Tax Group beside it (including 0%).</div>
            </div>
            <div class="field @error('tax_group_id') has-error @enderror">
                <label for="tax_group_id">Tax Group (Output Tax)</label>
                <select id="tax_group_id" name="tax_group_id" @disabled(! $canManage)>
                    <option value="">- Select Tax Group -</option>
                    @foreach ($taxGroups as $tg)
                        <option value="{{ $tg->id }}" @selected(old('tax_group_id', $company->tax_group_id) == $tg->id)>{{ $tg->label() }}</option>
                    @endforeach
                </select>
                <div class="hint">
                    {{ $company->sapIntegrationActive() ? 'Diambil dari SAP B1 lewat Impor Data SAP B1.' : 'Kelola di menu' }}
                    <a href="{{ route('admin.tax-groups.index') }}">Tax Group</a>.
                </div>
                @error('tax_group_id') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Global Configuration</h2>
        <p class="card-subtitle">Base currency, timezone, stock policy and return rules apply to all stores.</p>

        <div class="form-grid">
            <div class="field @error('base_currency_code') has-error @enderror">
                <label for="base_currency_code">Base Currency *</label>
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
                    <label for="ppn_inclusive">Selling price includes VAT (inclusive)</label>
                </div>
                <div class="hint">When unchecked, VAT is added on top of the selling price (exclusive).</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="allow_negative_stock" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $company->allow_negative_stock)) @disabled(! $canManage)>
                    <label for="allow_negative_stock">Allow negative stock</label>
                </div>
                <div class="hint">When enabled, offline sales that cause negative stock are accepted without review.</div>
            </div>
            <div class="field @error('return_window_days') has-error @enderror">
                <label for="return_window_days">Return window (days)</label>
                <input type="number" id="return_window_days" name="return_window_days" min="0" max="3650" value="{{ old('return_window_days', $company->return_window_days ?? 30) }}" @disabled(! $canManage)>
                @error('return_window_days') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="return_require_receipt" name="return_require_receipt" value="1" @checked(old('return_require_receipt', $company->return_require_receipt ?? true)) @disabled(! $canManage)>
                    <label for="return_require_receipt">Returns require a receipt</label>
                </div>
            </div>
        </div>
    </div>

    @if ($canManage)
        <div class="form-actions">
            <button type="submit" class="btn btn-filled">
                <span class="material-symbols-rounded">save</span> Save Configuration
            </button>
        </div>
    @endif
</form>

@php
    $sapLocked = $company->sapConnectionLocked();
    $sapErrorKeys = ['sap_endpoint_url', 'sap_db_name', 'sap_username', 'sap_password', 'sap_connection', 'current_password'];
    $sapHasErrors = $errors->hasAny($sapErrorKeys);
@endphp

<form method="POST" action="{{ route('admin.company.update-sap') }}" id="sap-form"
      data-locked="{{ $sapLocked ? '1' : '0' }}" data-start-editing="{{ $sapLocked && $sapHasErrors ? '1' : '0' }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="current_password" id="sap_current_password">

    <div class="card">
        <h2 class="card-title">SAP Business One Integration (Optional)</h2>
        <p class="card-subtitle">
            Synchronization with SAP Business One.
            When disabled, the application runs <strong>standalone</strong> (without SAP).
        </p>

        @if ($sapLocked)
            <div class="md-snackbar info">
                <span class="material-symbols-rounded">lock</span>
                <span>
                    Koneksi terkunci sejak impor data awal ({{ $company->sap_initial_imported_at->timezone($company->timezone ?? config('app.timezone'))->format('d M Y H:i') }}).
                    Database SAP B1 tidak dapat diubah. Perubahan lain memerlukan konfirmasi password akun easyPOS Anda.
                </span>
            </div>
        @endif

        @foreach (['sap_connection', 'current_password'] as $sapErrorKey)
            @error($sapErrorKey)
                <div class="md-snackbar error">
                    <span class="material-symbols-rounded">error</span> {{ $message }}
                </div>
            @enderror
        @endforeach

        <div class="field">
            <div class="checkbox-row">
                <input type="checkbox" id="sap_enabled" name="sap_enabled" value="1"
                       data-sap-field data-label="Integrasi SAP B1" data-original="{{ $company->sap_enabled ? '1' : '0' }}"
                       @checked(old('sap_enabled', $company->sap_enabled))
                       @disabled(! $canManage)>
                <label for="sap_enabled"><strong>Enable SAP Business One Integration via Service Layer</strong></label>
                @if ($company->sapIntegrationActive())
                    <span class="badge active">Active</span>
                    <a href="{{ route('admin.sap-import.index') }}" class="btn btn-text">
                        <span class="material-symbols-rounded">cloud_download</span> SAP B1 Import
                    </a>
                @elseif ($company->sap_enabled && ! $company->hasSapConfig())
                    <span class="badge inactive">ON — kredensial belum lengkap</span>
                @else
                    <span class="badge inactive">Inactive (standalone)</span>
                @endif
            </div>
        </div>

        <div id="sap-fields" style="{{ $company->sap_enabled ? '' : 'display: none;' }}">
            <div class="form-grid">
                <div class="field @error('sap_endpoint_url') has-error @enderror" style="grid-column: 1 / -1;">
                    <label for="sap_endpoint_url">SAP Business One Endpoint URL</label>
                    <input type="url" id="sap_endpoint_url" name="sap_endpoint_url" value="{{ old('sap_endpoint_url', $company->sap_endpoint_url) }}"
                           data-sap-field data-sap-unlockable data-label="Endpoint URL" data-original="{{ $company->sap_endpoint_url }}"
                           placeholder="https://sapb1.company.co.id:50000/b1s/v1" @disabled(! $canManage) @readonly($sapLocked)>
                    @error('sap_endpoint_url') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('sap_db_name') has-error @enderror">
                    <label for="sap_db_name">SAP Business One Database @if ($sapLocked)<span class="material-symbols-rounded" style="font-size: 14px; vertical-align: -2px;" title="Permanently locked">lock</span>@endif</label>
                    <input type="text" id="sap_db_name" name="sap_db_name" value="{{ old('sap_db_name', $company->sap_db_name) }}" maxlength="60"
                           @disabled(! $canManage) @readonly($sapLocked)>
                    @if ($sapLocked)
                        <div class="hint"></div>
                    @endif
                    @error('sap_db_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('sap_username') has-error @enderror">
                    <label for="sap_username">Username</label>
                    <input type="text" id="sap_username" name="sap_username" value="{{ old('sap_username', $company->sap_username) }}" maxlength="60"
                           data-sap-field data-sap-unlockable data-label="Username" data-original="{{ $company->sap_username }}"
                           @disabled(! $canManage) @readonly($sapLocked)>
                    @error('sap_username') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('sap_password') has-error @enderror">
                    <label for="sap_password">Password</label>
                    <input type="password" id="sap_password" name="sap_password" autocomplete="new-password"
                           data-sap-field data-sap-unlockable data-sap-secret data-label="Password SAP B1" data-original=""
                           @disabled(! $canManage) @readonly($sapLocked)>
                    @error('sap_password') <div class="error">{{ $message }}</div> @enderror
                    @if ($company->sap_password)<div class="hint">(leave blank to keep unchanged)</div>@endif
                </div>
            </div>

            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="sap_allow_self_signed" name="sap_allow_self_signed" value="1"
                           data-sap-field data-sap-unlockable data-label="Allow Self-Signed Certificate" data-original="{{ $company->sap_verify_ssl ? '0' : '1' }}"
                           @checked(old('sap_allow_self_signed', ! $company->sap_verify_ssl))
                           @disabled(! $canManage || $sapLocked)>
                    <label for="sap_allow_self_signed">Allow Self-Signed Certificate</label>
                </div>
                <div class="hint" style="display: none">
                    Server SAP B1 internal biasanya memakai self-signed certificate — centang ini agar tes koneksi tidak
                    fail with an SSL error. <strong>Disable only for trusted internal networks.</strong>
                </div>
            </div>

            <div class="form-actions" style="margin-top: 4px;">
                <button type="button" class="btn btn-tonal" id="btn-test-sap" data-url="{{ route('admin.company.test-sap') }}" data-csrf="{{ csrf_token() }}">
                    <span class="material-symbols-rounded">network_check</span> Test SAP Connection
                </button>
                @if ($sapLocked && $canManage)
                    <button type="button" class="btn btn-outline" id="btn-unlock-sap">
                        <span class="material-symbols-rounded">edit</span> Change SAP Connection
                    </button>
                @endif
                <div id="sap-test-result" style="display: none; flex: 1;"></div>
            </div>
        </div>

        @if ($canManage)
            <div class="form-actions">
                <button type="submit" class="btn btn-filled" id="btn-save-sap">
                    <span class="material-symbols-rounded">save</span> Save SAP Integration
                </button>
            </div>
        @endif
    </div>
</form>

@if ($canManage && $sapLocked)
    <dialog class="md-dialog" id="sap-confirm-dialog" aria-labelledby="sap-confirm-title">
        <form method="dialog" id="sap-confirm-form">
            <h3 class="md-dialog-title" id="sap-confirm-title">
                <span class="material-symbols-rounded">shield_lock</span> Confirm SAP B1 Change
            </h3>
            <ul class="change-list" id="sap-change-list"></ul>
            <div class="md-snackbar warning" id="sap-warning-off" hidden>
                <span class="material-symbols-rounded">warning</span>
                <span>If the integration is turned off, transactions will not be sent to SAP B1 and easyPOS stock will no longer tally with SAP B1.</span>
            </div>
            <div class="md-snackbar warning" id="sap-warning-on" hidden>
                <span class="material-symbols-rounded">warning</span>
                <span>SAP B1 stock movements made while the integration is off will be copied on the next sync. easyPOS transactions made while it is off are not sent to SAP B1, so stock may differ from SAP B1.</span>
            </div>
            <div class="md-snackbar success" id="sap-test-ok" hidden>
                <span class="material-symbols-rounded">check_circle</span> <span id="sap-test-ok-text"></span>
            </div>
            <div class="field">
                <label for="sap_confirm_password">Your easyPOS account password</label>
                <input type="password" id="sap_confirm_password" autocomplete="current-password" required>
            </div>
            <div class="md-dialog-actions">
                <button type="button" class="btn btn-outline" id="sap-confirm-cancel">Cancel</button>
                <button type="submit" class="btn btn-filled" id="sap-confirm-submit">
                    <span class="material-symbols-rounded">lock_open</span> Confirm &amp; Save
                </button>
            </div>
        </form>
    </dialog>
@endif

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

            // Test koneksi SAP B1 (dipakai tombol Tes Koneksi dan alur simpan saat terkunci)
            var sapTester = (function () {
                var btn = document.getElementById('btn-test-sap');
                var result = document.getElementById('sap-test-result');

                function show(text, cls) {
                    result.style.display = '';
                    result.className = cls;
                    result.textContent = text;
                }

                function val(id) {
                    var el = document.getElementById(id);
                    return el ? el.value.trim() : '';
                }

                function run() {
                    var body = {
                        sap_endpoint_url: val('sap_endpoint_url'),
                        sap_db_name: val('sap_db_name'),
                        sap_username: val('sap_username'),
                        sap_password: document.getElementById('sap_password').value,
                        sap_allow_self_signed: document.getElementById('sap_allow_self_signed').checked ? 1 : 0
                    };

                    show('Menguji koneksi ke SAP B1...', 'md-snackbar info');

                    return fetch(btn.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': btn.dataset.csrf,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(body)
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        show(data.message || 'Tes koneksi gagal.', data.success ? 'md-snackbar success' : 'md-snackbar error');
                        return data;
                    }).catch(function (e) {
                        show('Gagal memproses tes koneksi: ' + e, 'md-snackbar error');
                        return { success: false };
                    });
                }

                if (btn) {
                    btn.addEventListener('click', function () {
                        btn.disabled = true;
                        run().finally(function () { btn.disabled = false; });
                    });
                }

                return { run: run };
            })();

            // Koneksi terkunci setelah impor awal: buka field lewat tombol, simpan lewat dialog konfirmasi password.
            (function () {
                var form = document.getElementById('sap-form');
                if (! form || form.dataset.locked !== '1') return;

                var unlockBtn = document.getElementById('btn-unlock-sap');
                var saveBtn = document.getElementById('btn-save-sap');
                var dialog = document.getElementById('sap-confirm-dialog');
                var changeList = document.getElementById('sap-change-list');
                var passwordInput = document.getElementById('sap_confirm_password');
                var hiddenPassword = document.getElementById('sap_current_password');
                var confirmed = false;

                function startEditing() {
                    form.querySelectorAll('[data-sap-unlockable]').forEach(function (el) {
                        if (el.type === 'checkbox') {
                            el.disabled = false;
                        } else {
                            el.readOnly = false;
                        }
                    });
                    if (unlockBtn) unlockBtn.hidden = true;
                    document.getElementById('sap_endpoint_url').focus();
                }

                function currentValue(el) {
                    if (el.type === 'checkbox') return el.checked ? '1' : '0';
                    return el.value.trim();
                }

                function display(el, value) {
                    if (el.type === 'checkbox') return value === '1' ? 'Yes' : 'No';
                    return value === '' ? '(kosong)' : value;
                }

                function collectChanges() {
                    var changes = [];
                    form.querySelectorAll('[data-sap-field]').forEach(function (el) {
                        var now = currentValue(el);
                        if (el.hasAttribute('data-sap-secret')) {
                            if (now !== '') changes.push({ label: el.dataset.label, text: '(diubah)' });
                            return;
                        }
                        if (now !== el.dataset.original) {
                            changes.push({ label: el.dataset.label, from: display(el, el.dataset.original), to: display(el, now) });
                        }
                    });
                    return changes;
                }

                function renderChanges(changes) {
                    changeList.innerHTML = '';
                    changes.forEach(function (c) {
                        var li = document.createElement('li');
                        var label = document.createElement('span');
                        label.className = 'change-label';
                        label.textContent = c.label;
                        li.appendChild(label);
                        if (c.text) {
                            li.appendChild(document.createTextNode(c.text));
                        } else {
                            var old = document.createElement('span');
                            old.className = 'change-old';
                            old.textContent = c.from;
                            li.appendChild(old);
                            li.appendChild(document.createTextNode(' → ' + c.to));
                        }
                        changeList.appendChild(li);
                    });
                }

                function submitForReal() {
                    confirmed = true;
                    // Field disabled tidak ikut terkirim; aktifkan agar server menerima nilai lengkap.
                    form.querySelectorAll('[data-sap-unlockable]').forEach(function (el) { el.disabled = false; });
                    form.submit();
                }

                if (unlockBtn) unlockBtn.addEventListener('click', startEditing);
                if (form.dataset.startEditing === '1') startEditing();

                form.addEventListener('submit', function (e) {
                    if (confirmed) return;
                    e.preventDefault();

                    var changes = collectChanges();
                    if (changes.length === 0) {
                        submitForReal();
                        return;
                    }

                    var toggle = document.getElementById('sap_enabled');
                    var willBeEnabled = toggle.checked;
                    var turningOff = toggle.dataset.original === '1' && ! willBeEnabled;
                    var turningOn = toggle.dataset.original === '0' && willBeEnabled;

                    document.getElementById('sap-warning-off').hidden = ! turningOff;
                    document.getElementById('sap-warning-on').hidden = ! turningOn;
                    document.getElementById('sap-test-ok').hidden = true;
                    renderChanges(changes);

                    function openDialog() {
                        passwordInput.value = '';
                        dialog.showModal();
                        passwordInput.focus();
                    }

                    // Koneksi yang akan aktif wajib lolos tes login dulu; mematikan integrasi tidak perlu tes.
                    if (! willBeEnabled) {
                        openDialog();
                        return;
                    }

                    saveBtn.disabled = true;
                    sapTester.run().then(function (data) {
                        if (! data.success) return;
                        document.getElementById('sap-test-ok-text').textContent = data.message;
                        document.getElementById('sap-test-ok').hidden = false;
                        openDialog();
                    }).finally(function () { saveBtn.disabled = false; });
                });

                document.getElementById('sap-confirm-cancel').addEventListener('click', function () {
                    dialog.close();
                });

                document.getElementById('sap-confirm-form').addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (passwordInput.value === '') return;
                    hiddenPassword.value = passwordInput.value;
                    dialog.close();
                    submitForReal();
                });
            })();
        </script>
        @endpush
    @endif
@endsection

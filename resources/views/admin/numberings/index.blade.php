@extends('layouts.admin')

@section('title', 'Penomoran')
@section('page_title', 'Penomoran')

@section('content')
<div class="card">
    <h2 class="card-title">Konfigurasi Penomoran</h2>
    <p class="card-subtitle">
        Format nomor per tipe transaksi: <code>PREFIX{!! ($configs->first()?->separator ?: '/') !!}PERIODE{!! ($configs->first()?->separator ?: '/') !!}NOMOR</code>.
        Bagian periode otomatis hilang jika reset = "Tidak pernah", dan counter ter-reset otomatis saat periode berganti.
    </p>

    <form method="POST" action="{{ route('admin.numberings.update') }}">
        @csrf
        @method('PUT')

        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr>
                        <th>Tipe Transaksi</th>
                        <th>Prefix</th>
                        <th>Jumlah Digit</th>
                        <th>Separator</th>
                        <th>No. Terakhir</th>
                        <th>No. Berikutnya</th>
                        <th>Reset</th>
                        <th>Contoh</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($configs as $config)
                        @php
                            $typeLabel = \App\Models\NumberingConfig::TYPES[$config->transaction_type];
                            $nextNumber = $config->last_number + 1;
                            $example = $config->formatNumber($nextNumber);
                        @endphp
                        <tr>
                            <td><strong>{{ $typeLabel }}</strong></td>
                            <td>
                                <input type="text" name="configs[{{ $config->transaction_type }}][prefix]" value="{{ old('configs.'.$config->transaction_type.'.prefix', $config->prefix) }}" maxlength="20" style="width: 100px; padding: 8px 10px; border: 1px solid var(--md-outline); border-radius: 8px;">
                            </td>
                            <td>
                                <input type="number" name="configs[{{ $config->transaction_type }}][padding]" value="{{ old('configs.'.$config->transaction_type.'.padding', $config->padding) }}" min="1" max="12" style="width: 80px; padding: 8px 10px; border: 1px solid var(--md-outline); border-radius: 8px;">
                            </td>
                            <td>
                                <input type="text" name="configs[{{ $config->transaction_type }}][separator]" value="{{ old('configs.'.$config->transaction_type.'.separator', $config->separator) }}" maxlength="1" style="width: 50px; padding: 8px 10px; border: 1px solid var(--md-outline); border-radius: 8px; text-align: center;">
                            </td>
                            <td><code>{{ $config->last_number > 0 ? $config->formatNumber($config->last_number) : '-' }}</code></td>
                            <td>
                                <input type="number" name="configs[{{ $config->transaction_type }}][next_number]" value="{{ old('configs.'.$config->transaction_type.'.next_number', $nextNumber) }}" min="1" style="width: 90px; padding: 8px 10px; border: 1px solid var(--md-outline); border-radius: 8px;">
                            </td>
                            <td>
                                <select name="configs[{{ $config->transaction_type }}][reset_period]" style="padding: 8px 10px; border: 1px solid var(--md-outline); border-radius: 8px;">
                                    @foreach (\App\Models\NumberingConfig::RESET_PERIODS as $value => $label)
                                        <option value="{{ $value }}" @selected(old('configs.'.$config->transaction_type.'.reset_period', $config->reset_period) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><code style="color: var(--md-on-primary-container); background: var(--md-primary-container); padding: 4px 8px; border-radius: 6px;">{{ $example }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="form-actions" style="margin-top: 20px;">
            <button type="submit" class="btn btn-filled">
                <span class="material-symbols-rounded">save</span> Simpan Semua
            </button>
        </div>
    </form>
</div>
@endsection
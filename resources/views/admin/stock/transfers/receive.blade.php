@extends('layouts.admin')

@section('title', 'Terima Transfer')
@section('page_title', 'Terima Transfer · '.($transfer->number ?? 'Draft'))

@section('content')
<form method="POST" action="{{ route('admin.stock-transfers.receive', $transfer) }}">
    @csrf

    <div class="card">
        <h2 class="card-title">Penerimaan Barang</h2>
        <p class="card-subtitle">
            Dari <strong>{{ $transfer->fromStore?->code }}</strong> ke <strong>{{ $transfer->toStore?->code }}</strong>.
            Isi qty yang benar-benar diterima. Jika berbeda dari qty dikirim, alasan selisih wajib diisi.
        </p>

        @error('lines')
            <div class="md-snackbar error"><span class="material-symbols-rounded">error</span>{{ $message }}</div>
        @enderror

        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th style="text-align:right;">Dikirim</th>
                        <th style="width:160px;">Qty Diterima</th>
                        <th>Alasan Selisih</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transfer->lines as $i => $line)
                        <tr>
                            <td>{{ $line->product?->sku }} · {{ $line->product?->name }}</td>
                            <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $line->qty_shipped, 3, ',', '.'), '0'), ',') }}</td>
                            <td>
                                <input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}">
                                <input type="number" step="0.001" min="0" name="lines[{{ $i }}][qty_received]" value="{{ old("lines.$i.qty_received", $line->qty_shipped) }}" style="width:100%; padding:8px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm);">
                            </td>
                            <td>
                                <input type="text" name="lines[{{ $i }}][variance_reason]" value="{{ old("lines.$i.variance_reason") }}" maxlength="500" style="width:100%; padding:8px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm);" placeholder="wajib jika qty berbeda">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">check</span> Konfirmasi Penerimaan</button>
            <a href="{{ route('admin.stock-transfers.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </div>
</form>
@endsection

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1B1F23; }
        .header { border-bottom: 2px solid #1E4E8C; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { margin: 0; font-size: 16px; color: #1E4E8C; }
        .header .muted { color: #5A6472; font-size: 10px; }
        .meta { margin: 8px 0 12px; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #D6D6D0; padding: 6px 8px; text-align: left; }
        th { background: #F1F1EE; font-size: 10px; text-transform: uppercase; }
        td.num, th.num { text-align: right; }
        .footer { margin-top: 16px; font-size: 9px; color: #5A6472; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        <div class="muted">{{ $company->address }}</div>
    </div>

    <div class="meta">
        <strong>{{ $data['title'] }}</strong><br>
        Periode: {{ \Illuminate\Support\Carbon::parse($data['from'])->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($data['to'])->format('d/m/Y') }}
    </div>

    @php
        $isMoney = function (string $col): bool {
            foreach (['Total', 'Omzet', 'Subtotal', 'Kotor', 'Ongkir', 'Diskon', 'PPN', 'Retur', 'Net', 'Rata-rata', 'Belanja'] as $needle) {
                if (str_contains($col, $needle)) return true;
            }
            return false;
        };
        $fmt = function ($value, bool $money) {
            if (! is_numeric($value)) return $value;
            return $money
                ? 'Rp '.number_format((float) $value, 0, ',', '.')
                : rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
        };
    @endphp

    <table>
        <thead>
            <tr>
                @foreach ($data['columns'] as $i => $col)
                    <th class="{{ in_array($i, $data['numeric'] ?? [], true) ? 'num' : '' }}">{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data['rows'] as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td class="{{ in_array($i, $data['numeric'] ?? [], true) ? 'num' : '' }}">{{ $fmt($cell, $isMoney($data['columns'][$i] ?? '')) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($data['columns']) }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (! empty($data['notes']))
        <p>{{ $data['notes'] }}</p>
    @endif

    <div class="footer">Dicetak {{ now()->timezone(config('app.timezone', 'Asia/Jakarta'))->format('d/m/Y H:i') }}</div>
</body>
</html>

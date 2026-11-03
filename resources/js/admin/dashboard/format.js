// Format angka & perbandingan untuk section "Penjualan Hari Ini".

export const formatRupiah = (value) =>
    'Rp ' + Math.round(Number(value) || 0).toLocaleString('id-ID');

// Ringkas untuk sumbu grafik: 1.250.000 → "1,3 jt", 12.500 → "12,5 rb".
export function formatCompact(value) {
    const n = Number(value) || 0;
    const abs = Math.abs(n);
    if (abs >= 1e9) return (n / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M';
    if (abs >= 1e6) return (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
    if (abs >= 1e3) return (n / 1e3).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' rb';
    return n.toLocaleString('id-ID', { maximumFractionDigits: 0 });
}

export const formatNumber = (value) => (Number(value) || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });

/**
 * Arah & warna perbandingan vs kemarin. `higherIsBetter=false` untuk metrik pengeluaran
 * (retur/diskon naik = merah). `pct` null berarti belum ada data pembanding.
 */
export function delta(pct, { higherIsBetter = true } = {}) {
    if (pct === null || pct === undefined) {
        return { direction: 'flat', tone: 'muted', icon: 'remove', text: 'Belum ada data kemarin', pct: null };
    }
    const direction = pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat';
    const good = direction === 'flat' ? null : (direction === 'up') === higherIsBetter;
    const abs = Math.abs(pct).toLocaleString('id-ID', { maximumFractionDigits: 1 });

    return {
        direction,
        tone: good === null ? 'muted' : good ? 'up' : 'down',
        icon: direction === 'up' ? 'arrow_upward' : direction === 'down' ? 'arrow_downward' : 'remove',
        text: `${pct > 0 ? '+' : pct < 0 ? '−' : ''}${abs}% vs kemarin`,
        sentence: direction === 'flat' ? 'Sama dengan kemarin' : `${direction === 'up' ? 'Naik' : 'Turun'} sebesar ${abs}%`,
        pct,
    };
}

export const clampPct = (pct) => Math.max(0, Math.min(100, Number(pct) || 0));

export const initials = (name) =>
    String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w.charAt(0).toUpperCase()).join('');

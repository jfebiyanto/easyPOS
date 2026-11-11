// Gerbang Open Store / Start Shift di POS (docs/superpowers/specs/2026-11-11-open-store-shift-design.md).

/**
 * Layar gerbang menurut status toko dan shift kasir dari server:
 * 'open_store' (toko belum dibuka), 'start_shift' (toko open, kasir belum punya shift),
 * 'blocked' (EOD sudah ditutup), atau null (langsung masuk POS).
 */
export function gateScreen(gate) {
    if (!gate) return null;
    if (gate.state === 'eod_done') return 'blocked';
    if (gate.state === 'not_opened') return 'open_store';
    if (gate.state === 'open' && !gate.has_shift) return 'start_shift';
    return null;
}

/** Total uang dari hitungan per denominasi ({ nilai: jumlah lembar }); isian tidak valid dianggap 0. */
export function countFloat(counts) {
    return Object.entries(counts || {}).reduce((sum, [value, qty]) => {
        const n = parseInt(qty, 10);
        const v = Number(value);
        return Number.isFinite(n) && n > 0 && Number.isFinite(v) ? sum + v * n : sum;
    }, 0);
}

/** Selisih hitungan kasir terhadap modal standar toko (negatif = kurang). */
export function floatVariance(counted, standard) {
    return Math.round((Number(counted || 0) - Number(standard || 0)) * 100) / 100;
}

/**
 * Isian qty di tabel keranjang: hanya angka, dan hasilnya bilangan bulat >= 1.
 */
export function digitsOnly(text) {
    return String(text ?? '').replace(/[^\d]/g, '');
}

/**
 * @returns {number|null} qty yang valid, atau null bila isian kosong / nol (diabaikan, qty lama dipertahankan)
 */
export function parseQty(text) {
    const digits = digitsOnly(text);
    if (digits === '') return null;
    const value = Number(digits);

    return Number.isSafeInteger(value) && value >= 1 ? value : null;
}

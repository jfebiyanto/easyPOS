// Tombol fungsi F1–F12 di tepi bawah layar POS (Design-Brief-Layar-POS.md).
// Aksi didefinisikan di sini; App.jsx hanya memetakan id aksi ke handler.

export const FUNCTION_KEYS = [
    { key: 'F1', action: 'return', label: 'Retur' },
    { key: 'F2', action: 'customer', label: 'Pelanggan' },
    { key: 'F3', action: 'searchProduct', label: 'Cari Produk' },
    { key: 'F4', action: null, label: '' },
    { key: 'F5', action: 'editItem', label: 'Ubah Item', needsCart: true },
    { key: 'F6', action: 'transactions', label: 'Transaksi' },
    { key: 'F7', action: 'delivery', label: 'Pengiriman' },
    { key: 'F8', action: 'removeItem', label: 'Hapus Item', needsCart: true },
    { key: 'F9', action: 'cancelSale', label: 'Batal Transaksi', needsCart: true },
    { key: 'F10', action: 'holdOrRecall', label: 'Tahan / Daftar' },
    { key: 'F11', action: 'quickPay', label: 'Bayar Cepat', needsCart: true, needsCash: true },
    { key: 'F12', action: 'pay', label: 'Bayar', needsCart: true, primary: true },
];

// Status tiap tombol: dialog terbuka menonaktifkan semuanya (dialog tidak boleh bertumpuk),
// keranjang kosong menonaktifkan aksi yang butuh isi keranjang, dan F4 tidak punya fungsi.
export function functionKeyStates({ cartEmpty, modalOpen, hasCashMethod = true, heldCount = 0 }) {
    return FUNCTION_KEYS.map((fk) => ({
        ...fk,
        disabled: Boolean(
            !fk.action
            || modalOpen
            || (fk.needsCart && cartEmpty)
            || (fk.needsCash && !hasCashMethod),
        ),
        badge: fk.action === 'holdOrRecall' && heldCount > 0 ? heldCount : null,
    }));
}

// F10: keranjang berisi = tahan, keranjang kosong = buka daftar tahan.
export const holdOrRecallAction = (cartEmpty) => (cartEmpty ? 'recall' : 'hold');

// Event keyboard → id aksi (atau null). `F4` dan tombol tanpa aksi tetap dicegat agar tidak memicu browser.
export function keyToAction(event) {
    if (event.ctrlKey || event.altKey || event.metaKey) return null;
    const fk = FUNCTION_KEYS.find((item) => item.key === event.key);
    return fk ? fk.action : null;
}

export const isFunctionKey = (event) => /^F([1-9]|1[0-2])$/.test(event.key) && !event.ctrlKey && !event.altKey && !event.metaKey;

// Indeks baris terpilih setelah panah atas/bawah, berhenti di ujung tabel.
export function moveSelection(index, delta, length) {
    if (length === 0) return -1;
    if (index < 0) return delta > 0 ? 0 : length - 1;
    return Math.min(length - 1, Math.max(0, index + delta));
}

// Pilih baris sesudah baris `index` dihapus: baris di bawahnya, atau yang terakhir bila itu baris terakhir.
export const selectionAfterRemoval = (index, newLength) => (newLength === 0 ? -1 : Math.min(index, newLength - 1));

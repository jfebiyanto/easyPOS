// Isi panel pelanggan di atas keranjang. Tanpa pilihan kasir, penjualan dicatat atas nama pelanggan
// retail default gerai (master gerai), jadi panel menampilkan pelanggan yang benar-benar akan tercatat.
export function customerPanel(selected, defaultCustomer) {
    const customer = selected || defaultCustomer;

    if (!customer) {
        return { code: null, name: 'Tanpa pelanggan', isSelected: false, isDefault: false };
    }

    return {
        code: customer.code || 'Kode belum ada',
        name: customer.name,
        isSelected: Boolean(selected),
        isDefault: !selected,
    };
}

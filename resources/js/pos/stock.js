// Produk dengan stok terlacak yang sudah habis tidak bisa dijual, kecuali perusahaan mengizinkan stok negatif.
export const isOutOfStock = (product, allowNegativeStock = false) =>
    !allowNegativeStock && Boolean(product?.track_stock) && Number(product?.stock ?? 0) <= 0;

// Batas qty maksimum yang boleh ada di keranjang untuk satu produk (Infinity = tidak dibatasi).
export const maxQty = (line, allowNegativeStock = false) =>
    !allowNegativeStock && line?.track_stock ? Math.max(0, Math.floor(Number(line.stock ?? 0))) : Infinity;

// Sesuaikan keranjang hold dengan stok terkini: qty dipotong ke sisa stok, item yang stoknya habis dibuang.
// `stock` = { product_id: saldo | null }; produk yang tidak ada di peta memakai stok yang tersimpan di baris.
export function fitCartToStock(cart, stock = {}, allowNegativeStock = false) {
    const lines = [];
    const adjustments = [];

    for (const line of cart) {
        const fresh = line.id in stock ? { ...line, stock: stock[line.id], track_stock: stock[line.id] !== null } : line;
        const limit = maxQty(fresh, allowNegativeStock);

        if (fresh.qty > limit) {
            adjustments.push({ name: line.name, from: line.qty, to: limit });
        }
        if (limit > 0) {
            lines.push({ ...fresh, qty: Math.min(fresh.qty, limit) });
        }
    }

    return { lines, adjustments };
}

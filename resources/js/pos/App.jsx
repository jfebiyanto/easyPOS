import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, uuid } from './api.js';
import { printReceipt, connectUsbPrinter, hasUsbPrinter } from './printer.js';
import { enqueue, readQueue, removeFromQueue, writeQueue } from './offline.js';
import { activePromotions, applyPromotions, readCachedPromotions, writeCachedPromotions } from './promotions.js';
import { formatBusinessClock, formatIsoDate, serverOffset } from './businessClock.js';
import { fitCartToStock, isOutOfStock, maxQty } from './stock.js';
import { customerPanel } from './customer.js';

const fmt = (n, currency = 'IDR') =>
    (currency === 'IDR' ? 'Rp ' : currency + ' ') +
    Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });

// Baris keranjang + hasil promosi (discount, promotion_id, promotion_name). Keranjang di state tetap
// mentah agar promosi dihitung ulang setiap kali keranjang/promosi berubah, termasuk saat hold dilanjutkan.
function priceCart(cart, promotions, at = new Date()) {
    const result = applyPromotions(
        cart.map((l) => ({ product_id: l.id, qty: l.qty, unit_price: l.unit_price })),
        activePromotions(promotions, at),
    );

    return {
        lines: cart.map((l, i) => ({
            ...l,
            discount: result.lines[i].promotion_discount,
            promotion_id: result.lines[i].promotion_id,
            promotion_name: result.lines[i].promotion_name,
        })),
        bonusHints: result.bonus_hints,
    };
}

function computeTotals(lines, company, shipping) {
    const rate = Number(company?.ppn_rate || 0);
    const inclusive = !!company?.ppn_inclusive;
    const enabled = !!company?.ppn_enabled; // PPN 0% tetap PPN

    let subtotal = 0;
    let taxSum = 0;
    let discountTotal = 0;
    const lineTotals = [];

    lines.forEach((l) => {
        const base = Math.max(0, l.unit_price * l.qty - (l.discount || 0));
        const tax = enabled ? (inclusive ? base - base / (1 + rate / 100) : (base * rate) / 100) : 0;
        subtotal += base;
        taxSum += tax;
        discountTotal += l.discount || 0;
        lineTotals.push(Math.round((inclusive ? base : base + tax) * 100) / 100);
    });

    const taxTotal = Math.round(taxSum * 100) / 100;
    const total = Math.round((subtotal + (inclusive ? 0 : taxTotal) + (shipping || 0)) * 100) / 100;

    return { subtotal: Math.round(subtotal * 100) / 100, taxTotal, total, discountTotal: Math.round(discountTotal * 100) / 100, lineTotals };
}

const HOLDS_KEY = 'easypos.pos.holds';
const ACTIVE_HOLD_KEY = 'easypos.pos.activeHold';

const readLocalHolds = () => JSON.parse(localStorage.getItem(HOLDS_KEY) || '[]');
const writeLocalHolds = (holds) => localStorage.setItem(HOLDS_KEY, JSON.stringify(holds));
const withoutHold = (holds, clientUuid) => holds.filter((h) => h.client_uuid !== clientUuid);

const readActiveHold = () => JSON.parse(localStorage.getItem(ACTIVE_HOLD_KEY) || 'null');
const writeActiveHold = (hold) => (hold ? localStorage.setItem(ACTIVE_HOLD_KEY, JSON.stringify(hold)) : localStorage.removeItem(ACTIVE_HOLD_KEY));

// Kirim hold yang dibuat saat offline ke server. 409 berarti hold sudah final/bukan milik kasir ini, jadi dibuang.
async function syncLocalHolds() {
    for (const h of readLocalHolds()) {
        try {
            await api.saveHold({ client_uuid: h.client_uuid, device_label: navigator.platform, payload: h.payload });
        } catch (e) {
            if (e.status !== 409) return;
        }
        writeLocalHolds(withoutHold(readLocalHolds(), h.client_uuid));
    }
}

export default function App() {
    const [boot, setBoot] = useState(null);
    const [bootError, setBootError] = useState(null);
    const [storeChangeMessage, setStoreChangeMessage] = useState(null);
    const [products, setProducts] = useState([]);
    const [query, setQuery] = useState('');
    const [cart, setCart] = useState([]);
    const [online, setOnline] = useState(navigator.onLine);
    const [queue, setQueue] = useState(readQueue());
    const [heldCount, setHeldCount] = useState(0);
    const [notice, setNotice] = useState(null);
    const [showPayment, setShowPayment] = useState(false);
    const [showHolds, setShowHolds] = useState(false);
    const [showReturn, setShowReturn] = useState(false);
    const [showDevice, setShowDevice] = useState(false);
    const [showCustomer, setShowCustomer] = useState(false);
    const [customer, setCustomer] = useState(null);
    const [receipt, setReceipt] = useState(null);
    const [promotions, setPromotions] = useState(readCachedPromotions);
    const scanRef = useRef(null);

    const currency = boot?.company?.currency || 'IDR';
    const allowNegativeStock = Boolean(boot?.company?.allow_negative_stock);

    const loadProducts = useCallback(async (q = '') => {
        try {
            const res = await api.products(q);
            setProducts(res.data);
        } catch (e) {
            setNotice({ type: 'error', text: e.message });
        }
    }, []);

    // Gagal memuat (offline) berarti tetap memakai salinan terakhir di localStorage.
    const loadPromotions = useCallback(async () => {
        try {
            const res = await api.promotions();
            setPromotions(res.data);
            writeCachedPromotions(res.data);
        } catch {
            /* pakai cache */
        }
    }, []);

    const refreshHeldCount = useCallback(async () => {
        const local = readLocalHolds();
        if (navigator.onLine) {
            try {
                const res = await api.holds();
                const serverUuids = new Set(res.data.map((d) => d.client_uuid));
                setHeldCount(res.data.length + local.filter((h) => !serverUuids.has(h.client_uuid)).length);
                return;
            } catch {
                /* fall through ke hitungan lokal */
            }
        }
        setHeldCount(local.length);
    }, []);

    // Hold yang sedang dilanjutkan di keranjang. Disimpan di localStorage agar bisa
    // dikembalikan ke daftar tahan bila halaman dimuat ulang sebelum jadi penjualan.
    const [activeHold, setActiveHoldState] = useState(readActiveHold);
    const setActiveHold = useCallback((hold) => {
        writeActiveHold(hold);
        setActiveHoldState(hold);
    }, []);

    const releaseActiveHold = useCallback(async (hold) => {
        if (hold.local) {
            writeLocalHolds([...withoutHold(readLocalHolds(), hold.client_uuid), { id: hold.client_uuid, client_uuid: hold.client_uuid, payload: hold.payload, updated_at: new Date().toISOString() }]);
        } else {
            if (!navigator.onLine) return;
            try {
                await api.releaseHold(hold.id);
            } catch {
                return;
            }
        }
        setActiveHold(null);
        refreshHeldCount();
    }, [setActiveHold, refreshHeldCount]);

    useEffect(() => {
        if (activeHold && cart.length === 0) {
            releaseActiveHold(activeHold);
            setCustomer(null);
        }
    }, [activeHold, cart.length, online, releaseActiveHold]);

    useEffect(() => {
        (async () => {
            try {
                const res = await api.bootstrap();
                setBoot(res);
                await Promise.all([loadProducts(''), loadPromotions()]);
                await syncLocalHolds();
                refreshHeldCount();
            } catch (e) {
                setBootError(e.message);
                setNotice({ type: 'error', text: e.message });
            }
        })();
    }, [loadProducts, loadPromotions, refreshHeldCount]);

    // Status kunci EOD, pelanggan default, dan penugasan gerai bisa berubah dari admin; segarkan berkala selama online.
    useEffect(() => {
        if (!boot || !online) return undefined;
        const timer = setInterval(async () => {
            try {
                const res = await api.bootstrap();
                setBoot((prev) => ({ ...prev, business: res.business, default_customer: res.default_customer, prompt: res.prompt }));
                if (res.store.id !== boot.store.id) {
                    setStoreChangeMessage(`Anda dipindahkan ke gerai ${res.store.code} · ${res.store.name}.`);
                }
            } catch (e) {
                // 422 = gerai tidak lagi bisa dipakai; kegagalan lain tetap memakai status terakhir.
                if (e.status === 422) {
                    setStoreChangeMessage(e.message);
                }
            }
        }, 60000);
        return () => clearInterval(timer);
    }, [boot !== null, online]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const on = () => { setOnline(true); flushQueue(); loadPromotions(); syncLocalHolds().then(refreshHeldCount); };
        const off = () => setOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);
        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
        // eslint-disable-next-line
    }, [boot]);

    const flushQueue = useCallback(async () => {
        const pending = readQueue();
        if (pending.length === 0) return;
        for (const item of pending) {
            try {
                await api.createSale({ ...item, origin: 'offline' });
                removeFromQueue(item.client_uuid);
            } catch {
                break;
            }
        }
        setQueue(readQueue());
    }, []);

    // Antrean tersisa dari sesi sebelumnya tetap dikirim saat dibuka, termasuk bila gerai kasir sudah berubah.
    useEffect(() => {
        if (navigator.onLine) flushQueue();
    }, [flushQueue]);

    const addProduct = (product, qty = 1) => {
        if (isOutOfStock(product, allowNegativeStock)) {
            setNotice({ type: 'error', text: `Stok "${product.name}" habis.` });
            return;
        }
        const limit = maxQty(product, allowNegativeStock);
        const current = cart.find((l) => l.id === product.id)?.qty || 0;
        const nextQty = Math.min(current + qty, limit);
        if (current + qty > limit) {
            setNotice({ type: 'warning', text: `Stok "${product.name}" tersisa ${limit}.` });
        }
        if (nextQty > current) {
            setCart((prev) => {
                const found = prev.find((l) => l.id === product.id);
                if (found) {
                    return prev.map((l) => (l.id === product.id ? { ...l, qty: nextQty, stock: product.stock } : l));
                }
                return [...prev, { id: product.id, sku: product.sku, name: product.name, unit_price: product.price, qty: nextQty, track_stock: product.track_stock, stock: product.stock }];
            });
        }
        setQuery('');
        scanRef.current?.focus();
    };

    const onScan = (e) => {
        if (e.key !== 'Enter') return;
        const term = query.trim();
        if (!term) return;
        const match = products.find((p) => p.barcode === term || p.sku.toLowerCase() === term.toLowerCase());
        if (match) {
            addProduct(match);
        } else {
            setNotice({ type: 'error', text: `Produk "${term}" tidak ditemukan.` });
        }
    };

    const setQty = (id, qty) => {
        const line = cart.find((l) => l.id === id);
        if (!line) return;
        const limit = maxQty(line, allowNegativeStock);
        if (qty > limit) {
            setNotice({ type: 'warning', text: `Stok "${line.name}" tersisa ${limit}.` });
        }
        const nextQty = Math.max(1, Math.min(qty, limit));
        setCart((prev) => prev.map((l) => (l.id === id ? { ...l, qty: nextQty } : l)));
    };
    const removeLine = (id) => setCart((prev) => prev.filter((l) => l.id !== id));

    const [delivery, setDelivery] = useState({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });

    const priced = useMemo(() => priceCart(cart, promotions), [cart, promotions]);

    const totals = useMemo(
        () => computeTotals(priced.lines, boot?.company, delivery.enabled ? Number(delivery.cost || 0) : 0),
        [priced, boot, delivery],
    );

    const bonusHints = priced.bonusHints.map((hint) => {
        const promotion = promotions.find((p) => p.id === hint.promotion_id);
        const product = promotion?.bonus_product || products.find((p) => p.id === hint.product_id);
        return { ...hint, product };
    });

    // Promosi dihitung ulang tepat pada waktu transaksi agar sama dengan perhitungan server.
    const buildPayload = (origin, payments) => {
        const soldAt = new Date();
        return {
            client_uuid: uuid(),
            store_id: boot.store.id,
            origin,
            sold_at: soldAt.toISOString(),
            customer_id: customer?.id || null,
            shipping_cost: delivery.enabled ? Number(delivery.cost || 0) : 0,
            lines: priceCart(cart, promotions, soldAt).lines.map((l) => ({
                product_id: l.id,
                qty: l.qty,
                unit_price: l.unit_price,
                discount: l.discount,
                promotion_id: l.promotion_id,
            })),
            payments,
            delivery: delivery.enabled
                ? { recipient_name: delivery.recipient_name, phone: delivery.phone, address: delivery.address, note: delivery.note }
                : null,
            hold_client_uuid: activeHold?.client_uuid || null,
        };
    };

    const finalizeSale = async (payments) => {
        const payload = buildPayload(online ? 'online' : 'offline', payments);

        if (!online) {
            enqueue(payload);
            setQueue(readQueue());
            const receiptLines = priced.lines.map((l, i) => ({ ...l, line_total: totals.lineTotals[i] }));
            setReceipt({ number: 'OFFLINE', total: totals.total, paid_total: payments.reduce((s, p) => s + Number(p.amount || 0), 0), change_total: 0, lines: receiptLines, subtotal: totals.subtotal, discount_total: totals.discountTotal, tax_total: totals.taxTotal, shipping_cost: payload.shipping_cost, currency, origin: 'offline' });
            setActiveHold(null);
            setCart([]);
            setCustomer(null);
            setShowPayment(false);
            setDelivery({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });
            setNotice({ type: 'warning', text: 'Offline: transaksi disimpan & akan disinkron otomatis.' });
            return;
        }

        try {
            const res = await api.createSale(payload);
            setReceipt(res.data);
            setActiveHold(null);
            setCart([]);
            setCustomer(null);
            setShowPayment(false);
            setDelivery({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });
            setNotice({ type: 'success', text: `Transaksi ${res.data.number} tersimpan.` });
            loadProducts(query); // segarkan stok agar produk yang baru habis langsung nonaktif
            printReceipt(res.data, meta());
        } catch (e) {
            // 409: promosi di server berbeda (mis. baru diubah admin). Muat ulang promosi agar
            // keranjang dihitung ulang, lalu kasir memeriksa total baru sebelum membayar lagi.
            if (e.status === 409) {
                await loadPromotions();
                setShowPayment(false);
                setNotice({ type: 'warning', text: e.message });
                return;
            }
            setNotice({ type: 'error', text: e.message });
        }
    };

    const meta = () => ({
        company: boot?.company?.name,
        store: boot?.store?.code + ' · ' + boot?.store?.name,
        cashier: boot?.cashier?.name,
    });

    const hold = async () => {
        if (cart.length === 0) return;
        // Menahan ulang hold yang sedang dilanjutkan memakai client_uuid yang sama agar tidak terduplikasi.
        const clientUuid = activeHold?.client_uuid || uuid();
        try {
            if (online) {
                await api.saveHold({ client_uuid: clientUuid, device_label: navigator.platform, payload: { cart, delivery, customer } });
            } else {
                writeLocalHolds([...withoutHold(readLocalHolds(), clientUuid), { id: clientUuid, client_uuid: clientUuid, payload: { cart, delivery, customer }, updated_at: new Date().toISOString() }]);
            }
            setActiveHold(null);
            setCart([]);
            setCustomer(null);
            setDelivery({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });
            refreshHeldCount();
            setNotice({ type: 'success', text: 'Transaksi ditahan. Buka "Daftar Tahan" untuk melanjutkan.' });
        } catch (e) {
            setNotice({ type: 'error', text: e.message });
        }
    };

    if (!boot) {
        return (
            <div className="pos-loading">
                {bootError ? (
                    <div className="pos-boot-error">
                        <p>{bootError}</p>
                        <LogoutForm />
                    </div>
                ) : 'Memuat POS…'}
            </div>
        );
    }

    const customerInfo = customerPanel(customer, boot.default_customer);

    return (
        <div className="pos">
            <header className="pos-topbar">
                <div className="pos-store">
                    <strong>{boot.store?.code}</strong>
                    <span>{boot.store?.name}</span>
                </div>
                <div className="pos-status">
                    <span className={`chip ${online ? 'chip-success' : 'chip-offline'}`}>
                        <span className="material-symbols-rounded">{online ? 'wifi' : 'wifi_off'}</span>
                        {online ? 'Online' : 'Offline'}
                    </span>
                    {queue.length > 0 && <span className="chip chip-pending">{queue.length} tertunda</span>}
                </div>
                <div className="pos-actions">
                    <button className="btn btn-tonal btn-sm" onClick={() => setShowCustomer(true)}>Pelanggan</button>
                    <button className="btn btn-tonal btn-sm" onClick={() => setShowHolds(true)}>
                        Daftar Tahan{heldCount > 0 ? ` (${heldCount})` : ''}
                    </button>
                    <button className="btn btn-tonal btn-sm" onClick={() => setShowReturn(true)}>Retur</button>
                    <button className="btn btn-outline btn-sm" onClick={() => setShowDevice(true)}>Perangkat</button>
                    <span className="pos-cashier">{boot.cashier?.name}</span>
                    <LogoutForm />
                </div>
            </header>

            {notice && (
                <div className={`md-snackbar ${notice.type === 'success' ? 'success' : notice.type === 'error' ? 'error' : 'info'}`} onClick={() => setNotice(null)}>
                    {notice.text}
                </div>
            )}

            <div className="pos-body">
                <section className="pos-catalog">
                    <input
                        ref={scanRef}
                        className="pos-scan"
                        autoFocus
                        placeholder="Scan barcode / cari nama atau SKU lalu Enter…"
                        value={query}
                        onChange={(e) => { setQuery(e.target.value); loadProducts(e.target.value); }}
                        onKeyDown={onScan}
                    />
                    <div className="pos-grid">
                        {products.map((p) => {
                            const outOfStock = isOutOfStock(p, allowNegativeStock);
                            return (
                                <button key={p.id} className="pos-tile" onClick={() => addProduct(p)} disabled={outOfStock} title={outOfStock ? 'Stok habis' : undefined}>
                                    <ProductThumb product={p} />
                                    <span className="pos-tile-name">{p.name}</span>
                                    <span className="pos-tile-sku sku">{p.sku}</span>
                                    <span className="pos-tile-price money">{fmt(p.price, currency)}</span>
                                    {p.track_stock && <span className="pos-tile-stock">{outOfStock ? 'Stok habis' : `stok ${p.stock}`}</span>}
                                </button>
                            );
                        })}
                        {products.length === 0 && <div className="empty-state">Tidak ada produk.</div>}
                    </div>
                </section>

                <aside className="pos-cart">
                    <div className="pos-customer">
                        <span className="material-symbols-rounded">person</span>
                        <div className="pos-customer-info">
                            {customerInfo.code && <span className="pos-customer-code sku">{customerInfo.code}</span>}
                            <strong title={customerInfo.name}>{customerInfo.name}</strong>
                            {customerInfo.isDefault && <span className="chip">Default</span>}
                        </div>
                        <button className="btn btn-tonal btn-sm" onClick={() => setShowCustomer(true)}>
                            {customerInfo.isSelected ? 'Ganti' : 'Pilih'}
                        </button>
                        {customerInfo.isSelected && (
                            <button className="pos-line-remove" onClick={() => setCustomer(null)} title="Hapus pelanggan">
                                <span className="material-symbols-rounded">close</span>
                            </button>
                        )}
                    </div>
                    <div className="pos-cart-lines">
                        {cart.length === 0 && <div className="empty-state">Keranjang kosong. Scan atau pilih produk.</div>}
                        {priced.lines.map((l) => (
                            <div key={l.id} className="pos-line">
                                <div className="pos-line-info">
                                    <strong>{l.name}</strong>
                                    <span className="money">{fmt(l.unit_price, currency)}</span>
                                    {l.promotion_id && (
                                        <span className="pos-line-promo">
                                            <span className="material-symbols-rounded">sell</span>
                                            {l.promotion_name} <span className="money">−{fmt(l.discount, currency)}</span>
                                        </span>
                                    )}
                                </div>
                                <div className="pos-line-qty">
                                    <button onClick={() => setQty(l.id, l.qty - 1)}>−</button>
                                    <input value={l.qty} onChange={(e) => setQty(l.id, Number(e.target.value) || 1)} />
                                    <button onClick={() => setQty(l.id, l.qty + 1)}>+</button>
                                </div>
                                <div className="pos-line-total money">{fmt(l.unit_price * l.qty - l.discount, currency)}</div>
                                <button className="pos-line-remove" onClick={() => removeLine(l.id)} title="Hapus">
                                    <span className="material-symbols-rounded">close</span>
                                </button>
                            </div>
                        ))}
                        {bonusHints.map((hint) => (
                            <div key={hint.promotion_id} className="pos-bonus-hint">
                                <span>
                                    <span className="material-symbols-rounded">redeem</span>
                                    Bonus tersedia: {hint.qty} × {hint.product?.name || 'produk bonus'} <small>({hint.promotion_name})</small>
                                </span>
                                {hint.product && (
                                    <button className="btn btn-tonal btn-sm" onClick={() => addProduct(hint.product, hint.qty)}>Tambah</button>
                                )}
                            </div>
                        ))}
                    </div>

                    <div className="pos-totals">
                        {totals.discountTotal > 0 && <div className="pos-saving"><span>Hemat</span><span className="money">−{fmt(totals.discountTotal, currency)}</span></div>}
                        <div><span>Subtotal</span><span className="money">{fmt(totals.subtotal, currency)}</span></div>
                        <div><span>PPN{boot?.company?.tax_group_code ? ` (${boot.company.tax_group_code})` : ''}</span><span className="money">{fmt(totals.taxTotal, currency)}</span></div>
                        {delivery.enabled && <div><span>Kirim</span><span className="money">{fmt(delivery.cost, currency)}</span></div>}
                        <div className="pos-grand"><span>TOTAL</span><span className="money">{fmt(totals.total, currency)}</span></div>
                    </div>

                    <div className="pos-cart-actions">
                        <button className="btn btn-tonal" onClick={hold} disabled={cart.length === 0}>Tahan</button>
                        <button className="btn btn-filled pos-pay" onClick={() => setShowPayment(true)} disabled={cart.length === 0}>
                            Bayar
                        </button>
                    </div>
                </aside>
            </div>

            <BusinessBar business={boot.business} />

            {showPayment && (
                <PaymentModal
                    boot={boot}
                    currency={currency}
                    totals={totals}
                    delivery={delivery}
                    setDelivery={setDelivery}
                    onClose={() => setShowPayment(false)}
                    onConfirm={finalizeSale}
                />
            )}

            {receipt && <ReceiptModal sale={receipt} currency={currency} meta={meta()} onClose={() => setReceipt(null)} />}

            {showHolds && (
                <HoldsModal
                    online={online}
                    cartEmpty={cart.length === 0}
                    onClose={() => { setShowHolds(false); refreshHeldCount(); }}
                    onResume={(item, stock) => {
                        // Stok tidak dipotong saat ditahan, jadi cocokkan ulang dengan stok terkini agar tidak minus.
                        // Hold lokal (offline) memakai stok dari daftar produk terakhir yang dimuat.
                        const heldCart = item.payload.cart || [];
                        const knownStock = stock || Object.fromEntries(
                            products.filter((p) => heldCart.some((l) => l.id === p.id)).map((p) => [p.id, p.track_stock ? p.stock : null]),
                        );
                        const { lines, adjustments } = fitCartToStock(heldCart, knownStock, allowNegativeStock);
                        setActiveHold(item.local ? { local: true, client_uuid: item.client_uuid, payload: item.payload } : { id: item.id, client_uuid: item.client_uuid });
                        setCart(lines);
                        if (adjustments.length > 0) {
                            setNotice({
                                type: 'warning',
                                text: 'Stok berubah sejak ditahan: ' + adjustments
                                    .map((a) => (a.to > 0 ? `${a.name} ${a.from} → ${a.to}` : `${a.name} dihapus (stok habis)`))
                                    .join(', '),
                            });
                        }
                        setDelivery(item.payload.delivery || delivery);
                        setCustomer(item.payload.customer || null);
                        setShowHolds(false);
                        refreshHeldCount();
                    }}
                />
            )}

            {showReturn && <ReturnModal onClose={() => setShowReturn(false)} onDone={(msg) => { setNotice({ type: 'success', text: msg }); setShowReturn(false); }} onError={(msg) => setNotice({ type: 'error', text: msg })} />}

            {showDevice && <DeviceModal onClose={() => setShowDevice(false)} />}

            {(boot.prompt?.greeting || boot.prompt?.backdate_date) && (
                <SessionPromptModal
                    prompt={boot.prompt}
                    name={boot.cashier?.name}
                    onAcknowledge={async () => {
                        try {
                            const res = await api.acknowledgeSession();
                            setBoot((prev) => ({ ...prev, prompt: res.data }));
                        } catch (e) {
                            setNotice({ type: 'error', text: e.message });
                        }
                    }}
                    onDecline={async () => {
                        await api.logout().catch(() => {});
                        window.location.href = '/login';
                    }}
                />
            )}

            {storeChangeMessage && (
                <div className="pos-modal">
                    <div className="pos-modal-card">
                        <h2>Penugasan gerai berubah</h2>
                        <p>{storeChangeMessage} Muat ulang POS untuk melanjutkan. Keranjang yang belum selesai tidak disimpan, sedangkan transaksi offline yang tertunda tetap tercatat di gerai asalnya.</p>
                        <div className="pos-modal-actions">
                            <button className="btn btn-filled" onClick={() => window.location.reload()}>Muat Ulang</button>
                        </div>
                    </div>
                </div>
            )}

            {showCustomer && (
                <CustomerModal
                    onClose={() => setShowCustomer(false)}
                    onSelect={(c) => { setCustomer(c); setShowCustomer(false); }}
                />
            )}
        </div>
    );
}

// Status bar bawah: tanggal bisnis + jam (zona waktu perusahaan) dan peringatan bila EOD tertunda.
function BusinessBar({ business }) {
    const offset = useMemo(() => serverOffset(business?.server_time), [business?.server_time]);
    const [now, setNow] = useState(() => new Date(Date.now() + offset));

    useEffect(() => {
        const tick = () => setNow(new Date(Date.now() + offset));
        tick();
        const timer = setInterval(tick, 1000);
        return () => clearInterval(timer);
    }, [offset]);

    if (!business) return null;

    const clock = formatBusinessClock(now, business.timezone);

    return (
        <footer className="pos-statusbar">
            <span className="pos-statusbar-item">
                <span className="material-symbols-rounded">event</span>
                Business Date: <strong>{business.posting_date && business.posting_date !== clock.isoDate ? formatIsoDate(business.posting_date) : clock.date}</strong>
            </span>
            <span className="pos-statusbar-item">
                <span className="material-symbols-rounded">schedule</span>
                <strong className="money">{clock.time}</strong>
            </span>
            {business.closed && (
                <span className="pos-statusbar-item pos-statusbar-warning">
                    <span className="material-symbols-rounded">lock</span>
                    EOD hari ini sudah ditutup — transaksi online terkunci
                </span>
            )}
            {business.locked_date && (
                <span className="pos-statusbar-item pos-statusbar-warning">
                    <span className="material-symbols-rounded">lock</span>
                    EOD {formatIsoDate(business.locked_date)} belum diselesaikan — transaksi dicatat sebagai tanggal itu
                </span>
            )}
        </footer>
    );
}

function ProductThumb({ product }) {
    const [failed, setFailed] = useState(false);

    if (product.image && !failed) {
        return (
            <img
                src={product.image}
                alt={product.name}
                className="pos-tile-img"
                loading="lazy"
                onError={() => setFailed(true)}
            />
        );
    }

    return <span className="pos-tile-thumb">{(product.name || '?').charAt(0).toUpperCase()}</span>;
}

function PaymentModal({ boot, currency, totals, delivery, setDelivery, onClose, onConfirm }) {
    const [rows, setRows] = useState([{ payment_type_id: boot.payment_types[0]?.id || '', amount: totals.total, status: 'confirmed' }]);
    const paid = rows.reduce((s, r) => s + Number(r.amount || 0), 0);
    const change = Math.max(0, paid - totals.total);
    const hasPending = rows.some((r) => r.status === 'pending');

    const setRow = (i, patch) => setRows((prev) => prev.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Pembayaran</h2>
                <div className="pos-grand money">{fmt(totals.total, currency)}</div>

                <div className="pos-pay-rows">
                    {rows.map((r, i) => (
                        <div className="pos-pay-row" key={i}>
                            <select value={r.payment_type_id} onChange={(e) => setRow(i, { payment_type_id: e.target.value })}>
                                {boot.payment_types.map((pt) => (
                                    <option key={pt.id} value={pt.id}>{pt.code} · {pt.description}</option>
                                ))}
                            </select>
                            <input type="number" value={r.amount} onChange={(e) => setRow(i, { amount: Number(e.target.value) })} />
                            <select value={r.status} onChange={(e) => setRow(i, { status: e.target.value })}>
                                <option value="confirmed">Lunas</option>
                                <option value="pending">Pending</option>
                            </select>
                            {rows.length > 1 && <button className="icon-btn" onClick={() => setRows(rows.filter((_, idx) => idx !== i))}>×</button>}
                        </div>
                    ))}
                </div>

                <button className="btn btn-tonal btn-sm" onClick={() => setRows([...rows, { payment_type_id: boot.payment_types[0]?.id || '', amount: 0, status: 'confirmed' }])}>
                    + Metode lain
                </button>

                <div className="pos-pay-summary">
                    <div><span>Dibayar</span><span className="money">{fmt(paid, currency)}</span></div>
                    <div><span>Kembali</span><span className="money">{fmt(change, currency)}</span></div>
                </div>

                <label className="pos-checkbox">
                    <input type="checkbox" checked={delivery.enabled} onChange={(e) => setDelivery({ ...delivery, enabled: e.target.checked })} />
                    Kirim ke alamat pelanggan
                </label>

                {delivery.enabled && (
                    <div className="pos-delivery">
                        <input placeholder="Nama penerima" value={delivery.recipient_name} onChange={(e) => setDelivery({ ...delivery, recipient_name: e.target.value })} />
                        <input placeholder="Telepon" value={delivery.phone} onChange={(e) => setDelivery({ ...delivery, phone: e.target.value })} />
                        <input placeholder="Alamat" value={delivery.address} onChange={(e) => setDelivery({ ...delivery, address: e.target.value })} />
                        <input type="number" placeholder="Biaya kirim" value={delivery.cost} onChange={(e) => setDelivery({ ...delivery, cost: Number(e.target.value) })} />
                    </div>
                )}

                {hasPending && <div className="pos-pending-note">Ada pembayaran pending — akan dikonfirmasi setelah tersinkron.</div>}

                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Batal</button>
                    <button className="btn btn-filled" onClick={() => onConfirm(rows)} disabled={paid <= 0}>Konfirmasi</button>
                </div>
            </div>
        </div>
    );
}

function ReceiptModal({ sale, currency, meta, onClose }) {
    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Struk {sale.number}</h2>
                <div className="pos-receipt">
                    {sale.lines?.map((l, i) => (
                        <React.Fragment key={i}>
                            <div className="pos-receipt-line">
                                <span>{l.qty}× {l.name}</span>
                                <span className="money">{fmt(l.line_total, currency)}</span>
                            </div>
                            {l.discount > 0 && (
                                <div className="pos-receipt-line pos-receipt-promo">
                                    <span>{l.promotion_name || 'Diskon'}</span>
                                    <span className="money">−{fmt(l.discount, currency)}</span>
                                </div>
                            )}
                        </React.Fragment>
                    ))}
                    {sale.discount_total > 0 && <div className="pos-saving"><span>Hemat</span><span className="money">−{fmt(sale.discount_total, currency)}</span></div>}
                    <div className="pos-grand"><span>TOTAL</span><span className="money">{fmt(sale.total, currency)}</span></div>
                    <div><span>Bayar</span><span className="money">{fmt(sale.paid_total, currency)}</span></div>
                    <div><span>Kembali</span><span className="money">{fmt(sale.change_total, currency)}</span></div>
                    {sale.points_earned > 0 && (
                        <div><span>Poin diperoleh</span><span className="money">+{sale.points_earned}</span></div>
                    )}
                </div>
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Tutup</button>
                    <button className="btn btn-filled" onClick={() => printReceipt(sale, meta)}>Cetak</button>
                </div>
            </div>
        </div>
    );
}

function HoldsModal({ online, cartEmpty, onClose, onResume }) {
    const [items, setItems] = useState([]);
    const [error, setError] = useState(null);

    useEffect(() => {
        (async () => {
            // Hold lokal (dibuat offline, belum tersinkron) selalu ikut ditampilkan.
            const local = readLocalHolds().map((h) => ({ ...h, local: true }));
            if (online) {
                try {
                    const res = await api.holds();
                    const server = res.data.map((d) => ({ id: d.id, client_uuid: d.client_uuid, payload: d.payload, updated_at: d.updated_at, local: false }));
                    const serverUuids = new Set(server.map((h) => h.client_uuid));
                    setItems([...server, ...local.filter((h) => !serverUuids.has(h.client_uuid))]);
                    return;
                } catch { /* fall through */ }
            }
            setItems(local);
        })();
    }, [online]);

    const cancel = async (item) => {
        setError(null);
        if (!item.local) {
            try {
                await api.deleteHold(item.id);
            } catch (e) {
                setError(e.message);
                return;
            }
        }
        setItems((prev) => withoutHold(prev, item.client_uuid));
        writeLocalHolds(withoutHold(readLocalHolds(), item.client_uuid));
    };

    const resume = async (item) => {
        setError(null);
        if (!cartEmpty) {
            setError('Keranjang masih berisi. Selesaikan atau tahan transaksi saat ini dulu.');
            return;
        }
        let stock = null;
        if (!item.local) {
            try {
                const res = await api.consumeHold(item.id);
                stock = res.data?.stock || null;
            } catch (e) {
                setError(e.message);
                return;
            }
        }
        onResume(item, stock);
        writeLocalHolds(withoutHold(readLocalHolds(), item.client_uuid));
    };

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Transaksi Ditahan</h2>
                <p className="card-subtitle" style={{ marginTop: 0 }}>
                    Hanya menampilkan tahan milik akun kasir yang sedang login.
                </p>
                {error && <div className="md-snackbar error">{error}</div>}
                {items.length === 0 && <div className="empty-state">Tidak ada transaksi ditahan.</div>}
                {items.map((it) => (
                    <div key={it.client_uuid} className="pos-hold-row">
                        <span>{(it.payload?.cart || []).length} item · {new Date(it.updated_at || Date.now()).toLocaleTimeString('id-ID')}</span>
                        <div>
                            <button className="btn btn-filled btn-sm" onClick={() => resume(it)}>Lanjutkan</button>
                            <button className="btn btn-outline btn-sm" onClick={() => cancel(it)}>Hapus</button>
                        </div>
                    </div>
                ))}
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Tutup</button>
                </div>
            </div>
        </div>
    );
}

function ReturnModal({ onClose, onDone, onError }) {
    const [number, setNumber] = useState('');
    const [sale, setSale] = useState(null);
    const [qtys, setQtys] = useState({});
    const [refund, setRefund] = useState('cash');

    const lookup = async () => {
        try {
            const res = await api.lookupSale(number);
            setSale(res.data);
            setQtys({});
        } catch (e) {
            onError(e.message);
        }
    };

    const submit = async () => {
        const lines = Object.entries(qtys)
            .filter(([, q]) => Number(q) > 0)
            .map(([sale_line_id, qty]) => ({ sale_line_id, qty: Number(qty) }));
        if (lines.length === 0) {
            onError('Pilih qty retur.');
            return;
        }
        try {
            const res = await api.createReturn({ sale_id: sale.id, refund_method: refund, lines });
            onDone(`Retur ${res.data.number} berhasil.`);
        } catch (e) {
            onError(e.message);
        }
    };

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Retur Penjualan</h2>
                <div className="pos-pay-row">
                    <input placeholder="Nomor struk" value={number} onChange={(e) => setNumber(e.target.value)} />
                    <button className="btn btn-tonal" onClick={lookup}>Cari</button>
                </div>

                {sale && (
                    <>
                        <div className="pos-receipt">
                            {sale.lines.map((l) => (
                                <div key={l.sale_line_id} className="pos-return-line">
                                    <span>{l.sku} · {l.name} <small>(sisa {l.returnable})</small></span>
                                    <input type="number" min="0" max={l.returnable} value={qtys[l.sale_line_id] || ''} onChange={(e) => setQtys({ ...qtys, [l.sale_line_id]: e.target.value })} />
                                </div>
                            ))}
                        </div>
                        <select value={refund} onChange={(e) => setRefund(e.target.value)}>
                            <option value="cash">Tunai</option>
                            <option value="transfer">Transfer</option>
                            <option value="none">Tanpa refund</option>
                        </select>
                    </>
                )}

                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Batal</button>
                    <button className="btn btn-filled" onClick={submit} disabled={!sale}>Proses Retur</button>
                </div>
            </div>
        </div>
    );
}

function DeviceModal({ onClose }) {
    const [status, setStatus] = useState(hasUsbPrinter() ? 'Printer USB tersambung' : 'Belum ada printer USB');

    const connect = async () => {
        try {
            const name = await connectUsbPrinter();
            setStatus(`Tersambung: ${name}`);
        } catch (e) {
            setStatus(`Gagal: ${e.message}. Cetak akan memakai dialog HTML.`);
        }
    };

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Perangkat</h2>
                <p className="card-subtitle">{status}</p>
                <p className="card-subtitle">Jika WebUSB tidak tersedia, struk dicetak via dialog HTML (fallback).</p>
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Tutup</button>
                    <button className="btn btn-filled" onClick={connect}>Sambungkan Printer USB</button>
                </div>
            </div>
        </div>
    );
}

// Sapaan login + tanggal bisnis. Bila EOD tanggal sebelumnya tertunda, kasir wajib memilih: lanjut
// (transaksi dicatat ke tanggal itu) atau batal login agar Store Manager menyelesaikan EOD dulu.
function SessionPromptModal({ prompt, name, onAcknowledge, onDecline }) {
    const backdated = Boolean(prompt.backdate_date);

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>{prompt.greeting || 'Selamat datang'}{name ? `, ${name}` : ''}!</h2>
                <p>Business date: <strong>{formatIsoDate(prompt.posting_date)}</strong></p>
                {backdated && (
                    <p className="pos-session-warning">
                        EOD tanggal {formatIsoDate(prompt.backdate_date)} belum diselesaikan. Jika Anda melanjutkan, semua transaksi
                        hari ini akan dicatat sebagai transaksi tanggal {formatIsoDate(prompt.backdate_date)} (termasuk tanggal posting di SAP).
                        Lanjutkan?
                    </p>
                )}
                <div className="pos-modal-actions">
                    {backdated && <button className="btn btn-outline" onClick={onDecline}>Tidak, batalkan login</button>}
                    <button className="btn btn-filled" onClick={onAcknowledge}>{backdated ? 'Ya, lanjutkan' : 'Mulai'}</button>
                </div>
            </div>
        </div>
    );
}

function LogoutForm() {
    return (
        <form method="POST" action="/logout">
            <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
            <button className="btn btn-outline btn-sm" type="submit">Keluar</button>
        </form>
    );
}

function CustomerModal({ onClose, onSelect }) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [form, setForm] = useState({ name: '', phone: '', code: '' });
    const [error, setError] = useState(null);

    const search = async (term = q) => {
        try {
            const res = await api.customers(term);
            setResults(res.data);
        } catch (e) {
            setError(e.message);
        }
    };

    useEffect(() => { search(''); /* eslint-disable-next-line */ }, []);

    const register = async () => {
        setError(null);
        try {
            const res = await api.createCustomer(form);
            onSelect(res.data);
        } catch (e) {
            setError(e.errors ? Object.values(e.errors).flat().join(' ') : e.message);
        }
    };

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Pelanggan / Member</h2>

                <div className="pos-pay-row">
                    <input placeholder="Cari nomor HP, kode, atau nama…" value={q}
                        onChange={(e) => { setQ(e.target.value); search(e.target.value); }} autoFocus />
                </div>

                <div className="pos-receipt" style={{ maxHeight: 200, overflowY: 'auto' }}>
                    {results.map((c) => (
                        <div key={c.id} className="pos-return-line">
                            <span>{c.name} <small>{c.phone || c.code} · {c.level} · {c.points_balance} poin</small></span>
                            <button className="btn btn-tonal btn-sm" onClick={() => onSelect(c)}>Pilih</button>
                        </div>
                    ))}
                    {results.length === 0 && <div className="empty-state">Tidak ada pelanggan.</div>}
                </div>

                <hr />
                <h3 style={{ fontSize: 14 }}>Daftar Baru</h3>
                {error && <div className="md-snackbar error">{error}</div>}
                <div className="pos-delivery">
                    <input placeholder="Nama *" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <input placeholder="Nomor HP" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                    <input placeholder="Kode (opsional)" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
                    <button className="btn btn-filled btn-sm" onClick={register} disabled={!form.name}>Daftarkan</button>
                </div>

                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Tutup</button>
                </div>
            </div>
        </div>
    );
}

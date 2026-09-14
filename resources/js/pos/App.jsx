import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, uuid } from './api.js';
import { printReceipt, connectUsbPrinter, hasUsbPrinter } from './printer.js';
import { enqueue, readQueue, removeFromQueue, writeQueue } from './offline.js';

const fmt = (n, currency = 'IDR') =>
    (currency === 'IDR' ? 'Rp ' : currency + ' ') +
    Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });

function computeTotals(cart, company, shipping) {
    const rate = Number(company?.ppn_rate || 0);
    const inclusive = !!company?.ppn_inclusive;
    const enabled = !!company?.ppn_enabled && rate > 0;

    let subtotal = 0;
    let taxSum = 0;

    cart.forEach((l) => {
        const base = Math.max(0, l.unit_price * l.qty - (l.discount || 0));
        subtotal += base;
        if (enabled) {
            taxSum += inclusive ? base - base / (1 + rate / 100) : (base * rate) / 100;
        }
    });

    const taxTotal = Math.round(taxSum * 100) / 100;
    const total = Math.round((subtotal + (inclusive ? 0 : taxTotal) + (shipping || 0)) * 100) / 100;

    return { subtotal: Math.round(subtotal * 100) / 100, taxTotal, total };
}

export default function App() {
    const [boot, setBoot] = useState(null);
    const [products, setProducts] = useState([]);
    const [query, setQuery] = useState('');
    const [cart, setCart] = useState([]);
    const [online, setOnline] = useState(navigator.onLine);
    const [queue, setQueue] = useState(readQueue());
    const [notice, setNotice] = useState(null);
    const [showPayment, setShowPayment] = useState(false);
    const [showHolds, setShowHolds] = useState(false);
    const [showReturn, setShowReturn] = useState(false);
    const [showDevice, setShowDevice] = useState(false);
    const [showCustomer, setShowCustomer] = useState(false);
    const [customer, setCustomer] = useState(null);
    const [receipt, setReceipt] = useState(null);
    const scanRef = useRef(null);

    const currency = boot?.company?.currency || 'IDR';

    const loadProducts = useCallback(async (q = '') => {
        try {
            const res = await api.products(q);
            setProducts(res.data);
        } catch (e) {
            setNotice({ type: 'error', text: e.message });
        }
    }, []);

    useEffect(() => {
        (async () => {
            try {
                const res = await api.bootstrap();
                setBoot(res);
                await loadProducts('');
            } catch (e) {
                setNotice({ type: 'error', text: e.message });
            }
        })();
    }, [loadProducts]);

    useEffect(() => {
        const on = () => { setOnline(true); flushQueue(); };
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

    const addProduct = (product) => {
        setCart((prev) => {
            const found = prev.find((l) => l.id === product.id);
            if (found) {
                return prev.map((l) => (l.id === product.id ? { ...l, qty: l.qty + 1 } : l));
            }
            return [...prev, { id: product.id, sku: product.sku, name: product.name, unit_price: product.price, qty: 1, discount: 0, track_stock: product.track_stock, stock: product.stock }];
        });
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

    const setQty = (id, qty) => setCart((prev) => prev.map((l) => (l.id === id ? { ...l, qty: Math.max(1, qty) } : l)));
    const removeLine = (id) => setCart((prev) => prev.filter((l) => l.id !== id));

    const [delivery, setDelivery] = useState({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });

    const totals = useMemo(
        () => computeTotals(cart, boot?.company, delivery.enabled ? Number(delivery.cost || 0) : 0),
        [cart, boot, delivery],
    );

    const buildPayload = (origin, payments) => ({
        client_uuid: uuid(),
        origin,
        sold_at: new Date().toISOString(),
        customer_id: customer?.id || null,
        shipping_cost: delivery.enabled ? Number(delivery.cost || 0) : 0,
        lines: cart.map((l) => ({ product_id: l.id, qty: l.qty, unit_price: l.unit_price, discount: l.discount || 0 })),
        payments,
        delivery: delivery.enabled
            ? { recipient_name: delivery.recipient_name, phone: delivery.phone, address: delivery.address, note: delivery.note }
            : null,
    });

    const finalizeSale = async (payments) => {
        const payload = buildPayload(online ? 'online' : 'offline', payments);

        if (!online) {
            enqueue(payload);
            setQueue(readQueue());
            setReceipt({ number: 'OFFLINE', total: totals.total, paid_total: payments.reduce((s, p) => s + Number(p.amount || 0), 0), change_total: 0, lines: cart, subtotal: totals.subtotal, tax_total: totals.taxTotal, shipping_cost: payload.shipping_cost, currency, origin: 'offline' });
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
            setCart([]);
            setCustomer(null);
            setShowPayment(false);
            setDelivery({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });
            setNotice({ type: 'success', text: `Transaksi ${res.data.number} tersimpan.` });
            printReceipt(res.data, meta());
        } catch (e) {
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
        const clientUuid = uuid();
        try {
            if (online) {
                await api.saveHold({ client_uuid: clientUuid, device_label: navigator.platform, payload: { cart, delivery } });
            } else {
                const drafts = JSON.parse(localStorage.getItem('easypos.pos.holds') || '[]');
                drafts.push({ id: clientUuid, client_uuid: clientUuid, payload: { cart, delivery }, updated_at: new Date().toISOString() });
                localStorage.setItem('easypos.pos.holds', JSON.stringify(drafts));
            }
            setCart([]);
            setNotice({ type: 'success', text: 'Transaksi ditahan.' });
        } catch (e) {
            setNotice({ type: 'error', text: e.message });
        }
    };

    if (!boot) {
        return <div className="pos-loading">Memuat POS…</div>;
    }

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
                    <button className="btn btn-tonal btn-sm" onClick={() => setShowCustomer(true)}>
                        {customer ? customer.name : 'Pelanggan'}
                    </button>
                    <button className="btn btn-tonal btn-sm" onClick={() => setShowHolds(true)}>Tahan</button>
                    <button className="btn btn-tonal btn-sm" onClick={() => setShowReturn(true)}>Retur</button>
                    <button className="btn btn-outline btn-sm" onClick={() => setShowDevice(true)}>Perangkat</button>
                    <span className="pos-cashier">{boot.cashier?.name}</span>
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
                        <button className="btn btn-outline btn-sm" type="submit">Keluar</button>
                    </form>
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
                        {products.map((p) => (
                            <button key={p.id} className="pos-tile" onClick={() => addProduct(p)}>
                                <span className="pos-tile-name">{p.name}</span>
                                <span className="pos-tile-sku sku">{p.sku}</span>
                                <span className="pos-tile-price money">{fmt(p.price, currency)}</span>
                                {p.track_stock && <span className="pos-tile-stock">stok {p.stock}</span>}
                            </button>
                        ))}
                        {products.length === 0 && <div className="empty-state">Tidak ada produk.</div>}
                    </div>
                </section>

                <aside className="pos-cart">
                    <div className="pos-cart-lines">
                        {cart.length === 0 && <div className="empty-state">Keranjang kosong. Scan atau pilih produk.</div>}
                        {cart.map((l) => (
                            <div key={l.id} className="pos-line">
                                <div className="pos-line-info">
                                    <strong>{l.name}</strong>
                                    <span className="money">{fmt(l.unit_price, currency)}</span>
                                </div>
                                <div className="pos-line-qty">
                                    <button onClick={() => setQty(l.id, l.qty - 1)}>−</button>
                                    <input value={l.qty} onChange={(e) => setQty(l.id, Number(e.target.value) || 1)} />
                                    <button onClick={() => setQty(l.id, l.qty + 1)}>+</button>
                                </div>
                                <div className="pos-line-total money">{fmt(l.unit_price * l.qty, currency)}</div>
                                <button className="pos-line-remove" onClick={() => removeLine(l.id)} title="Hapus">
                                    <span className="material-symbols-rounded">close</span>
                                </button>
                            </div>
                        ))}
                    </div>

                    <div className="pos-totals">
                        <div><span>Subtotal</span><span className="money">{fmt(totals.subtotal, currency)}</span></div>
                        <div><span>PPN</span><span className="money">{fmt(totals.taxTotal, currency)}</span></div>
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

            {showHolds && <HoldsModal online={online} onClose={() => setShowHolds(false)} onResume={(payload) => { setCart(payload.cart || []); setDelivery(payload.delivery || delivery); setShowHolds(false); }} />}

            {showReturn && <ReturnModal onClose={() => setShowReturn(false)} onDone={(msg) => { setNotice({ type: 'success', text: msg }); setShowReturn(false); }} onError={(msg) => setNotice({ type: 'error', text: msg })} />}

            {showDevice && <DeviceModal onClose={() => setShowDevice(false)} />}

            {showCustomer && (
                <CustomerModal
                    onClose={() => setShowCustomer(false)}
                    onSelect={(c) => { setCustomer(c); setShowCustomer(false); }}
                />
            )}
        </div>
    );
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
                        <div key={i} className="pos-receipt-line">
                            <span>{l.qty}× {l.name}</span>
                            <span className="money">{fmt(l.line_total, currency)}</span>
                        </div>
                    ))}
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

function HoldsModal({ online, onClose, onResume }) {
    const [items, setItems] = useState([]);

    useEffect(() => {
        (async () => {
            if (online) {
                try {
                    const res = await api.holds();
                    setItems(res.data.map((d) => ({ id: d.id, client_uuid: d.client_uuid, payload: d.payload, updated_at: d.updated_at })));
                    return;
                } catch { /* fall through */ }
            }
            setItems(JSON.parse(localStorage.getItem('easypos.pos.holds') || '[]'));
        })();
    }, [online]);

    const cancel = async (item) => {
        try {
            if (online && item.id) await api.deleteHold(item.id);
        } catch { /* ignore */ }
        const rest = items.filter((i) => i.client_uuid !== item.client_uuid);
        setItems(rest);
        localStorage.setItem('easypos.pos.holds', JSON.stringify(rest));
    };

    return (
        <div className="pos-modal">
            <div className="pos-modal-card">
                <h2>Transaksi Ditahan</h2>
                {items.length === 0 && <div className="empty-state">Tidak ada transaksi ditahan.</div>}
                {items.map((it) => (
                    <div key={it.client_uuid} className="pos-hold-row">
                        <span>{(it.payload?.cart || []).length} item · {new Date(it.updated_at || Date.now()).toLocaleTimeString('id-ID')}</span>
                        <div>
                            <button className="btn btn-filled btn-sm" onClick={() => onResume(it.payload)}>Lanjutkan</button>
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

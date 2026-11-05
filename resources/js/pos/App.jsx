import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, uuid } from './api.js';
import { printReceipt, connectUsbPrinter, hasUsbPrinter, getAutoPrint, setAutoPrint } from './printer.js';
import { enqueue, readQueue, removeFromQueue, writeQueue } from './offline.js';
import { activePromotions, applyPromotions, readCachedPromotionMeta, readCachedPromotions, writeCachedPromotionMeta, writeCachedPromotions } from './promotions.js';
import { formatBusinessClock, formatIsoDate, serverOffset } from './businessClock.js';
import { fitCartToStock, isOutOfStock, maxQty } from './stock.js';
import { digitsOnly, parseQty } from './qty.js';
import { approvalMissing, normalizePayments, requiresApproval } from './payment.js';
import { buildCartPayload, clearCartBackup, pickNewestCart, readCartBackup, writeCartBackup } from './cartPersistence.js';
import { customerPanel } from './customer.js';
import { functionKeyStates, holdOrRecallAction, isFunctionKey, keyToAction, moveSelection, selectionAfterRemoval } from './functionKeys.js';

const fmt = (n, currency = 'IDR') =>
    (currency === 'IDR' ? 'Rp ' : currency + ' ') +
    Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });

// Baris keranjang + hasil promosi (discount, promotion_id, promotion_name). Keranjang di state tetap
// mentah agar promosi dihitung ulang setiap kali keranjang/promosi berubah, termasuk saat hold dilanjutkan.
function priceCart(cart, promotions, at = new Date(), context = {}, hierarchy = null) {
    const result = applyPromotions(
        cart.map((l) => ({ product_id: l.id, qty: l.qty, unit_price: l.unit_price, brand_id: l.brand_id ?? null, product_group_id: l.product_group_id ?? null })),
        activePromotions(promotions, at),
        context,
        hierarchy,
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
    const [selectedId, setSelectedId] = useState(null);
    const [panelTab, setPanelTab] = useState('customer');
    const [panelOpen, setPanelOpen] = useState(false);
    const [showDelivery, setShowDelivery] = useState(false);
    const [showTransactions, setShowTransactions] = useState(false);
    const [showEditItem, setShowEditItem] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [receipt, setReceipt] = useState(null);
    const [promotions, setPromotions] = useState(readCachedPromotions);
    const [promotionMeta, setPromotionMeta] = useState(readCachedPromotionMeta);
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
            if (res.meta) {
                setPromotionMeta(res.meta);
                writeCachedPromotionMeta(res.meta);
            }
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
                // Sesi aktif di perangkat lain ditangani layar konflik (lewat peristiwa dari api.js).
                if (e.data?.code === 'pos_session_active') return;
                setBootError(e.message);
                setNotice({ type: 'error', text: e.message });
            }
        })();
    }, [loadProducts, loadPromotions, refreshHeldCount]);

    // Status kunci EOD, pelanggan default, dan penugasan gerai bisa berubah dari admin; segarkan berkala selama online.
    // Promosi dan hierarki promosi ikut disegarkan (tiap menit dan saat tab kembali aktif) agar perubahan dari admin
    // atau sinkronisasi SAP langsung terpakai tanpa memuat ulang POS.
    useEffect(() => {
        if (!boot || !online) return undefined;
        const onVisible = () => { if (document.visibilityState === 'visible') loadPromotions(); };
        document.addEventListener('visibilitychange', onVisible);
        const timer = setInterval(async () => {
            loadPromotions();
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
        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
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

    // Pembatalan di keranjang (batal transaksi, hapus item, qty dikurangi) dicatat di server untuk diperiksa manajer.
    const logCartEvent = (body) => {
        if (navigator.onLine) api.logCartEvent(body).catch(() => {});
    };

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
                return [...prev, { id: product.id, sku: product.sku, name: product.name, unit: product.unit, unit_price: product.price, qty: nextQty, track_stock: product.track_stock, stock: product.stock, brand_id: product.brand_id ?? null, product_group_id: product.product_group_id ?? null }];
            });
        }
        setSelectedId(product.id);
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
            // Kode tidak cocok: tampilkan hasil pencarian di tab Produk.
            setPanelTab('products');
            setPanelOpen(true);
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
        if (nextQty < line.qty) {
            logCartEvent({ event: 'qty_reduced', sku: line.sku, name: line.name, from_qty: line.qty, to_qty: nextQty });
        }
        setCart((prev) => prev.map((l) => (l.id === id ? { ...l, qty: nextQty } : l)));
    };
    const removeLine = (id) => setCart((prev) => prev.filter((l) => l.id !== id));

    const [delivery, setDelivery] = useState({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });

    // Keranjang yang sedang berjalan disimpan otomatis ke server (dan cadangan di browser) dan dipulihkan saat POS
    // dibuka lagi (refresh, tab tertutup, login ulang), supaya transaksi tidak bisa hilang tanpa jejak.
    const [cartReady, setCartReady] = useState(false);
    const allowUnloadRef = useRef(false);
    const cartSnapshot = useRef(null);
    cartSnapshot.current = { cart, delivery, customer, activeHold };

    useEffect(() => {
        if (!boot || cartReady) return;
        (async () => {
            let server = null;
            if (navigator.onLine) {
                try {
                    server = (await api.restoreCart()).data;
                } catch {
                    /* pakai cadangan browser */
                }
            }
            const found = pickNewestCart(server, readCartBackup(boot.cashier.id));
            if (found) {
                setCart(found.cart);
                setDelivery(found.delivery ?? { enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });
                setCustomer(found.customer ?? null);
                setActiveHold(found.active_hold ?? null);
                setNotice({ type: 'info', text: `Transaksi sebelumnya dipulihkan (${found.cart.length} barang).` });
            }
            setCartReady(true);
        })();
    }, [boot, cartReady]);

    useEffect(() => {
        if (!cartReady || !boot) return undefined;
        const payload = buildCartPayload({ cart, delivery, customer, activeHold });
        if (cart.length === 0) clearCartBackup();
        else writeCartBackup(boot.cashier.id, payload);
        if (!online) return undefined;

        const timer = setTimeout(() => { api.saveCart(payload).catch(() => {}); }, cart.length === 0 ? 0 : 300);
        return () => clearTimeout(timer);
    }, [cart, delivery, customer, activeHold, cartReady, online]); // eslint-disable-line react-hooks/exhaustive-deps

    // Perubahan terakhir yang belum sempat terkirim (debounce) dikirim saat halaman ditutup atau dimuat ulang.
    useEffect(() => {
        if (!cartReady) return undefined;
        const flush = () => {
            const snapshot = cartSnapshot.current;
            if (snapshot.cart.length > 0 && navigator.onLine) {
                api.saveCart(buildCartPayload(snapshot), { keepalive: true }).catch(() => {});
                api.logCartEvent({
                    event: 'left_open',
                    item_count: snapshot.cart.length,
                    total: snapshot.cart.reduce((sum, l) => sum + l.qty * l.unit_price, 0),
                }, { keepalive: true }).catch(() => {});
            }
        };
        window.addEventListener('pagehide', flush);
        return () => window.removeEventListener('pagehide', flush);
    }, [cartReady]);

    // Diskon SAP dibatasi pelanggan (kode dan group) dan price list gerai; pelanggan kosong = pelanggan default gerai.
    const discountContext = useMemo(() => {
        const who = customer || boot?.default_customer || null;
        return {
            customer: who ? { code: who.code, group_code: who.group_code ?? null } : null,
            price_list_code: promotionMeta.price_list_code ?? null,
        };
    }, [customer, boot, promotionMeta]);

    const priced = useMemo(
        () => priceCart(cart, promotions, new Date(), discountContext, promotionMeta.hierarchy ?? null),
        [cart, promotions, discountContext, promotionMeta],
    );

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
            lines: priceCart(cart, promotions, soldAt, discountContext, promotionMeta.hierarchy ?? null).lines.map((l) => ({
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

    const customerInfoLabel = () => {
        const info = customerPanel(customer, boot?.default_customer);
        return info.code ? `${info.code} ${info.name}` : info.name;
    };

    const finalizeSale = async (payments) => {
        const payload = buildPayload(online ? 'online' : 'offline', payments);

        if (!online) {
            enqueue({
                ...payload,
                local_summary: { customer_name: customerInfoLabel(), total: totals.total, tax_total: totals.taxTotal },
            });
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
            if (getAutoPrint()) printReceipt(res.data, meta());
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

    // ---- Tombol fungsi F1–F12, keyboard, dan dialog (docs/Design-Brief-Layar-POS.md) ----
    const cashMethod = boot?.payment_types?.find((pt) => pt.code === 'CASH');
    const promptOpen = Boolean(boot?.prompt?.greeting || boot?.prompt?.backdate_date);
    const modalOpen = showPayment || showHolds || showReturn || showDevice || showCustomer || showDelivery
        || showTransactions || showEditItem || Boolean(confirm) || Boolean(receipt) || promptOpen || Boolean(storeChangeMessage);
    const keyStates = functionKeyStates({ cartEmpty: cart.length === 0, modalOpen, hasCashMethod: Boolean(cashMethod), heldCount });
    const selectedLine = priced.lines.find((l) => l.id === selectedId) || priced.lines[priced.lines.length - 1] || null;

    const resetSale = () => {
        setCart([]);
        setCustomer(null);
        setSelectedId(null);
        setDelivery({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 });
    };

    const askRemoveItem = () => {
        if (!selectedLine) return;
        const index = cart.findIndex((l) => l.id === selectedLine.id);
        setConfirm({
            title: 'Hapus item?',
            message: `Hapus "${selectedLine.name}" dari keranjang?`,
            confirmLabel: 'Hapus',
            onConfirm: () => {
                const remaining = cart.filter((l) => l.id !== selectedLine.id);
                logCartEvent({
                    event: 'item_removed',
                    sku: selectedLine.sku,
                    name: selectedLine.name,
                    qty: selectedLine.qty,
                    amount: Math.max(0, selectedLine.unit_price * selectedLine.qty - (selectedLine.discount || 0)),
                });
                removeLine(selectedLine.id);
                setSelectedId(remaining[selectionAfterRemoval(index, remaining.length)]?.id ?? null);
            },
        });
    };

    const askCancelSale = () => {
        setConfirm({
            title: 'Batalkan transaksi?',
            message: 'Semua barang di keranjang akan dikosongkan.',
            confirmLabel: 'Batalkan Transaksi',
            onConfirm: () => {
                if (cart.length > 0) logCartEvent({ event: 'cancelled', item_count: cart.length, total: totals.total });
                resetSale();
            },
        });
    };

    const quickPay = () => {
        if (!cashMethod || cart.length === 0) return;
        finalizeSale([{ payment_type_id: cashMethod.id, amount: totals.total, status: 'confirmed' }]);
    };

    const runAction = (action) => {
        switch (action) {
            case 'return': setShowReturn(true); break;
            case 'customer': setShowCustomer(true); break;
            case 'searchProduct': setPanelTab('products'); setPanelOpen(true); scanRef.current?.focus(); break;
            case 'editItem': setShowEditItem(true); break;
            case 'transactions': setShowTransactions(true); break;
            case 'delivery': setShowDelivery(true); break;
            case 'removeItem': askRemoveItem(); break;
            case 'cancelSale': askCancelSale(); break;
            case 'holdOrRecall':
                if (holdOrRecallAction(cart.length === 0) === 'hold') { hold(); scanRef.current?.focus(); } else { setShowHolds(true); }
                break;
            case 'quickPay': quickPay(); break;
            case 'pay': setShowPayment(true); break;
            default: break;
        }
    };

    const runIfEnabled = (action) => {
        const state = keyStates.find((k) => k.action === action);
        if (state && !state.disabled) runAction(action);
    };

    const moveSelected = (delta) => {
        const index = priced.lines.findIndex((l) => l.id === selectedLine?.id);
        setSelectedId(priced.lines[moveSelection(index, delta, priced.lines.length)]?.id ?? null);
    };

    const closeTopDialog = () => {
        if (confirm) setConfirm(null);
        else if (showEditItem) setShowEditItem(false);
        else if (showDelivery) setShowDelivery(false);
        else if (showTransactions) setShowTransactions(false);
        else if (showPayment) setShowPayment(false);
        else if (showHolds) { setShowHolds(false); refreshHeldCount(); }
        else if (showReturn) setShowReturn(false);
        else if (showDevice) setShowDevice(false);
        else if (showCustomer) setShowCustomer(false);
        else if (receipt) setReceipt(null);
    };

    // Handler keyboard membaca state terbaru lewat ref agar listener cukup dipasang sekali.
    const latest = useRef(null);
    latest.current = {
        keyStates,
        modalOpen,
        query,
        runIfEnabled,
        move: moveSelected,
        adjustQty: (delta) => { if (selectedLine) setQty(selectedLine.id, selectedLine.qty + delta); },
        escape: () => {
            if (modalOpen) { closeTopDialog(); } else if (query) { setQuery(''); loadProducts(''); }
        },
    };

    useEffect(() => {
        const onKeyDown = (e) => {
            const a = latest.current;
            // F1–F12 selalu dicegat agar tidak memicu fungsi bawaan browser (bantuan, muat ulang, layar penuh, dll.).
            if (isFunctionKey(e)) {
                e.preventDefault();
                if (!e.repeat) {
                    const action = keyToAction(e);
                    if (action) a.runIfEnabled(action);
                }
                return;
            }
            if (e.key === 'Escape') { a.escape(); return; }
            if (a.modalOpen) return;

            const free = document.activeElement === scanRef.current || document.activeElement === document.body;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                a.move(e.key === 'ArrowDown' ? 1 : -1);
            } else if ((e.key === '+' || e.key === '-') && a.query === '' && free) {
                e.preventDefault();
                a.adjustQty(e.key === '+' ? 1 : -1);
            } else if (e.key === 'Delete' && a.query === '' && free) {
                e.preventDefault();
                a.runIfEnabled('removeItem');
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    // Semua notifikasi (termasuk error) hilang sendiri setelah 5 detik, atau saat diklik.
    useEffect(() => {
        if (!notice) return undefined;
        const timer = setTimeout(() => setNotice(null), 5000);
        return () => clearTimeout(timer);
    }, [notice]);

    // Fokus kembali ke kotak input setelah dialog tertutup, agar kasir bisa langsung memindai barang berikutnya.
    useEffect(() => {
        if (!modalOpen) scanRef.current?.focus();
    }, [modalOpen]);

    // Selama ada transaksi berjalan, menutup tab/jendela, memuat ulang, atau keluar meminta konfirmasi browser. Browser tidak
    // mengizinkan pemblokiran penuh; yang lolos tetap tercatat (pos.cart_left_open) dan keranjangnya dipulihkan.
    useEffect(() => {
        if (cart.length === 0) return undefined;
        const guard = (e) => {
            if (allowUnloadRef.current) return;
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', guard);
        return () => window.removeEventListener('beforeunload', guard);
    }, [cart.length]);

    // Satu sesi POS aktif per kasir: detak tiap 30 detik menjaga sesi tetap aktif, dan perangkat yang tergeser
    // (atau ditolak saat membuka POS) melihat layar konflik dengan pilihan mengambil alih.
    const [sessionConflict, setSessionConflict] = useState(null);
    useEffect(() => {
        const onConflict = (event) => setSessionConflict(event.detail);
        window.addEventListener('pos-session-conflict', onConflict);
        return () => window.removeEventListener('pos-session-conflict', onConflict);
    }, []);

    useEffect(() => {
        if (!boot || !online || sessionConflict) return undefined;
        const timer = setInterval(() => { api.heartbeat().catch(() => {}); }, 30000);
        return () => clearInterval(timer);
    }, [boot, online, sessionConflict]);

    useEffect(() => {
        document.querySelector('.pos-row.is-selected')?.scrollIntoView({ block: 'nearest' });
    }, [selectedLine?.id, cart.length]);

    if (sessionConflict) {
        return (
            <SessionConflict
                info={sessionConflict.active_session}
                onTakeover={async () => {
                    allowUnloadRef.current = true;
                    try {
                        await api.takeoverSession();
                        window.location.reload();
                    } catch (e) {
                        allowUnloadRef.current = false;
                        throw e;
                    }
                }}
            />
        );
    }

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
    const totalQty = priced.lines.reduce((sum, l) => sum + l.qty, 0);
    const availableBonuses = bonusHints.filter((h) => h.product);
    const appliedPromotions = [...new Map(priced.lines.filter((l) => l.promotion_id).map((l) => [l.promotion_id, l])).values()];

    return (
        <div className="pos">
            <div className="pos-unsupported">
                <h2>Gunakan tablet atau desktop</h2>
                <p>POS tidak didukung di ponsel agar transaksi, printer, pemindai, dan laci kas tetap andal. Buka POS di perangkat dengan layar minimal 768 px.</p>
            </div>

            <PosHeader
                boot={boot}
                online={online}
                queueCount={queue.length}
                onOpenDevice={() => setShowDevice(true)}
                panelOpen={panelOpen}
                onTogglePanel={() => setPanelOpen((open) => !open)}
            />

            <div className="pos-ribbons">
                {boot.business?.closed && (
                    <div className="pos-ribbon" role="status">
                        <span className="material-symbols-rounded" aria-hidden="true">lock</span>
                        EOD tanggal ini sudah ditutup — transaksi online terkunci
                    </div>
                )}
                {boot.business?.locked_date && (
                    <div className="pos-ribbon" role="status">
                        <span className="material-symbols-rounded" aria-hidden="true">lock_clock</span>
                        EOD {formatIsoDate(boot.business.locked_date)} belum dijalankan. Semua transaksi akan tercatat sebagai transaksi {formatIsoDate(boot.business.locked_date)}.
                    </div>
                )}
            </div>

            {notice && (
                <div
                    className={`md-snackbar pos-notice ${notice.type === 'success' ? 'success' : notice.type === 'error' ? 'error' : 'info'}`}
                    role={notice.type === 'error' ? 'alert' : 'status'}
                    onClick={() => setNotice(null)}
                >
                    {notice.text}
                </div>
            )}

            <div className="pos-main">
                <section className="pos-items" aria-label="Daftar barang">
                    <div className="pos-table-wrap" aria-live="polite">
                        <table className="pos-table">
                            <caption className="sr-only">Barang dalam transaksi</caption>
                            <thead>
                                <tr>
                                    <th className="col-code">Kode</th>
                                    <th className="col-name">Nama Barang</th>
                                    <th className="col-num">Diskon</th>
                                    <th className="col-qty">Qty</th>
                                    <th className="col-unit">Satuan</th>
                                    <th className="col-num">Harga</th>
                                    <th className="col-num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {priced.lines.map((l) => (
                                    <tr
                                        key={l.id}
                                        className={`pos-row ${l.id === selectedLine?.id ? 'is-selected' : ''}`}
                                        aria-selected={l.id === selectedLine?.id}
                                        onClick={() => { setSelectedId(l.id); scanRef.current?.focus(); }}
                                    >
                                        <td className="col-code sku">{l.sku}</td>
                                        <td className="col-name">
                                            <span className="pos-row-name">{l.name}</span>
                                            {l.promotion_id && (
                                                <span className="pos-line-promo">
                                                    <span className="material-symbols-rounded" aria-hidden="true">sell</span>
                                                    {l.promotion_name}
                                                </span>
                                            )}
                                        </td>
                                        <td className="col-num money">{l.discount > 0 ? `−${fmt(l.discount, currency)}` : '–'}</td>
                                        <td className="col-qty">
                                            <QtyCell
                                                line={l}
                                                onSelect={() => setSelectedId(l.id)}
                                                onCommit={(qty) => setQty(l.id, qty)}
                                                onDone={() => scanRef.current?.focus()}
                                            />
                                        </td>
                                        <td className="col-unit">{l.unit || '–'}</td>
                                        <td className="col-num money">{fmt(l.unit_price, currency)}</td>
                                        <td className="col-num money">{fmt(l.unit_price * l.qty - l.discount, currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {cart.length === 0 && <div className="empty-state">Keranjang kosong. Scan atau cari produk lalu tekan Enter.</div>}
                        {availableBonuses.map((hint) => (
                            <div key={hint.promotion_id} className="pos-bonus-hint">
                                <span>
                                    <span className="material-symbols-rounded" aria-hidden="true">redeem</span>
                                    Bonus tersedia: {hint.qty} × {hint.product.name} <small>({hint.promotion_name})</small>
                                </span>
                                <button className="btn btn-tonal btn-sm" onClick={() => addProduct(hint.product, hint.qty)}>Tambah</button>
                            </div>
                        ))}
                    </div>
                </section>

                <aside className={`pos-panel ${panelOpen ? 'is-open' : ''}`} aria-label="Panel samping">
                    <div className="pos-tabs" role="tablist">
                        {[
                            { id: 'customer', label: 'Pelanggan' },
                            { id: 'promo', label: 'Promo & Bonus', badge: availableBonuses.length },
                            { id: 'products', label: 'Produk' },
                        ].map((tab) => (
                            <button
                                key={tab.id}
                                role="tab"
                                aria-selected={panelTab === tab.id}
                                className={`pos-tab ${panelTab === tab.id ? 'is-active' : ''}`}
                                onClick={() => { setPanelTab(tab.id); scanRef.current?.focus(); }}
                            >
                                {tab.label}
                                {tab.badge > 0 && <span className="pos-badge" aria-label={`${tab.badge} bonus tersedia`}>{tab.badge}</span>}
                            </button>
                        ))}
                    </div>

                    <div className="pos-tab-body" role="tabpanel">
                        {panelTab === 'customer' && (
                            <div className="pos-customer-panel">
                                <dl className="pos-facts">
                                    <div><dt>Kode</dt><dd className="sku">{customerInfo.code || '–'}</dd></div>
                                    <div><dt>Nama</dt><dd>{customerInfo.name}{customerInfo.isDefault && <span className="chip">Default</span>}</dd></div>
                                    <div><dt>HP</dt><dd className="mono">{customer?.phone || '–'}</dd></div>
                                    <div><dt>Level</dt><dd>{customer?.level || '–'}</dd></div>
                                    <div><dt>Poin</dt><dd className="money">{customer ? Number(customer.points_balance || 0).toLocaleString('id-ID') : '–'}</dd></div>
                                </dl>
                                <div className="pos-panel-actions">
                                    <button className="btn btn-outline" onClick={() => setShowCustomer(true)}>Ganti</button>
                                    <button className="btn btn-outline" onClick={() => { setCustomer(null); scanRef.current?.focus(); }} disabled={!customerInfo.isSelected}>Hapus</button>
                                </div>
                            </div>
                        )}

                        {panelTab === 'promo' && (
                            <div className="pos-promo-panel">
                                {appliedPromotions.length === 0 && availableBonuses.length === 0 && (
                                    <div className="empty-state">Tidak ada promosi pada keranjang ini.</div>
                                )}
                                {appliedPromotions.map((l) => (
                                    <div key={l.promotion_id} className="pos-promo-row">
                                        <span className="pos-line-promo">
                                            <span className="material-symbols-rounded" aria-hidden="true">sell</span>
                                            {l.promotion_name}
                                        </span>
                                        <span className="money">−{fmt(l.discount, currency)}</span>
                                    </div>
                                ))}
                                {availableBonuses.map((hint) => (
                                    <div key={hint.promotion_id} className="pos-bonus-hint">
                                        <span>
                                            <span className="material-symbols-rounded" aria-hidden="true">redeem</span>
                                            {hint.qty} × {hint.product.name} <small>({hint.promotion_name})</small>
                                        </span>
                                        <button className="btn btn-tonal btn-sm" onClick={() => addProduct(hint.product, hint.qty)}>Tambah</button>
                                    </div>
                                ))}
                            </div>
                        )}

                        {panelTab === 'products' && (
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
                        )}
                    </div>
                </aside>
            </div>

            <div className="pos-bottom">
                <div className="pos-summary" aria-live="polite">
                    <div className="pos-cell"><span className="pos-cell-label">Item</span><span className="pos-cell-value money">{priced.lines.length} / {totalQty}</span></div>
                    <div className="pos-cell"><span className="pos-cell-label">Subtotal setelah diskon</span><span className="pos-cell-value money">{fmt(totals.subtotal, currency)}</span></div>
                    <div className="pos-cell"><span className="pos-cell-label">Diskon</span><span className="pos-cell-value money">{fmt(totals.discountTotal, currency)}</span></div>
                    <div className="pos-cell"><span className="pos-cell-label">PPN{boot.company?.tax_group_code ? ` (${boot.company.tax_group_code})` : ''}</span><span className="pos-cell-value money">{fmt(totals.taxTotal, currency)}</span></div>
                    <div className="pos-cell"><span className="pos-cell-label">Ongkir</span><span className="pos-cell-value money">{fmt(delivery.enabled ? Number(delivery.cost || 0) : 0, currency)}</span></div>
                    <div className="pos-cell pos-cell-total"><span className="pos-cell-label">TOTAL</span><span className="pos-cell-value money">{fmt(totals.total, currency)}</span></div>
                </div>
                <div className="pos-input-box">
                    <input
                        ref={scanRef}
                        className="pos-scan"
                        autoFocus
                        aria-label="Scan barcode atau cari produk"
                        placeholder="Scan / cari produk"
                        value={query}
                        onChange={(e) => { setQuery(e.target.value); loadProducts(e.target.value); }}
                        onKeyDown={onScan}
                    />
                </div>
            </div>

            <nav className="pos-fkeys" aria-label="Tombol fungsi">
                {keyStates.map((k) => (
                    <button
                        key={k.key}
                        className={`pos-fkey ${k.primary ? 'is-primary' : ''}`}
                        disabled={k.disabled}
                        aria-keyshortcuts={k.key}
                        onClick={() => { runAction(k.action); }}
                    >
                        <span className="pos-fkey-name">{k.key}</span>
                        <span className="pos-fkey-label">{k.label || 'kosong'}</span>
                        {k.badge && <span className="pos-badge pos-fkey-badge" aria-label={`${k.badge} transaksi ditahan`}>{k.badge}</span>}
                    </button>
                ))}
            </nav>

            {showPayment && (
                <PaymentModal
                    boot={boot}
                    currency={currency}
                    totals={totals}
                    onClose={() => setShowPayment(false)}
                    onConfirm={finalizeSale}
                />
            )}

            {showDelivery && (
                <DeliveryModal
                    delivery={delivery}
                    onClose={() => setShowDelivery(false)}
                    onSave={(next) => { setDelivery(next); setShowDelivery(false); }}
                />
            )}

            {showEditItem && selectedLine && (
                <EditItemModal
                    line={selectedLine}
                    onClose={() => setShowEditItem(false)}
                    onSave={(qty) => { setQty(selectedLine.id, qty); setShowEditItem(false); }}
                />
            )}

            {showTransactions && (
                <TransactionsModal
                    online={online}
                    boot={boot}
                    currency={currency}
                    meta={meta()}
                    onClose={() => setShowTransactions(false)}
                />
            )}

            {confirm && (
                <ConfirmDialog
                    {...confirm}
                    onCancel={() => setConfirm(null)}
                    onConfirm={() => { const run = confirm.onConfirm; setConfirm(null); run(); }}
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
                        setSelectedId(lines[lines.length - 1]?.id ?? null);
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

            {promptOpen && (
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
                <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-store-change-title">
                    <div className="pos-modal-card">
                        <h2 id="pos-store-change-title">Penugasan gerai berubah</h2>
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

// Header: logo, gerai, business date (tanggal posting), jam zona waktu perusahaan, status online/antrean, dan menu kasir.
function PosHeader({ boot, online, queueCount, onOpenDevice, panelOpen, onTogglePanel }) {
    const business = boot.business;
    const offset = useMemo(() => serverOffset(business?.server_time), [business?.server_time]);
    const [now, setNow] = useState(() => new Date(Date.now() + offset));
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);

    useEffect(() => {
        const tick = () => setNow(new Date(Date.now() + offset));
        tick();
        const timer = setInterval(tick, 1000);
        return () => clearInterval(timer);
    }, [offset]);

    useEffect(() => {
        if (!menuOpen) return undefined;
        const close = (e) => { if (!menuRef.current?.contains(e.target)) setMenuOpen(false); };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [menuOpen]);

    const clock = business ? formatBusinessClock(now, business.timezone) : null;
    const businessDate = business?.posting_date && business.posting_date !== clock?.isoDate ? formatIsoDate(business.posting_date) : clock?.date;

    return (
        <header className="pos-header">
            <span className="pos-brand"><span className="material-symbols-rounded" aria-hidden="true">point_of_sale</span>easyPOS</span>
            <span className="pos-header-item">{boot.store?.code} · {boot.store?.name}</span>
            {clock && (
                <>
                    <span className="pos-header-item">Business date: <strong>{businessDate}</strong></span>
                    <span className="pos-header-item pos-clock money">{clock.time}</span>
                </>
            )}
            <span className="pos-header-status">
                <span className={`chip ${online ? 'chip-success' : 'chip-offline'}`} role="status">
                    <span className="material-symbols-rounded" aria-hidden="true">{online ? 'wifi' : 'wifi_off'}</span>
                    {online ? 'Online' : 'Offline'}
                </span>
                {queueCount > 0 && (
                    <span className="chip chip-pending" role="status">
                        <span className="material-symbols-rounded" aria-hidden="true">schedule</span>
                        {queueCount} tertunda
                    </span>
                )}
            </span>
            <div className="pos-header-end">
                <button className="pos-header-btn pos-panel-toggle" onClick={onTogglePanel} aria-expanded={panelOpen}>
                    <span className="material-symbols-rounded" aria-hidden="true">side_navigation</span>Panel
                </button>
                <div className="pos-menu-wrap" ref={menuRef}>
                    <button className="pos-header-btn" onClick={() => setMenuOpen((open) => !open)} aria-haspopup="menu" aria-expanded={menuOpen}>
                        <span className="material-symbols-rounded" aria-hidden="true">account_circle</span>
                        {boot.cashier?.name}
                        <span className="material-symbols-rounded" aria-hidden="true">expand_more</span>
                    </button>
                    {menuOpen && (
                        <div className="pos-menu" role="menu">
                            <button role="menuitem" className="pos-menu-item" onClick={() => { setMenuOpen(false); onOpenDevice(); }}>
                                <span className="material-symbols-rounded" aria-hidden="true">print</span>Perangkat
                            </button>
                            <form method="POST" action="/logout">
                                <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
                                <button role="menuitem" type="submit" className="pos-menu-item">
                                    <span className="material-symbols-rounded" aria-hidden="true">logout</span>Keluar
                                </button>
                            </form>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}

function ConfirmDialog({ title, message, confirmLabel, onConfirm, onCancel }) {
    return (
        <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-confirm-title">
            <div className="pos-modal-card pos-modal-sm">
                <h2 id="pos-confirm-title">{title}</h2>
                <p>{message}</p>
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onCancel}>Batal</button>
                    <button className="btn pos-btn-danger" autoFocus onClick={onConfirm}>{confirmLabel}</button>
                </div>
            </div>
        </div>
    );
}

// F5: ubah qty baris terpilih (tahap ini qty saja; diskon baris manual belum ada).
/**
 * Qty yang bisa diketik langsung di tabel: klik, ketik angka (mis. 5), Enter atau pindah fokus untuk
 * menyimpan, Esc untuk membatalkan. Menggantikan scan berulang untuk barang yang sama.
 */
function QtyCell({ line, onSelect, onCommit, onDone }) {
    const [draft, setDraft] = useState(null); // null = tidak sedang diedit

    const commit = () => {
        if (draft === null) return;
        const qty = parseQty(draft);
        setDraft(null);
        if (qty !== null && qty !== line.qty) onCommit(qty);
    };

    return (
        <input
            className="pos-qty-input money"
            inputMode="numeric"
            aria-label={`Qty ${line.name}`}
            value={draft ?? String(line.qty)}
            onClick={(e) => e.stopPropagation()}
            onFocus={(e) => { onSelect(); e.target.select(); }}
            onChange={(e) => setDraft(digitsOnly(e.target.value))}
            onBlur={commit}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    commit();
                    onDone();
                } else if (e.key === 'Escape') {
                    e.stopPropagation();
                    setDraft(null);
                    onDone();
                }
            }}
        />
    );
}

const formatSessionTime = (iso) => (iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'medium' }) : '-');

/**
 * Akun ini sedang memakai POS di perangkat/browser lain. Kasir dapat mengambil alih (perangkat lain terputus dan
 * kejadiannya tercatat) atau keluar. Browser dan IP perangkat aktif ditampilkan agar jelas siapa yang memakainya.
 */
function SessionConflict({ info, onTakeover }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const takeover = async () => {
        setBusy(true);
        setError('');
        try {
            await onTakeover();
        } catch (e) {
            setError(e.message);
            setBusy(false);
        }
    };

    return (
        <div className="pos-loading">
            <div className="pos-boot-error" role="alertdialog" aria-labelledby="pos-session-title">
                <h2 id="pos-session-title">POS sedang aktif di perangkat lain</h2>
                <p>Satu akun hanya boleh memakai POS di satu perangkat atau browser pada satu waktu.</p>
                {info && (
                    <dl className="pos-session-info">
                        <div><dt>Browser</dt><dd>{info.browser || '-'}</dd></div>
                        <div><dt>IP</dt><dd className="mono">{info.ip || '-'}</dd></div>
                        <div><dt>Aktif sejak</dt><dd>{formatSessionTime(info.started_at)}</dd></div>
                        <div><dt>Terakhir aktif</dt><dd>{formatSessionTime(info.last_seen_at)}</dd></div>
                    </dl>
                )}
                <p>Jika Anda mengambil alih, perangkat tersebut terputus dan kejadian ini dicatat di Log Aktivitas. Keranjang yang sedang berjalan tidak hilang.</p>
                {error && <p className="pos-session-error">{error}</p>}
                <div className="pos-modal-actions">
                    <LogoutForm />
                    <button className="btn btn-filled" onClick={takeover} disabled={busy}>Ambil alih sesi di perangkat ini</button>
                </div>
            </div>
        </div>
    );
}

function EditItemModal({ line, onClose, onSave }) {
    const [qty, setQty] = useState(String(line.qty));
    const limit = maxQty(line, false);
    const value = Number(qty);
    const valid = Number.isFinite(value) && value >= 1;

    return (
        <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-edit-title">
            <div className="pos-modal-card pos-modal-sm">
                <h2 id="pos-edit-title">Ubah Item</h2>
                <p><strong>{line.name}</strong> <span className="sku">{line.sku}</span></p>
                <label className="pos-field">
                    Qty{limit !== Infinity ? ` (stok ${limit})` : ''}
                    <input
                        className="mono"
                        inputMode="numeric"
                        autoFocus
                        value={qty}
                        onFocus={(e) => e.target.select()}
                        onChange={(e) => setQty(e.target.value.replace(/[^\d]/g, ''))}
                        onKeyDown={(e) => { if (e.key === 'Enter' && valid) onSave(value); }}
                    />
                </label>
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Batal</button>
                    <button className="btn btn-filled" disabled={!valid} onClick={() => onSave(value)}>Simpan</button>
                </div>
            </div>
        </div>
    );
}

// F7: data penerima dan biaya kirim (dulu bagian dari dialog bayar).
function DeliveryModal({ delivery, onClose, onSave }) {
    const [draft, setDraft] = useState({ ...delivery, cost: delivery.cost || '' });
    const valid = draft.recipient_name.trim() !== '' && draft.address.trim() !== '';

    return (
        <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-delivery-title">
            <div className="pos-modal-card">
                <h2 id="pos-delivery-title">Pengiriman</h2>
                <div className="pos-delivery">
                    <input autoFocus aria-label="Nama penerima" placeholder="Nama penerima *" value={draft.recipient_name} onChange={(e) => setDraft({ ...draft, recipient_name: e.target.value })} />
                    <input aria-label="Telepon" placeholder="Telepon" value={draft.phone} onChange={(e) => setDraft({ ...draft, phone: e.target.value })} />
                    <input aria-label="Alamat" placeholder="Alamat *" value={draft.address} onChange={(e) => setDraft({ ...draft, address: e.target.value })} />
                    <input aria-label="Catatan" placeholder="Catatan" value={draft.note} onChange={(e) => setDraft({ ...draft, note: e.target.value })} />
                    <input aria-label="Biaya kirim" inputMode="numeric" placeholder="Biaya kirim" value={draft.cost} onChange={(e) => setDraft({ ...draft, cost: Number(e.target.value.replace(/[^\d]/g, '')) || '' })} />
                </div>
                <div className="pos-modal-actions">
                    {delivery.enabled && (
                        <button className="btn btn-outline" onClick={() => onSave({ enabled: false, recipient_name: '', phone: '', address: '', note: '', cost: 0 })}>Hapus Pengiriman</button>
                    )}
                    <button className="btn btn-outline" onClick={onClose}>Batal</button>
                    <button className="btn btn-filled" disabled={!valid} onClick={() => onSave({ ...draft, enabled: true, cost: Number(draft.cost || 0) })}>Simpan</button>
                </div>
            </div>
        </div>
    );
}

// F6: transaksi gerai pada business date aktif. Transaksi offline yang belum tersinkron hanya ada di perangkat ini.
function TransactionsModal({ online, boot, currency, meta, onClose }) {
    const [rows, setRows] = useState([]);
    const [businessDate, setBusinessDate] = useState(boot.business?.posting_date);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(online);
    const [printing, setPrinting] = useState(null);

    useEffect(() => {
        if (!online) return;
        (async () => {
            try {
                const res = await api.todaySales();
                setRows(res.data);
                setBusinessDate(res.business_date);
            } catch (e) {
                setError(e.message);
            }
            setLoading(false);
        })();
    }, [online]);

    const reprint = async (row) => {
        setError(null);
        setPrinting(row.id);
        try {
            const res = await api.showSale(row.id);
            printReceipt(res.data, meta);
        } catch (e) {
            setError(e.message);
        }
        setPrinting(null);
    };

    const waiting = readQueue().map((item) => ({
        key: item.client_uuid,
        number: 'Belum tersinkron',
        customer: item.local_summary?.customer_name || '–',
        total: item.local_summary?.total ?? 0,
        tax_total: item.local_summary?.tax_total ?? 0,
    }));

    return (
        <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-trx-title">
            <div className="pos-modal-card pos-modal-wide">
                <h2 id="pos-trx-title">Transaksi {businessDate ? formatIsoDate(businessDate) : ''}</h2>
                <p className="card-subtitle" style={{ marginTop: 0 }}>
                    Hanya transaksi {boot.store?.code} pada business date ini.{!online && ' Offline: hanya transaksi di perangkat ini yang tampil.'}
                </p>
                {error && <div className="md-snackbar error" role="alert">{error}</div>}
                {loading && <div className="empty-state">Memuat transaksi…</div>}
                <table className="pos-table pos-mini-table">
                    <caption className="sr-only">Transaksi pada business date aktif</caption>
                    <thead>
                        <tr>
                            <th>Nomor</th>
                            <th>Pelanggan</th>
                            <th className="col-num">Penjualan</th>
                            <th className="col-num">Pajak</th>
                            <th>Status</th>
                            <th><span className="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {waiting.map((r) => (
                            <tr key={r.key}>
                                <td className="sku">{r.number}</td>
                                <td>{r.customer}</td>
                                <td className="col-num money">{fmt(r.total, currency)}</td>
                                <td className="col-num money">{fmt(r.tax_total, currency)}</td>
                                <td><span className="chip chip-pending"><span className="material-symbols-rounded" aria-hidden="true">schedule</span>Offline · tertunda</span></td>
                                <td />
                            </tr>
                        ))}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="sku">{r.number}</td>
                                <td>{r.customer ? `${r.customer.code || ''} ${r.customer.name}`.trim() : '–'}</td>
                                <td className="col-num money">{fmt(r.total, currency)}</td>
                                <td className="col-num money">{fmt(r.tax_total, currency)}</td>
                                <td>
                                    {r.status === 'void' && <span className="chip chip-danger"><span className="material-symbols-rounded" aria-hidden="true">block</span>Void</span>}
                                    {r.status !== 'void' && r.origin === 'offline' && <span className="chip chip-offline"><span className="material-symbols-rounded" aria-hidden="true">wifi_off</span>Offline</span>}
                                </td>
                                <td><button className="btn btn-tonal btn-sm" disabled={printing === r.id} onClick={() => reprint(r)}>Cetak Ulang</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!loading && rows.length === 0 && waiting.length === 0 && <div className="empty-state">Belum ada transaksi pada business date ini.</div>}
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Tutup</button>
                </div>
            </div>
        </div>
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

function PaymentModal({ boot, currency, totals, onClose, onConfirm }) {
    const [rows, setRows] = useState([{ payment_type_id: boot.payment_types[0]?.id || '', amount: totals.total, status: 'confirmed' }]);
    const paid = rows.reduce((s, r) => s + Number(r.amount || 0), 0);
    const change = Math.max(0, paid - totals.total);
    const hasPending = rows.some((r) => r.status === 'pending');
    const needsApproval = approvalMissing(rows, boot.payment_types);

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
                            {requiresApproval(boot.payment_types, r.payment_type_id) && (
                                <input
                                    className="pos-approval"
                                    aria-label="Kode approval"
                                    placeholder={r.status === 'confirmed' ? 'Kode approval *' : 'Kode approval'}
                                    maxLength={50}
                                    value={r.approval_code || ''}
                                    onChange={(e) => setRow(i, { approval_code: e.target.value })}
                                />
                            )}
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

                {hasPending && <div className="pos-pending-note">Ada pembayaran pending — akan dikonfirmasi setelah tersinkron.</div>}

                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onClose}>Batal</button>
                    <button className="btn btn-filled" onClick={() => onConfirm(normalizePayments(rows, boot.payment_types))} disabled={paid <= 0 || needsApproval}>Konfirmasi</button>
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
    const [autoPrint, setAutoPrintState] = useState(getAutoPrint());

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
                <p className="card-subtitle">Jika WebUSB tidak tersedia, struk dicetak lewat dialog cetak browser. Agar struk langsung keluar tanpa dialog, jalankan Chrome dengan opsi <code>--kiosk-printing</code>.</p>
                <label className="pos-check">
                    <input
                        type="checkbox"
                        checked={autoPrint}
                        onChange={(e) => { setAutoPrintState(e.target.checked); setAutoPrint(e.target.checked); }}
                    />
                    Cetak struk otomatis setelah pembayaran
                </label>
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
                        EOD {formatIsoDate(prompt.backdate_date)} belum dijalankan. Jika Anda melanjutkan, semua transaksi
                        akan tercatat sebagai transaksi {formatIsoDate(prompt.backdate_date)} (termasuk tanggal posting di SAP).
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

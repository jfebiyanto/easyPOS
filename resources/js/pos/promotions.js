// Cermin dari app/Support/PromotionService.php. Keduanya diuji dengan kasus yang sama
// (tests/fixtures/promotion-cases.json) karena server menolak (409) hasil yang berbeda.

const CACHE_KEY = 'easypos.pos.promotions';

const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
const compare = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

export function readCachedPromotions() {
    try {
        return JSON.parse(localStorage.getItem(CACHE_KEY)) || [];
    } catch {
        return [];
    }
}

export function writeCachedPromotions(promotions) {
    try {
        localStorage.setItem(CACHE_KEY, JSON.stringify(promotions));
    } catch {
        /* cache hanya untuk mode offline */
    }
}

/**
 * Promosi aktif pada waktu transaksi (batas periode kosong = tanpa batas).
 */
export function activePromotions(promotions, at = new Date()) {
    const time = at.getTime();
    return promotions.filter(
        (p) =>
            p.is_active !== false &&
            (!p.starts_at || new Date(p.starts_at).getTime() <= time) &&
            (!p.ends_at || new Date(p.ends_at).getTime() >= time),
    );
}

function itemDiscount(promotion, line) {
    const qty = Number(line.qty);
    const unitPrice = Number(line.unit_price);
    const value = Number(promotion.value);

    if (promotion.product_id != null && promotion.product_id !== line.product_id) return 0;
    if (qty < Math.max(1, Number(promotion.min_qty) || 0)) return 0;

    switch (promotion.type) {
        case 'discount_percent':
            return round2((unitPrice * qty * Math.min(value, 100)) / 100);
        case 'discount_amount':
            return round2(Math.min(value, unitPrice) * qty);
        case 'special_price':
            return round2(Math.max(0, unitPrice - value) * qty);
        default:
            return 0;
    }
}

function collectBuyXGetY(promotion, lines, qtyByProduct, candidates) {
    const buyQty = Number(promotion.buy_qty) || 0;
    const getQty = Number(promotion.get_qty) || 0;
    const buyProductId = promotion.product_id;

    if (buyQty < 1 || getQty < 1 || buyProductId == null) return null;

    const bonusProductId = promotion.get_product_id ?? buyProductId;
    const boughtUnits = Math.floor(qtyByProduct[buyProductId] || 0);
    const limit = Number(promotion.max_applications) || Infinity;

    let freeUnits;
    let missing;

    if (bonusProductId === buyProductId) {
        const applications = Math.min(Math.floor(boughtUnits / (buyQty + getQty)), limit);
        freeUnits = applications * getQty;

        const remainder = boughtUnits - applications * (buyQty + getQty);
        missing = applications < limit && remainder >= buyQty ? buyQty + getQty - remainder : 0;
    } else {
        const applications = Math.min(Math.floor(boughtUnits / buyQty), limit);
        freeUnits = applications * getQty;

        const bonusUnits = Math.floor(qtyByProduct[bonusProductId] || 0);
        missing = Math.max(0, freeUnits - bonusUnits);
    }

    lines.forEach((line, key) => {
        if (freeUnits <= 0 || line.product_id !== bonusProductId) return;

        const units = Math.min(freeUnits, Math.floor(Number(line.qty)));
        if (units > 0) {
            (candidates[key] ||= []).push([promotion, round2(units * Number(line.unit_price))]);
            freeUnits -= units;
        }
    });

    if (missing <= 0) return null;

    return { promotion_id: promotion.id, promotion_name: promotion.name, product_id: bonusProductId, qty: missing };
}

/**
 * @param {{product_id: string, qty: number, unit_price: number}[]} lines
 * @param {object[]} promotions promosi yang sudah difilter aktif
 * @returns {{lines: {promotion_id: ?string, promotion_name: ?string, promotion_discount: number}[], bonus_hints: object[]}}
 */
export function applyPromotions(lines, promotions) {
    const sorted = [...promotions].sort(
        (a, b) => compare(a.product_id == null, b.product_id == null) || compare(a.code, b.code),
    );

    const qtyByProduct = {};
    lines.forEach((line) => {
        qtyByProduct[line.product_id] = (qtyByProduct[line.product_id] || 0) + Number(line.qty);
    });

    const candidates = {};
    const bonusHints = [];

    sorted.forEach((promotion) => {
        if (promotion.type === 'buy_x_get_y') {
            const hint = collectBuyXGetY(promotion, lines, qtyByProduct, candidates);
            if (hint) bonusHints.push(hint);
            return;
        }

        lines.forEach((line, key) => {
            const discount = itemDiscount(promotion, line);
            if (discount > 0) (candidates[key] ||= []).push([promotion, discount]);
        });
    });

    return {
        lines: lines.map((_, key) => {
            let best = null;
            (candidates[key] || []).forEach((candidate) => {
                if (best === null || candidate[1] > best[1]) best = candidate;
            });

            return {
                promotion_id: best ? best[0].id : null,
                promotion_name: best ? best[0].name : null,
                promotion_discount: best ? best[1] : 0,
            };
        }),
        bonus_hints: bonusHints,
    };
}

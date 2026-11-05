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

const META_KEY = 'easypos.pos.promotions.meta';

/** Hierarki promosi dan price list gerai, disimpan bersama promosi untuk mode offline. */
export function readCachedPromotionMeta() {
    try {
        return JSON.parse(localStorage.getItem(META_KEY)) || { hierarchy: null, price_list_code: null };
    } catch {
        return { hierarchy: null, price_list_code: null };
    }
}

export function writeCachedPromotionMeta(meta) {
    try {
        localStorage.setItem(META_KEY, JSON.stringify(meta));
    } catch {
        /* cache hanya untuk mode offline */
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

/** Kunci sumber di hierarki promosi (cermin Promotion::hierarchyKey). */
export function hierarchyKey(promotion) {
    if (promotion.source === 'sap') {
        return promotion.type === 'volume_price' ? 'sap_period_volume' : 'sap_discount_group';
    }

    return promotion.type;
}

/** Pembatas pelanggan dan price list (cermin PromotionService::appliesToContext). */
function appliesToContext(promotion, context) {
    if (promotion.price_list_code != null && String(promotion.price_list_code) !== String(context.price_list_code ?? '')) {
        return false;
    }

    const customer = context.customer ?? null;

    switch (promotion.customer_scope ?? 'all') {
        case 'customer_group':
            return customer !== null && String(customer.group_code ?? '') === String(promotion.customer_ref);
        case 'customer':
            return customer !== null && String(customer.code ?? '') === String(promotion.customer_ref);
        default:
            return true;
    }
}

function volumeDiscount(promotion, unitPrice, lineQty, totalQty) {
    const tier = (promotion.tiers || [])
        .filter((t) => Number(t.min_qty) <= totalQty)
        .sort((a, b) => Number(a.min_qty) - Number(b.min_qty))
        .pop();

    return tier === undefined ? 0 : Math.max(0, unitPrice - Number(tier.price)) * lineQty;
}

function itemDiscount(promotion, line, qtyByProduct) {
    const qty = Number(line.qty);
    const unitPrice = Number(line.unit_price);
    const value = Number(promotion.value);

    if (promotion.product_id != null && promotion.product_id !== line.product_id) return 0;
    if (promotion.brand_id != null && promotion.brand_id !== (line.brand_id ?? null)) return 0;
    if (promotion.product_group_id != null && promotion.product_group_id !== (line.product_group_id ?? null)) return 0;
    if (qty < Math.max(1, Number(promotion.min_qty) || 0)) return 0;

    switch (promotion.type) {
        case 'discount_percent':
            return round2((unitPrice * qty * Math.min(value, 100)) / 100);
        case 'discount_amount':
            return round2(Math.min(value, unitPrice) * qty);
        case 'special_price':
            return round2(Math.max(0, unitPrice - value) * qty);
        case 'volume_price':
            return round2(volumeDiscount(promotion, unitPrice, qty, qtyByProduct[line.product_id] ?? qty));
        default:
            return 0;
    }
}

/** Gabungkan baris Discount Group SAP satu grup pada satu baris (cermin PromotionService::combineGroups). */
function combineGroups(candidates, line) {
    const result = [];
    const groups = new Map();

    candidates.forEach((candidate) => {
        const key = candidate[0].group_key;
        if (key == null) {
            result.push(candidate);
            return;
        }
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(candidate);
    });

    groups.forEach((members) => {
        if (members.length === 1) {
            result.push(members[0]);
            return;
        }

        const percents = members.map((m) => Math.min(Number(m[0].value), 100));
        let combined;
        switch (members[0][0].group_relation) {
            case 'highest':
                combined = Math.max(...percents);
                break;
            case 'average':
                combined = percents.reduce((a, b) => a + b, 0) / percents.length;
                break;
            case 'total':
                combined = Math.min(100, percents.reduce((a, b) => a + b, 0));
                break;
            case 'multiplied':
                combined = 100 * (1 - percents.reduce((carry, p) => carry * (1 - p / 100), 1));
                break;
            default:
                combined = Math.min(...percents);
        }

        const gross = Number(line.unit_price) * Number(line.qty);
        result.push([members[0][0], round2((gross * combined) / 100)]);
    });

    return result;
}

/** Pilih pemenang satu baris menurut hierarki, atau terbesar menang bila tanpa hierarki. */
function pickBest(candidates, line, hierarchy) {
    let best = null;
    let bestRank = Infinity;

    combineGroups(candidates, line).forEach((candidate) => {
        let rank = 0;
        if (hierarchy != null) {
            const position = hierarchy.indexOf(hierarchyKey(candidate[0]));
            rank = position === -1 ? hierarchy.length : position;
        }

        if (best === null || rank < bestRank || (rank === bestRank && candidate[1] > best[1])) {
            best = candidate;
            bestRank = rank;
        }
    });

    return best;
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
 * @param {{customer?: ?{code?: ?string, group_code?: ?string}, price_list_code?: ?string}} context pelanggan dan price list gerai
 * @param {?string[]} hierarchy urutan kunci sumber diskon; null = terbesar menang
 * @returns {{lines: {promotion_id: ?string, promotion_name: ?string, promotion_discount: number}[], bonus_hints: object[]}}
 */
export function applyPromotions(lines, promotions, context = {}, hierarchy = null) {
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
        if (!appliesToContext(promotion, context)) return;

        if (promotion.type === 'buy_x_get_y') {
            const hint = collectBuyXGetY(promotion, lines, qtyByProduct, candidates);
            if (hint) bonusHints.push(hint);
            return;
        }

        lines.forEach((line, key) => {
            const discount = itemDiscount(promotion, line, qtyByProduct);
            if (discount > 0) (candidates[key] ||= []).push([promotion, discount]);
        });
    });

    return {
        lines: lines.map((line, key) => {
            const best = pickBest(candidates[key] || [], line, hierarchy);

            return {
                promotion_id: best ? best[0].id : null,
                promotion_name: best ? best[0].name : null,
                promotion_discount: best ? best[1] : 0,
            };
        }),
        bonus_hints: bonusHints,
    };
}

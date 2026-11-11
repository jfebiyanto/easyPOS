const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function req(method, url, body, init = {}) {
    const res = await fetch(url, {
        ...init,
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const err = new Error(data.message || 'Permintaan gagal.');
        err.status = res.status;
        err.errors = data.errors;
        err.data = data;

        // Akun ini sedang aktif di perangkat lain (satu sesi POS aktif per kasir): kasir diarahkan ke layar konflik.
        if (res.status === 409 && data.code === 'pos_session_active') {
            window.dispatchEvent(new CustomEvent('pos-session-conflict', { detail: data }));
        }

        throw err;
    }

    return data;
}

export const api = {
    bootstrap: () => req('GET', '/pos/bootstrap'),
    acknowledgeSession: () => req('POST', '/pos/session/acknowledge'),
    openStore: (body) => req('POST', '/pos/store/open', body),
    startShift: (body) => req('POST', '/pos/shift/start', body),
    endShift: () => req('POST', '/pos/shift/end'),
    logout: () => req('POST', '/logout'),
    products: (q = '') => req('GET', '/pos/products' + (q ? `?q=${encodeURIComponent(q)}` : '')),
    promotions: () => req('GET', '/pos/promotions'),
    saveCart: (payload, init) => req('PUT', '/pos/cart', { payload }, init),
    restoreCart: () => req('POST', '/pos/cart/restore'),
    heartbeat: () => req('POST', '/pos/session/heartbeat'),
    takeoverSession: () => req('POST', '/pos/session/takeover'),
    logCartEvent: (body, init) => req('POST', '/pos/cart/events', body, init),
    createSale: (payload) => req('POST', '/pos/sales', payload),
    todaySales: () => req('GET', '/pos/sales'),
    showSale: (id) => req('GET', `/pos/sales/${id}`),
    lookupSale: (number) => req('GET', '/pos/sales-lookup?number=' + encodeURIComponent(number)),
    createReturn: (payload) => req('POST', '/pos/returns', payload),
    holds: () => req('GET', '/pos/holds'),
    saveHold: (payload) => req('POST', '/pos/holds', payload),
    deleteHold: (id) => req('DELETE', `/pos/holds/${id}`),
    consumeHold: (id) => req('POST', `/pos/holds/${id}/consume`),
    releaseHold: (id) => req('POST', `/pos/holds/${id}/release`),
    customers: (q = '') => req('GET', '/pos/customers' + (q ? `?q=${encodeURIComponent(q)}` : '')),
    createCustomer: (payload) => req('POST', '/pos/customers', payload),
};

export const uuid = () =>
    (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    }));

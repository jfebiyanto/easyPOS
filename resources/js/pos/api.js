const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function req(method, url, body) {
    const res = await fetch(url, {
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
        throw err;
    }

    return data;
}

export const api = {
    bootstrap: () => req('GET', '/pos/bootstrap'),
    products: (q = '') => req('GET', '/pos/products' + (q ? `?q=${encodeURIComponent(q)}` : '')),
    createSale: (payload) => req('POST', '/pos/sales', payload),
    lookupSale: (number) => req('GET', '/pos/sales-lookup?number=' + encodeURIComponent(number)),
    createReturn: (payload) => req('POST', '/pos/returns', payload),
    holds: () => req('GET', '/pos/holds'),
    saveHold: (payload) => req('POST', '/pos/holds', payload),
    deleteHold: (id) => req('DELETE', `/pos/holds/${id}`),
    customers: (q = '') => req('GET', '/pos/customers' + (q ? `?q=${encodeURIComponent(q)}` : '')),
    createCustomer: (payload) => req('POST', '/pos/customers', payload),
};

export const uuid = () =>
    (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    }));

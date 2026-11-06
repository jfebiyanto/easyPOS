// Satu-satunya sumber data section "Penjualan Hari Ini". Untuk memakai sumber lain (mis. mock saat
// pengembangan), cukup ganti fungsi ini selama bentuk datanya sama dengan DashboardService::today().

export async function fetchTodaySales(url) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new Error(body.message || 'Failed to load the sales data for today.');
    }

    return body.data;
}

// Ringkasan hari ini (jumlah transaksi penjualan/retur dan penjualan per gerai); bentuk data = DashboardService::overview().
export async function fetchOverview(url) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new Error(body.message || 'Failed to load the summary for today.');
    }

    return body.data;
}

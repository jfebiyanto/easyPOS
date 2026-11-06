import ApexCharts from 'apexcharts';
import { fetchOverview } from './data.js';
import { formatCompact, formatNumber, formatRupiah } from './format.js';

const REFRESH_MS = 5 * 60 * 1000; // 5 menit

const root = document.getElementById('store-overview');

if (root) {
    const $ = (selector) => root.querySelector(selector);
    const chartEl = $('[data-ov-chart="stores"]');
    let data = null;

    // Perbandingan penjualan hari ini antar gerai (hanya ada untuk Owner).
    const chart = chartEl
        ? new ApexCharts(chartEl, {
            chart: { type: 'bar', height: 260, toolbar: { show: false }, fontFamily: 'Inter, sans-serif', animations: { enabled: true, speed: 600 } },
            series: [{ name: 'Sales', data: [] }],
            colors: ['#1E9CFF'],
            plotOptions: { bar: { columnWidth: '45%', borderRadius: 4 } },
            dataLabels: { enabled: true, formatter: formatCompact, style: { fontSize: '11px' } },
            grid: { borderColor: '#E9ECF2', strokeDashArray: 4 },
            xaxis: { categories: [], labels: { style: { colors: '#9AA0AC', fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { min: 0, forceNiceScale: true, labels: { formatter: formatCompact, style: { colors: '#9AA0AC', fontSize: '11px' } } },
            tooltip: {
                x: { formatter: (_, { dataPointIndex }) => (data ? `${data.stores[dataPointIndex].code} · ${data.stores[dataPointIndex].name}` : '') },
                y: { formatter: (value, { dataPointIndex }) => `${formatRupiah(value)} (${formatNumber(data?.stores[dataPointIndex]?.transactions ?? 0)} transaksi)` },
            },
            noData: { text: 'Loading…' },
        })
        : null;
    chart?.render();

    const render = () => {
        $('[data-ov-sales-count]').textContent = formatNumber(data.sales_transactions);
        $('[data-ov-returns-count]').textContent = formatNumber(data.returns_transactions);
        $('[data-ov-date]').textContent = new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${data.date}T00:00:00Z`));
        chart?.updateOptions({
            xaxis: { categories: data.stores.map((store) => store.code) },
            series: [{ name: 'Sales', data: data.stores.map((store) => store.total) }],
        });
    };

    const showError = (message) => {
        $('[data-ov-error]').hidden = !message;
        $('[data-ov-error-text]').textContent = message || '';
    };

    const load = async () => {
        try {
            data = await fetchOverview(root.dataset.ovUrl);
            render();
            showError(null);
        } catch (e) {
            showError(data ? `${e.message} Menampilkan data terakhir.` : e.message);
        } finally {
            root.classList.remove('is-loading');
            root.setAttribute('aria-busy', 'false');
        }
    };

    load();
    setInterval(() => {
        if (!document.hidden) load();
    }, REFRESH_MS);
}

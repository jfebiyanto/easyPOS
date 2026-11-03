import ApexCharts from 'apexcharts';
import './dashboard.css';
import { fetchTodaySales } from './data.js';
import { clampPct, delta, formatCompact, formatNumber, formatRupiah, initials } from './format.js';

const COLORS = { green: '#34C38F', amber: '#F9B92A', red: '#E0245E', blue: '#1E9CFF', label: '#9AA0AC' };
const REFRESH_MS = 5 * 60 * 1000; // 5 menit

const root = document.getElementById('today-sales');

if (root) {
    const $ = (selector) => root.querySelector(selector);
    const charts = {};
    let data = null;
    let mode = 'income';

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const setText = (selector, text) => {
        const el = $(selector);
        if (el) el.textContent = text;
    };

    const renderDelta = (selector, info, useSentence = false) => {
        const el = $(selector);
        el.className = `ts-delta ts-tone-${info.tone}`;
        el.innerHTML = `<span class="material-symbols-rounded">${info.icon}</span>${escapeHtml(useSentence ? (info.sentence || info.text) : info.text)}`;
    };

    const renderArrow = (selector, info) => {
        const el = $(selector);
        el.className = `ts-arrow ts-tone-${info.tone} material-symbols-rounded`;
        el.textContent = info.direction === 'down' ? 'trending_down' : info.direction === 'up' ? 'trending_up' : 'trending_flat';
    };

    const renderList = (selector, rows, emptyText, renderRow) => {
        $(selector).innerHTML = rows.length > 0
            ? rows.map(renderRow).join('')
            : `<li class="ts-empty">${escapeHtml(emptyText)}</li>`;
    };

    const ring = (pct, variant, size = 'sm') =>
        `<span class="ts-ring ts-ring-${variant} ts-ring-${size}" style="--p:${clampPct(pct)}"><span>${formatNumber(Math.round(pct))}%</span></span>`;

    const sparkline = (el, color, name) => new ApexCharts(el, {
        chart: { type: 'area', height: 110, sparkline: { enabled: true }, fontFamily: 'Inter, sans-serif', animations: { enabled: true, speed: 600 } },
        series: [{ name, data: [] }],
        colors: [color],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0, stops: [0, 100] } },
        tooltip: {
            x: { show: true, formatter: (_, { dataPointIndex }) => data?.hours?.[dataPointIndex] ?? '' },
            y: { formatter: (v) => formatRupiah(v) },
        },
        noData: { text: '' },
    });

    const targetChart = (el) => new ApexCharts(el, {
        chart: { type: 'line', height: 230, toolbar: { show: false }, fontFamily: 'Inter, sans-serif', animations: { enabled: true, speed: 600 } },
        series: [],
        stroke: { curve: 'smooth', width: [0, 3] },
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
        dataLabels: { enabled: false },
        grid: { borderColor: '#E9ECF2', strokeDashArray: 4 },
        xaxis: { categories: [], tickAmount: 8, labels: { style: { colors: COLORS.label, fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { min: 0, forceNiceScale: true, labels: { formatter: formatCompact, style: { colors: COLORS.label, fontSize: '11px' } } },
        legend: { position: 'bottom', fontSize: '12px', labels: { colors: '#3D4148' } },
        tooltip: { shared: true, intersect: false, y: { formatter: (v) => formatRupiah(v) } },
        noData: { text: 'Memuat…' },
    });

    const targetSeries = () => (mode === 'income'
        ? {
            colors: [COLORS.blue, COLORS.amber],
            series: [
                { name: 'Pemasukan', type: 'column', data: data.performance.income_hourly },
                { name: 'Target (rata-rata 7 hari)', type: 'line', data: data.performance.target_hourly },
            ],
        }
        : {
            colors: [COLORS.red],
            series: [{ name: 'Pengeluaran (retur + diskon)', type: 'column', data: data.performance.expense_hourly }],
        });

    const render = () => {
        const { sales, products, performance } = data;

        setText('[data-ts-date]', new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${data.date}T00:00:00Z`)));

        // Kartu 1 — Total penjualan
        const salesDelta = delta(sales.delta_pct);
        setText('[data-ts-sales-total]', formatRupiah(sales.total));
        setText('[data-ts-sales-count]', `${formatNumber(sales.transactions)} transaksi`);
        renderArrow('[data-ts-sales-arrow]', salesDelta);
        renderDelta('[data-ts-sales-delta]', salesDelta);
        charts.sales.updateSeries([{ name: 'Penjualan', data: sales.hourly }]);
        renderList('[data-ts-list="cashiers"]', sales.top_cashiers, 'Belum ada transaksi hari ini.', (c) => `
            <li class="ts-row">
                <span class="ts-avatar">${escapeHtml(initials(c.name))}</span>
                <span class="ts-row-name">${escapeHtml(c.name)}</span>
                <span class="ts-pill">${formatNumber(c.transactions)} trx</span>
                <span class="ts-row-value">${formatRupiah(c.total)}</span>
                <span class="material-symbols-rounded ts-chevron">chevron_right</span>
            </li>`);

        // Kartu 2 — Produk terlaris
        const top = products.top;
        const productDelta = delta(top?.delta_pct ?? null);
        setText('[data-ts-product-name]', top ? top.name.toUpperCase() : 'BELUM ADA PRODUK TERJUAL');
        setText('[data-ts-product-total]', top ? formatRupiah(top.omzet) : formatRupiah(0));
        setText('[data-ts-product-qty]', top ? `${formatNumber(top.qty)} terjual${top.sku ? ` · ${top.sku}` : ''}` : '—');
        renderDelta('[data-ts-product-delta]', productDelta);
        charts.products.updateSeries([{ name: top ? top.name : 'Produk', data: products.hourly }]);
        renderList('[data-ts-list="products"]', products.list, 'Belum ada produk terjual hari ini.', (p) => `
            <li class="ts-row">
                ${ring(p.share_pct, 'warm')}
                <span class="ts-row-name">${escapeHtml(p.name)}<small>${escapeHtml(p.sku ?? '')}</small></span>
                <span class="ts-pill">${formatNumber(p.qty)} pcs</span>
                <span class="ts-row-value">${formatRupiah(p.omzet)}</span>
            </li>`);

        // Kartu 3 — Ringkasan performa
        $('[data-ts-ring="net"]').innerHTML = ring(performance.net_target_pct ?? 0, 'warm', 'lg');
        $('[data-ts-ring="deductions"]').innerHTML = ring(performance.deductions_pct, 'blue', 'lg');
        setText('[data-ts-net]', formatRupiah(performance.net_sales));
        setText('[data-ts-deductions]', formatRupiah(performance.deductions));
        renderDelta('[data-ts-net-delta]', delta(performance.net_delta_pct), true);
        renderDelta('[data-ts-deductions-delta]', delta(performance.deductions_delta_pct, { higherIsBetter: false }), true);
        setText('[data-ts-target-note]', performance.target_total > 0
            ? `Target hari ini ${formatRupiah(performance.target_total)} (rata-rata 7 hari terakhir)`
            : 'Belum ada data 7 hari terakhir untuk target');
        charts.target.updateOptions({ xaxis: { categories: data.hours }, ...targetSeries() });
    };

    const showError = (message) => {
        $('[data-ts-error]').hidden = !message;
        setText('[data-ts-error-text]', message || '');
    };

    const load = async () => {
        try {
            data = await fetchTodaySales(root.dataset.url);
            render();
            showError(null);
        } catch (e) {
            // Data terakhir tetap tampil; pesan error menjelaskan bahwa angka belum diperbarui.
            showError(data ? `${e.message} Menampilkan data terakhir.` : e.message);
        } finally {
            root.classList.remove('is-loading');
            root.setAttribute('aria-busy', 'false');
        }
    };

    charts.sales = sparkline($('[data-ts-chart="sales"]'), COLORS.green, 'Penjualan');
    charts.products = sparkline($('[data-ts-chart="products"]'), COLORS.amber, 'Produk');
    charts.target = targetChart($('[data-ts-chart="target"]'));
    Object.values(charts).forEach((chart) => chart.render());

    root.querySelectorAll('[data-ts-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            mode = button.dataset.tsMode;
            root.querySelectorAll('[data-ts-mode]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
            if (data) charts.target.updateOptions(targetSeries());
        });
    });

    // Menu kartu (ikon hamburger): muat ulang data atau buka laporan.
    root.querySelectorAll('[data-ts-menu]').forEach((button) => {
        const menu = button.nextElementSibling;
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = menu.hidden;
            root.querySelectorAll('.ts-menu-list').forEach((m) => { m.hidden = true; });
            menu.hidden = !open;
        });
    });
    document.addEventListener('click', () => root.querySelectorAll('.ts-menu-list').forEach((m) => { m.hidden = true; }));
    root.querySelectorAll('[data-ts-refresh]').forEach((button) => button.addEventListener('click', load));

    load();
    setInterval(() => {
        if (!document.hidden) load();
    }, REFRESH_MS);
}

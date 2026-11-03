// Jam & tanggal bisnis mengikuti zona waktu perusahaan dan jam server (bukan jam perangkat kasir),
// supaya tanggal yang tampil sama dengan tanggal bisnis yang dipakai server saat menyimpan transaksi.

export const serverOffset = (serverTime, clientNow = Date.now()) =>
    serverTime ? new Date(serverTime).getTime() - clientNow : 0;

export function formatBusinessClock(at, timeZone) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' })
            .formatToParts(at)
            .map((p) => [p.type, p.value]),
    );

    return {
        isoDate: `${parts.year}-${parts.month}-${parts.day}`,
        date: new Intl.DateTimeFormat('id-ID', { timeZone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(at),
        time: new Intl.DateTimeFormat('id-ID', { timeZone, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZoneName: 'short' })
            .format(at)
            .replace(/\./g, ':'),
    };
}

export const formatIsoDate = (isoDate) =>
    new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${isoDate}T00:00:00Z`));

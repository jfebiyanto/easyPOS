import React, { useCallback, useEffect, useRef, useState } from 'react';
import { api } from './api.js';
import { acquireTabLock } from './tabLock.js';

/**
 * Hanya satu tab POS per browser. Tab kedua melihat layar pemblokir (dengan pilihan memindahkan POS ke tab
 * itu, yang membuat tab lama diblokir), dan percobaan membuka tab ganda dicatat di Log Aktivitas.
 */
export default function SingleTabGate({ children }) {
    const [status, setStatus] = useState('checking'); // checking | active | blocked | lost
    const releaseRef = useRef(() => {});

    const acquire = useCallback((steal) => {
        releaseRef.current();
        releaseRef.current = acquireTabLock(navigator.locks ?? null, {
            steal,
            onAcquired: () => setStatus('active'),
            onBlocked: () => {
                setStatus('blocked');
                api.logCartEvent({ event: 'duplicate_tab' }).catch(() => {});
            },
            onLost: () => setStatus('lost'),
        });
    }, []);

    useEffect(() => {
        acquire(false);

        return () => releaseRef.current();
    }, [acquire]);

    if (status === 'active') return children;
    if (status === 'checking') return <div className="pos-loading">Memuat POS…</div>;

    return (
        <div className="pos-loading">
            <div className="pos-boot-error" role="alertdialog" aria-labelledby="pos-tab-title">
                <h2 id="pos-tab-title">POS sedang dibuka di tab lain</h2>
                <p>
                    {status === 'lost'
                        ? 'POS dipindahkan ke tab lain di browser ini.'
                        : 'Untuk mencegah transaksi ganda, POS hanya boleh terbuka di satu tab per browser.'}
                    {' '}Tutup tab ini, atau pindahkan POS ke tab ini. Keranjang yang sedang berjalan tidak hilang.
                </p>
                <button className="btn btn-filled" onClick={() => acquire(true)}>Pindahkan POS ke tab ini</button>
            </div>
        </div>
    );
}

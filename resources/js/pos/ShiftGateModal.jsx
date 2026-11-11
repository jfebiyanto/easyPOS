import React, { useState } from 'react';
import { api } from './api.js';
import { countFloat, floatVariance } from './shiftGate.js';
import { formatIsoDate } from './businessClock.js';

const rupiah = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });

const formatTime = (iso) => (iso ? new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '-');

function LogoutButton({ label = 'Sign out' }) {
    return (
        <form method="POST" action="/logout">
            <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
            <button className="btn btn-outline" type="submit">{label}</button>
        </form>
    );
}

// Gerbang sebelum layar penjualan: Open Store (toko belum dibuka), Start Shift (toko sudah dibuka), atau
// pemblokir saat EOD sudah ditutup. Kasir menghitung modal awal laci uangnya; selisih dari modal standar wajib diberi remark.
export function ShiftGateModal({ screen, gate, onStarted }) {
    const [counts, setCounts] = useState({});
    const [amount, setAmount] = useState('');
    const [remark, setRemark] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});

    if (screen === 'blocked') {
        return (
            <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-shift-title">
                <div className="pos-modal-card pos-modal-sm">
                    <h2 id="pos-shift-title">The store is closed</h2>
                    <p>End of Day for {formatIsoDate(gate.posting_date)} has been run, so transactions are locked. Contact the Store Manager or Owner if EOD needs to be reopened.</p>
                    <div className="pos-modal-actions"><LogoutButton /></div>
                </div>
            </div>
        );
    }

    const opening = screen === 'open_store';
    const byDenomination = gate.count_by_denomination;
    const counted = byDenomination ? countFloat(counts) : Number(amount || 0);
    const ready = byDenomination || amount !== '';
    const variance = floatVariance(counted, gate.opening_float_standard);

    const submit = async () => {
        setBusy(true);
        setError(null);
        setFieldErrors({});
        try {
            const body = byDenomination ? { denominations: counts, remark } : { amount: Number(amount), remark };
            const res = opening ? await api.openStore(body) : await api.startShift(body);
            onStarted(res.data);
        } catch (e) {
            setFieldErrors(e.errors || {});
            setError(e.message);
            setBusy(false);
        }
    };

    return (
        <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-shift-title">
            <div className="pos-modal-card">
                <h2 id="pos-shift-title">{opening ? 'Open Store' : 'Start Shift'}</h2>
                <p>
                    Business date: <strong>{formatIsoDate(gate.posting_date)}</strong>
                    {!opening && gate.opened_by && <> · Store opened by <strong>{gate.opened_by}</strong> at {formatTime(gate.opened_at)}</>}
                </p>
                <p>Count the opening float (change money) you received. Standard store float: <strong>{rupiah(gate.opening_float_standard)}</strong>.</p>

                {byDenomination ? (
                    <table className="pos-shift-count">
                        <thead><tr><th>Denomination</th><th>Quantity</th><th>Subtotal</th></tr></thead>
                        <tbody>
                            {gate.denominations.map((value) => (
                                <tr key={value}>
                                    <td>{rupiah(value)}</td>
                                    <td>
                                        <input
                                            type="number"
                                            min="0"
                                            inputMode="numeric"
                                            aria-label={`Quantity ${rupiah(value)}`}
                                            value={counts[value] ?? ''}
                                            onChange={(e) => setCounts((prev) => ({ ...prev, [value]: e.target.value }))}
                                        />
                                    </td>
                                    <td className="money">{rupiah(value * (parseInt(counts[value], 10) || 0))}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <label className="pos-shift-amount">
                        Opening float amount (Rp)
                        <input type="number" min="0" inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} />
                    </label>
                )}
                {fieldErrors.denominations && <p className="pos-session-error">{fieldErrors.denominations[0]}</p>}
                {fieldErrors.amount && <p className="pos-session-error">{fieldErrors.amount[0]}</p>}

                <p className="money"><strong>Total counted: {rupiah(counted)}</strong></p>

                {ready && variance !== 0 && (
                    <div className="pos-session-warning">
                        The float is {rupiah(Math.abs(variance))} {variance < 0 ? 'short of' : 'over'} the standard float. Explain the difference.
                        <input
                            type="text"
                            maxLength={500}
                            aria-label="Float difference remark"
                            placeholder="Remark is required"
                            value={remark}
                            onChange={(e) => setRemark(e.target.value)}
                        />
                        {fieldErrors.remark && <p className="pos-session-error">{fieldErrors.remark[0]}</p>}
                    </div>
                )}

                {error && <p className="pos-session-error">{error}</p>}
                <div className="pos-modal-actions">
                    <LogoutButton />
                    <button className="btn btn-filled" onClick={submit} disabled={busy || !ready || (variance !== 0 && remark.trim() === '')}>
                        {opening ? 'Open Store' : 'Start Shift'}
                    </button>
                </div>
            </div>
        </div>
    );
}

// Keluar mengakhiri shift kasir (toko tetap terbuka sampai End of Day). Tanpa shift aktif, cukup konfirmasi keluar.
export function LogoutConfirmModal({ shift, onCancel }) {
    return (
        <div className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-logout-title">
            <div className="pos-modal-card pos-modal-sm">
                <h2 id="pos-logout-title">{shift ? 'End your shift?' : 'Sign out of POS?'}</h2>
                {shift && (
                    <p>Your shift started at <strong>{formatTime(shift.started_at)}</strong> with <strong>{shift.sales_count}</strong> transactions. The store stays open until End of Day is run.</p>
                )}
                <div className="pos-modal-actions">
                    <button className="btn btn-outline" onClick={onCancel}>Cancel</button>
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
                        <button className="btn btn-filled" type="submit">{shift ? 'End Shift & Sign out' : 'Sign out'}</button>
                    </form>
                </div>
            </div>
        </div>
    );
}

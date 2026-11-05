/**
 * Pembayaran non-tunai (tipe dipetakan ke kartu kredit SAP: QRIS, EDC/kartu) mencatat kode approval
 * dari EDC/penyedia. Kode ini hanya disimpan di easyPOS, tidak dikirim ke SAP.
 */
export function requiresApproval(paymentTypes, paymentTypeId) {
    return Boolean(paymentTypes?.find((pt) => pt.id === paymentTypeId)?.requires_approval);
}

/**
 * Baris pembayaran yang sudah Lunas tetapi kode approval-nya belum diisi.
 */
export function approvalMissing(rows, paymentTypes) {
    return rows.some((r) => requiresApproval(paymentTypes, r.payment_type_id)
        && r.status === 'confirmed'
        && String(r.approval_code ?? '').trim() === '');
}

/**
 * Bentuk baris untuk dikirim ke server: kode approval dirapikan, dan dibuang untuk tipe yang tidak memerlukannya.
 */
export function normalizePayments(rows, paymentTypes) {
    return rows.map(({ approval_code: code, ...row }) => {
        const approval = String(code ?? '').trim();

        return requiresApproval(paymentTypes, row.payment_type_id) && approval !== ''
            ? { ...row, approval_code: approval }
            : row;
    });
}

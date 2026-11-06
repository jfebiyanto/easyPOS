<?php

namespace App\Models\Concerns;

/**
 * Konstanta status posting ke SAP B1 untuk dokumen easyPOS (pending, posted, failed).
 */
trait PostsToSap
{
    public const SAP_PENDING = 'pending';

    public const SAP_POSTED = 'posted';

    public const SAP_FAILED = 'failed';
}

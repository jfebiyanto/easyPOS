<?php

namespace App\Support\Sap;

use App\Models\CompanyDetail;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;

/**
 * Posting Stock Transfer Shipment dan Receipt easyPOS ke SAP B1 sebagai Stock Transfer (OWTR).
 */
class SapStockTransferPosting
{
    public const OBJ_STOCK_TRANSFER = 67;

    /**
     * Tandai dokumen menunggu posting bila integrasi aktif dan dokumen belum terposting.
     */
    public static function queue(StockTransferShipment|StockTransferReceipt $doc): void
    {
        $company = CompanyDetail::find($doc->company_id);

        if (! $company?->sapIntegrationActive() || $doc->sap_post_status === $doc::SAP_POSTED) {
            return;
        }

        $doc->forceFill(['sap_post_status' => $doc::SAP_PENDING, 'sap_post_error' => null])->saveQuietly();
    }
}

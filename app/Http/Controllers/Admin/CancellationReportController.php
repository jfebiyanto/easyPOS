<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\PosCancellation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Laporan pembatalan transaksi untuk pengawasan: gerai, kasir, pelanggan, isi barang, waktu lengkap, IP, dan perangkat.
 * Owner melihat semua gerai; Store Manager hanya gerainya.
 */
class CancellationReportController extends Controller
{
    private const EXPORT_LIMIT = 5000;

    /** Baris terbaru per gerai yang ditampilkan; satu gerai dipilih (filter) menampilkan lebih banyak. */
    private const ROWS_PER_STORE = 50;

    private const ROWS_SINGLE_STORE = 200;

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);
        $query = $this->filtered($request, $from, $to);
        $limit = $request->query('store') ? self::ROWS_SINGLE_STORE : self::ROWS_PER_STORE;

        // Dikelompokkan per gerai: ringkasan per gerai dari agregat, lalu baris terbaru tiap gerai.
        $groups = (clone $query)->reorder()
            ->selectRaw('store_id, max(store_label) as store_label, count(*) as total_count, sum(total) as total_value, sum(case when type = ? then 1 else 0 end) as void_count', [PosCancellation::TYPE_SALE_VOID])
            ->groupBy('store_id')
            ->orderByRaw('max(store_label)')
            ->get()
            ->each(fn ($group) => $group->rows = (clone $query)
                ->where(fn (Builder $q) => $group->store_id ? $q->where('store_id', $group->store_id) : $q->whereNull('store_id'))
                ->latest('occurred_at')
                ->limit($limit)
                ->get());

        return view('admin.cancellations.index', [
            'groups' => $groups,
            'rowLimit' => $limit,
            'summary' => ['count' => (clone $query)->count(), 'total' => (float) (clone $query)->sum('total')],
            'from' => $from,
            'to' => $to,
            'stores' => $request->user()->isOwner() ? Store::where('company_id', current_company_id())->orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'cashiers' => $this->cashiers($request),
            'timezone' => CompanyDetail::current()->timezone ?: 'Asia/Jakarta',
        ]);
    }

    public function export(Request $request)
    {
        [$from, $to] = $this->range($request);
        $timezone = CompanyDetail::current()->timezone ?: 'Asia/Jakarta';

        $dir = storage_path('app/tmp');
        File::ensureDirectoryExists($dir);
        $filename = "voids_cancellations_{$from}_{$to}";
        $path = $dir.DIRECTORY_SEPARATOR.$filename.'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Time', 'Type', 'Store', 'Cashier', 'Customer', 'Sale No.', 'Reason', 'Item Count', 'Items', 'Total', 'IP', 'Device', 'Recorded Late']));

        $this->filtered($request, $from, $to)->reorder()->orderBy('store_label')->latest('occurred_at')->limit(self::EXPORT_LIMIT)->get()->each(function (PosCancellation $c) use ($writer, $timezone) {
            $writer->addRow(Row::fromValues([
                $c->occurred_at->timezone($timezone)->format('d/m/Y H:i:s'),
                PosCancellation::TYPE_LABELS[$c->type] ?? $c->type,
                $c->store_label,
                $c->cashier_name,
                $c->customer_label,
                $c->sale_number,
                $c->reason,
                $c->item_count,
                $c->itemsText(),
                (float) $c->total,
                $c->ip_address,
                $c->browser,
                $c->isLate() ? 'Yes' : 'No',
            ]));
        });
        $writer->close();

        return response()->download($path, $filename.'.xlsx')->deleteFileAfterSend(true);
    }

    /**
     * Periode bawaan 7 hari terakhir, dalam tanggal kalender zona waktu perusahaan.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $timezone = CompanyDetail::current()->timezone ?: 'Asia/Jakarta';
        $today = now($timezone);

        $from = $request->query('from') ? Carbon::parse($request->query('from'))->toDateString() : $today->copy()->subDays(6)->toDateString();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->toDateString() : $today->toDateString();

        return [$from, $to];
    }

    /**
     * @return Builder<PosCancellation>
     */
    private function filtered(Request $request, string $from, string $to): Builder
    {
        $user = $request->user();
        $timezone = CompanyDetail::current()->timezone ?: 'Asia/Jakarta';

        $startsAt = Carbon::parse($from, $timezone)->startOfDay()->utc();
        $endsAt = Carbon::parse($to, $timezone)->endOfDay()->utc();

        return PosCancellation::query()
            ->where('company_id', current_company_id())
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when(! $user->isOwner(), fn (Builder $q) => $q->where('store_id', $user->store_id))
            ->when($request->query('store'), fn (Builder $q, $store) => $user->isOwner() ? $q->where('store_id', $store) : $q)
            ->when($request->query('cashier'), fn (Builder $q, $cashier) => $q->where('cashier_id', $cashier))
            ->when(array_key_exists((string) $request->query('type'), PosCancellation::TYPE_LABELS), fn (Builder $q) => $q->where('type', $request->query('type')))
            ->when($request->query('q'), fn (Builder $q, $s) => $q->where(fn (Builder $x) => $x
                ->whereLike('customer_label', "%{$s}%")
                ->orWhereLike('cashier_name', "%{$s}%")
                ->orWhereLike('sale_number', "%{$s}%")
                ->orWhereLike('ip_address', "%{$s}%")
                ->orWhereLike('browser', "%{$s}%")));
    }

    private function cashiers(Request $request): Collection
    {
        $user = $request->user();

        return User::where('company_id', current_company_id())
            ->when(! $user->isOwner(), fn (Builder $q) => $q->where('store_id', $user->store_id))
            ->orderBy('name')
            ->get(['id', 'name', 'username']);
    }
}

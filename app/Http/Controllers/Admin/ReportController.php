<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Store;
use App\Support\ReportService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ReportController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): View
    {
        [$from, $to] = ReportService::range($request->query('from'), $request->query('to'));

        return view('admin.reports.index', [
            'reports' => ReportService::REPORTS,
            'stores' => $this->reportStores($request),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function show(Request $request, string $report): View
    {
        [$from, $to] = ReportService::range($request->query('from'), $request->query('to'));
        $data = ReportService::build($report, $from, $to, $this->reportStoreIds($request));

        return view('admin.reports.show', [
            'data' => $data,
            'stores' => $this->reportStores($request),
            'selectedStore' => $request->query('store'),
            'reports' => ReportService::REPORTS,
        ]);
    }

    public function export(Request $request)
    {
        $report = (string) $request->query('report');
        $format = (string) $request->query('format', 'xlsx');

        abort_unless(array_key_exists($report, ReportService::REPORTS), 404, 'Unknown report.');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404, 'Unsupported format.');

        [$from, $to] = ReportService::range($request->query('from'), $request->query('to'));
        $data = ReportService::build($report, $from, $to, $this->reportStoreIds($request));

        $filename = $report.'_'.$from.'_'.$to;

        return $format === 'xlsx'
            ? $this->exportXlsx($data, $filename)
            : $this->exportPdf($data, $filename);
    }

    private function reportStoreIds(Request $request): array
    {
        $user = $request->user();

        if ($user->isOwner()) {
            if ($requested = $request->query('store')) {
                return [$requested];
            }

            return Store::where('company_id', current_company_id())->pluck('id')->all();
        }

        return array_values(array_filter([$user->store_id]));
    }

    private function reportStores(Request $request): Collection
    {
        if (! $request->user()->isOwner()) {
            return collect();
        }

        return Store::where('company_id', current_company_id())->orderBy('code')->get();
    }

    private function exportXlsx(array $data, string $filename)
    {
        $dir = storage_path('app/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.$filename.'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($data['columns']));
        foreach ($data['rows'] as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return response()->download($path, $filename.'.xlsx')->deleteFileAfterSend(true);
    }

    private function exportPdf(array $data, string $filename)
    {
        $html = view('admin.reports.pdf', [
            'data' => $data,
            'company' => CompanyDetail::current(),
        ])->render();

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }
}

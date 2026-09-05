<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchGeneralReportRequest;
use App\Models\Empresa;
use App\Services\OperationContextService;
use App\Services\ProductDispatchGeneralReportService;
use App\Services\ReportPaletteService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ProductDispatchGeneralReportController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchGeneralReportService $reports,
        private readonly ReportPaletteService $reportPalettes,
    ) {}

    public function pdf(ListProductDispatchGeneralReportRequest $request): Response
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $filters = $request->validated();
        $company = Empresa::query()->whereKey($companyId)->firstOrFail();
        $report = DB::transaction(fn (): array => $this->reports->report(
            $companyId,
            $branch,
            $filters['date_from'],
            $filters['date_to'],
        ));
        $palette = $this->reportPalettes->current($company);
        $html = view('reports.product-dispatch-general', [
            'company' => $company,
            'report' => $report,
            'reportPalette' => $palette,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('tempDir', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $background = $this->reportPalettes->dompdfColor($palette['page_background']);
        $pdf->setCallbacks([[
            'event' => 'begin_page_render',
            'f' => static function (mixed $frame, mixed $canvas) use ($background): void {
                $canvas->filled_rectangle(0, 0, $canvas->get_width(), $canvas->get_height(), $background);
            },
        ]]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $pdf->getCanvas()->page_text(
            36, 568, 'Despacho de productos  |  Reporte general  |  Página {PAGE_NUM} de {PAGE_COUNT}',
            $font, 7, $this->reportPalettes->dompdfColor($palette['muted_text']),
        );
        $filename = 'reporte-general-despacho-productos-'.$report['period']['from'].'-'.$report['period']['to'].'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('preview') ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

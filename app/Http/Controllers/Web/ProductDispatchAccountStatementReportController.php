<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchAccountStatementRequest;
use App\Models\Empresa;
use App\Services\OperationContextService;
use App\Services\ProductDispatchAccountStatementService;
use App\Services\ReportPaletteService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ProductDispatchAccountStatementReportController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchAccountStatementService $statements,
        private readonly ReportPaletteService $reportPalettes,
    ) {}

    public function pdf(ListProductDispatchAccountStatementRequest $request): Response
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $validated = $request->validated();
        $company = Empresa::query()->whereKey($companyId)->firstOrFail();
        $statement = DB::transaction(fn (): array => $this->statements->statement(
            $companyId,
            $branch,
            (int) $validated['client_id'],
            $validated['date_from'],
            $validated['date_to'],
            $validated['currency'],
        ));
        $palette = $this->reportPalettes->current($company);
        $html = view('reports.product-dispatch-account-statement', [
            'company' => $company,
            'statement' => $statement,
            'reportPalette' => $palette,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('tempDir', storage_path('framework/cache'));

        $dompdf = new Dompdf($options);
        $pageBackground = $this->reportPalettes->dompdfColor($palette['page_background']);
        $mutedText = $this->reportPalettes->dompdfColor($palette['muted_text']);
        $dompdf->setCallbacks([
            [
                'event' => 'begin_page_render',
                'f' => static function (mixed $frame, mixed $canvas) use ($pageBackground): void {
                    $canvas->filled_rectangle(
                        0,
                        0,
                        $canvas->get_width(),
                        $canvas->get_height(),
                        $pageBackground,
                    );
                },
            ],
            [
                'event' => 'end_page_render',
                'f' => static function (mixed $frame, mixed $canvas, mixed $fontMetrics) use ($mutedText): void {
                    $text = 'Pagina '.$canvas->get_page_number();
                    $font = $fontMetrics->getFont('DejaVu Sans', 'normal')
                        ?: $fontMetrics->getFont('Helvetica', 'normal');
                    $size = 7;
                    $width = $fontMetrics->getTextWidth($text, $font, $size);
                    $canvas->text(
                        ($canvas->get_width() - $width) / 2,
                        $canvas->get_height() - 18,
                        $text,
                        $font,
                        $size,
                        $mutedText,
                    );
                },
            ],
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = sprintf(
            'estado-cuenta-despacho-productos-cliente-%d-%s-%s-%s.pdf',
            (int) $validated['client_id'],
            $validated['date_from'],
            $validated['date_to'],
            strtolower($validated['currency']),
        );

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('preview') ? 'inline' : 'attachment')
                .'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

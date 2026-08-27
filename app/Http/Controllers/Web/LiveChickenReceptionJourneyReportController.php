<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\LiveChickenReceptionHistoryService;
use App\Services\LiveChickenReceptionJourneyReportImageRenderer;
use App\Services\OperationContextService;
use App\Services\ReportPaletteService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class LiveChickenReceptionJourneyReportController extends Controller
{
    public function __construct(
        private readonly LiveChickenReceptionHistoryService $history,
        private readonly LiveChickenReceptionJourneyReportImageRenderer $images,
        private readonly OperationContextService $context,
        private readonly ReportPaletteService $reportPalettes,
    ) {}

    public function pdf(Request $request): Response
    {
        [$company, $report, $journeyId] = $this->reportPayload($request);
        $html = view('reports.live-chicken-reception-journey', [
            'company' => $company,
            'report' => $report,
            'reportPalette' => $this->reportPalettes->current($company),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('tempDir', storage_path('framework/cache'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, $this->downloadHeaders(
            'application/pdf',
            $this->basename($report, $journeyId).'.pdf',
        ));
    }

    public function images(Request $request): Response
    {
        [$company, $report, $journeyId] = $this->reportPayload($request);
        $pages = $this->images->render($company, $report);
        abort_if($pages === [], 500, 'No se generaron imágenes para el reporte.');

        $basename = $this->basename($report, $journeyId);
        if (count($pages) === 1) {
            return response($pages[0], 200, $this->downloadHeaders(
                'image/png',
                $basename.'.png',
            ));
        }

        $temporaryFile = tempnam(storage_path('framework/cache'), 'live-reception-report-');
        abort_if($temporaryFile === false, 500, 'No se pudo preparar el archivo de imágenes.');

        $zip = new ZipArchive;
        $zipIsOpen = false;

        try {
            $zipIsOpen = $zip->open($temporaryFile, ZipArchive::OVERWRITE) === true;
            abort_unless($zipIsOpen, 500, 'No se pudo crear el archivo de imágenes.');

            foreach ($pages as $index => $page) {
                abort_unless(
                    $zip->addFromString(
                        $basename.'-pagina-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'.png',
                        $page,
                    ),
                    500,
                    'No se pudo agregar una página al archivo de imágenes.',
                );
            }

            abort_unless(
                $zip->close(),
                500,
                'No se pudo finalizar el archivo de imágenes.',
            );
            $zipIsOpen = false;

            $contents = file_get_contents($temporaryFile);
            abort_if($contents === false, 500, 'No se pudo leer el archivo de imágenes.');
        } finally {
            if ($zipIsOpen) {
                $zip->close();
            }
            @unlink($temporaryFile);
        }

        return response($contents, 200, $this->downloadHeaders(
            'application/zip',
            $basename.'-imagenes.zip',
        ));
    }

    /**
     * @return array{0: Empresa, 1: array<string, mixed>, 2: int}
     */
    private function reportPayload(Request $request): array
    {
        $validated = $request->validate([
            'journey_id' => ['required', 'integer', 'min:1'],
        ]);
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $company = Empresa::query()->findOrFail($companyId);
        $journeyId = (int) $validated['journey_id'];

        return [
            $company,
            $this->history->report($companyId, $branch, $journeyId),
            $journeyId,
        ];
    }

    /** @param  array<string, mixed>  $report */
    private function basename(array $report, int $journeyId): string
    {
        $operatingDate = (string) data_get($report, 'journey.operating_date', '');
        $date = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $operatingDate) === 1
            ? $operatingDate
            : 'sin-fecha';

        return "recepcion-pollo-vivo-jornada-{$journeyId}-{$date}";
    }

    /** @return array<string, string> */
    private function downloadHeaders(string $contentType, string $filename): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}

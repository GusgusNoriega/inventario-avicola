<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Pago;
use App\Services\ReportDataService;
use App\Services\ReportImageRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportDataService $reports,
        private readonly ReportImageRenderer $images,
    ) {}

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->empresa_id;
        $thirdParties = fn (string $role) => DB::table('terceros as tercero')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
            ->where('tercero.empresa_id', $companyId)
            ->where('rol.rol', $role)
            ->orderBy('tercero.nombre_razon_social')
            ->get(['tercero.id', 'tercero.nombre_razon_social']);

        return view('finanzas-reportes', [
            'clients' => $thirdParties('CLIENTE'),
            'providers' => $thirdParties('PROVEEDOR'),
            'users' => DB::table('usuarios')
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'paymentMethods' => DB::table('metodos_pago')
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'paymentTypes' => Pago::TYPES,
        ]);
    }

    public function pdf(Request $request, string $type): Response
    {
        $payload = $this->payload($request, $type);
        $validated = $payload['validated'];
        $html = view('reports.pdf', $payload)->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('tempDir', storage_path('framework/cache'));
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', in_array($type, ['ventas-clientes', 'responsable'], true) ? 'landscape' : 'portrait');
        $this->addPageNumbers($dompdf);
        $dompdf->render();

        $filename = $type.'-'.$validated['desde'].'-'.$validated['hasta'].'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('descargar') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ]);
    }

    public function image(Request $request, string $type): Response
    {
        $payload = $this->payload($request, $type);
        $validated = $payload['validated'];
        $pages = $this->images->render($payload);
        $basename = $type.'-'.$validated['desde'].'-'.$validated['hasta'];

        if (count($pages) === 1) {
            return response($pages[0], 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="'.$basename.'.png"',
            ]);
        }

        $temporaryFile = tempnam(storage_path('framework/cache'), 'report-images-');
        abort_if($temporaryFile === false, 500, 'No se pudo preparar el archivo de imagenes.');
        $zip = new ZipArchive;
        abort_unless($zip->open($temporaryFile, ZipArchive::OVERWRITE) === true, 500, 'No se pudo crear el archivo de imagenes.');
        foreach ($pages as $index => $page) {
            $zip->addFromString($basename.'-pagina-'.($index + 1).'.png', $page);
        }
        $zip->close();

        try {
            $contents = file_get_contents($temporaryFile);
            abort_if($contents === false, 500, 'No se pudo leer el archivo de imagenes.');
        } finally {
            @unlink($temporaryFile);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$basename.'-imagenes.zip"',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, string $type): array
    {
        abort_unless(in_array($type, [
            'ventas-clientes',
            'estado-cliente',
            'estado-proveedor',
            'pagos',
            'responsable',
        ], true), 404);

        $request->merge([
            'tipo' => $request->filled('tipo') ? strtoupper(trim((string) $request->input('tipo'))) : null,
        ]);
        $rules = [
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'descargar' => ['nullable', 'boolean'],
        ];
        if ($type === 'estado-cliente') {
            $rules['cliente_id'] = ['required', 'integer'];
        }
        if ($type === 'estado-proveedor') {
            $rules['proveedor_id'] = ['required', 'integer'];
        }
        if ($type === 'pagos') {
            $rules['tipo'] = ['nullable', 'string', Rule::in(Pago::TYPES)];
            $rules['metodo_pago_id'] = ['nullable', 'integer'];
        }
        if ($type === 'responsable') {
            $rules['usuario_id'] = ['required', 'integer'];
        }
        $validated = $request->validate($rules);
        $companyId = (int) $request->user()->empresa_id;
        $company = Empresa::query()->findOrFail($companyId);
        $data = match ($type) {
            'ventas-clientes' => $this->reports->salesByCustomer($companyId, $validated['desde'], $validated['hasta']),
            'estado-cliente' => $this->reports->customerStatement($companyId, (int) $validated['cliente_id'], $validated['desde'], $validated['hasta']),
            'estado-proveedor' => $this->reports->providerStatement($companyId, (int) $validated['proveedor_id'], $validated['desde'], $validated['hasta']),
            'pagos' => $this->reports->payments($companyId, $validated['desde'], $validated['hasta'], $validated),
            'responsable' => $this->reports->responsibleMovements($companyId, (int) $validated['usuario_id'], $validated['desde'], $validated['hasta']),
        };
        $titles = [
            'ventas-clientes' => 'Reporte de ventas por cliente',
            'estado-cliente' => 'Estado de cuenta de cliente',
            'estado-proveedor' => 'Estado de cuenta de proveedor',
            'pagos' => 'Reporte de pagos y cobros',
            'responsable' => 'Movimientos por responsable',
        ];

        return [
            'company' => $company,
            'type' => $type,
            'title' => $titles[$type],
            'from' => $validated['desde'],
            'to' => $validated['hasta'],
            'data' => $data,
            'generatedAt' => now($company->zona_horaria ?: config('app.timezone')),
            'validated' => $validated,
        ];
    }

    private function addPageNumbers(Dompdf $dompdf): void
    {
        $dompdf->setCallbacks([[
            'event' => 'end_page_render',
            'f' => function (mixed $frame, mixed $canvas, mixed $fontMetrics): void {
                $text = 'Pagina '.$canvas->get_page_number();
                $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
                $width = $fontMetrics->getTextWidth($text, $font, 8);
                $canvas->text(
                    ($canvas->get_width() - $width) / 2,
                    $canvas->get_height() - 22,
                    $text,
                    $font,
                    8,
                    [0.32, 0.35, 0.4],
                );
            },
        ]]);
    }
}

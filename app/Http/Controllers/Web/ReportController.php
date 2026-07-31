<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EntidadFinanciera;
use App\Models\Pago;
use App\Services\ReportDataService;
use App\Services\ReportImageRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
                ->orderBy('estado')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'estado']),
            'paymentMethods' => DB::table('metodos_pago')
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'paymentTypes' => Pago::TYPES,
            'accounts' => $this->ownAccountsQuery($companyId)
                ->orderBy('entidad.razon_social')
                ->orderBy('cuenta.estado')
                ->orderBy('cuenta.alias')
                ->get(),
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

    public function paymentsCsv(Request $request): StreamedResponse
    {
        $payload = $this->payload($request, 'pagos');
        $validated = $payload['validated'];
        $rows = $payload['data']['rows'];
        $filename = 'pagos-'.$validated['desde'].'-'.$validated['hasta'].'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new RuntimeException('No se pudo preparar el archivo CSV.');
            }

            try {
                fwrite($output, "\xEF\xBB\xBFsep=;\r\n");
                $this->writeCsvRow($output, [
                    'Fecha y hora',
                    'Código',
                    'Contraparte',
                    'Tipo',
                    'Método',
                    'Detalle',
                    'Responsable',
                    'Flujo',
                    'Monto',
                ]);

                foreach ($rows as $row) {
                    $this->writeCsvRow($output, [
                        $row['date']->format('d/m/Y H:i:s'),
                        $this->csvText($row['code']),
                        $this->csvText($row['counterparty']),
                        $this->csvText($row['type']),
                        $this->csvText($row['method']),
                        $this->csvText($row['detail']),
                        $this->csvText($row['user']),
                        $this->csvText($row['flow']),
                        number_format((float) $row['amount'], 2, ',', ''),
                    ]);
                }
            } finally {
                fclose($output);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
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
        $companyId = (int) $request->user()->empresa_id;
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
            $rules['cuenta_id'] = ['nullable', 'integer'];
            $rules['usuario_id'] = ['nullable', 'integer'];
        }
        if ($type === 'responsable') {
            $rules['usuario_id'] = ['required', 'integer'];
            $rules['cuenta_id'] = ['nullable', 'integer'];
        }
        $validated = $request->validate($rules);
        $selectedAccount = null;
        if (isset($validated['cuenta_id'])) {
            $selectedAccount = $this->ownAccountsQuery($companyId)
                ->where('cuenta.id', (int) $validated['cuenta_id'])
                ->first();

            if (! $selectedAccount) {
                throw ValidationException::withMessages([
                    'cuenta_id' => 'La cuenta seleccionada no pertenece a una empresa propia.',
                ]);
            }
        }
        $selectedUser = null;
        if (isset($validated['usuario_id'])) {
            $selectedUser = DB::table('usuarios')
                ->where('empresa_id', $companyId)
                ->where('id', (int) $validated['usuario_id'])
                ->first(['id', 'nombre', 'estado']);

            if (! $selectedUser) {
                throw ValidationException::withMessages([
                    'usuario_id' => 'El usuario seleccionado no pertenece a esta empresa.',
                ]);
            }
        }
        $company = Empresa::query()->findOrFail($companyId);
        $data = match ($type) {
            'ventas-clientes' => $this->reports->salesByCustomer($companyId, $validated['desde'], $validated['hasta']),
            'estado-cliente' => $this->reports->customerStatement($companyId, (int) $validated['cliente_id'], $validated['desde'], $validated['hasta']),
            'estado-proveedor' => $this->reports->providerStatement($companyId, (int) $validated['proveedor_id'], $validated['desde'], $validated['hasta']),
            'pagos' => $this->reports->payments($companyId, $validated['desde'], $validated['hasta'], $validated),
            'responsable' => $this->reports->responsibleMovements(
                $companyId,
                (int) $validated['usuario_id'],
                $validated['desde'],
                $validated['hasta'],
                isset($validated['cuenta_id']) ? (int) $validated['cuenta_id'] : null,
            ),
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
            'selectedAccount' => $selectedAccount,
            'selectedUser' => $selectedUser,
            'generatedAt' => now($company->zona_horaria ?: config('app.timezone')),
            'validated' => $validated,
        ];
    }

    private function ownAccountsQuery(int $companyId): Builder
    {
        return DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.tipo', EntidadFinanciera::TYPE_OWN)
            ->select([
                'cuenta.id',
                'cuenta.entidad_financiera_id',
                'cuenta.alias',
                'cuenta.tipo',
                'cuenta.moneda',
                'cuenta.estado as cuenta_estado',
                'entidad.razon_social as entidad_razon_social',
                'entidad.nombre_comercial as entidad_nombre_comercial',
                'entidad.estado as entidad_estado',
            ]);
    }

    /**
     * @param  resource  $output
     * @param  list<string>  $values
     */
    private function writeCsvRow(mixed $output, array $values): void
    {
        if (fputcsv($output, $values, ';', '"', '', "\r\n") === false) {
            throw new RuntimeException('No se pudo escribir el archivo CSV.');
        }
    }

    private function csvText(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^\s*[=+\-@]/u', $text) === 1 ? "'".$text : $text;
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

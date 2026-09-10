<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ReceptionSyncToken;
use App\Models\Sucursal;
use App\Services\ReceptionSyncTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReceptionSyncTokenController extends Controller
{
    public function __construct(private readonly ReceptionSyncTokenService $tokens) {}

    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function documentation(Request $request): BinaryFileResponse
    {
        return $this->downloadDocumentation($request, 'recepcion-pollo-vivo-sync.md', 'text/markdown; charset=UTF-8');
    }

    public function openapi(Request $request): BinaryFileResponse
    {
        return $this->downloadDocumentation($request, 'openapi-recepcion-pollo-vivo.json', 'application/json');
    }

    public function store(Request $request): Response
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'device_name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'uuid'],
            'expires_in_days' => ['required', 'integer', 'between:1,365'],
        ]);
        $branch = $this->branches($request)->whereKey($data['sucursal_id'])->firstOrFail();
        $issued = $this->tokens->issue(
            $request->user(), $branch, $data['device_name'],
            $data['device_id'] ?? null, (int) $data['expires_in_days'],
        );

        // The plaintext is rendered only in this response, never flashed to a session or stored.
        return $this->page($request, $issued['plain_text_token'], $issued['token']);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $record = $this->visibleTokens($request)->whereKey($token)->firstOrFail();
        $this->tokens->revoke($record);

        return redirect()->route('reception-sync-tokens.index')->with('status', 'Token revocado. El dispositivo ya no puede sincronizar.');
    }

    private function page(Request $request, ?string $plainTextToken = null, ?ReceptionSyncToken $issuedToken = null): Response
    {
        $this->authorizeAdministrator($request);

        return response()->view('reception-sync-tokens', [
            'branches' => $this->branches($request)->orderBy('nombre')->get(),
            'tokens' => $this->visibleTokens($request)->with(['sucursal', 'user'])->latest('id')->paginate(30),
            'plainTextToken' => $plainTextToken,
            'issuedToken' => $issuedToken,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isAdministrator(), 403, 'Solo un administrador puede gestionar dispositivos.');
        abort_unless($request->user()?->empresa?->estado === 'ACTIVO', 403, 'La empresa está inactiva.');
    }

    private function downloadDocumentation(Request $request, string $filename, string $contentType): BinaryFileResponse
    {
        $this->authorizeAdministrator($request);
        $path = base_path('docs/'.$filename);
        abort_unless(is_file($path), 404, 'La documentación no está disponible.');

        return response()->download($path, $filename, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function branches(Request $request): Builder
    {
        return Sucursal::query()->where('empresa_id', $request->user()->empresa_id)
            ->where('estado', 'ACTIVO')
            ->when($request->user()->sucursal_id !== null,
                fn (Builder $query) => $query->whereKey($request->user()->sucursal_id));
    }

    private function visibleTokens(Request $request): Builder
    {
        return ReceptionSyncToken::query()->where('empresa_id', $request->user()->empresa_id)
            ->when($request->user()->sucursal_id !== null,
                fn (Builder $query) => $query->where('sucursal_id', $request->user()->sucursal_id));
    }
}

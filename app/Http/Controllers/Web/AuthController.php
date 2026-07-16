<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route($this->destinationFor(Auth::user()));
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();
        $login = Str::lower(trim((string) ($credentials['login'] ?? $credentials['email'] ?? '')));
        $column = Str::contains($login, '@') ? 'email' : 'nombre';

        $matchingUsers = User::query()
            ->whereRaw("LOWER({$column}) = ?", [$login])
            ->get()
            ->filter(fn (User $candidate): bool => Hash::check(
                (string) $credentials['password'],
                (string) $candidate->password_hash,
            ));

        /** @var User|null $user */
        $user = $matchingUsers->count() === 1 ? $matchingUsers->first() : null;

        if (! $user) {
            return back()
                ->withErrors(['login' => 'Las credenciales son incorrectas.'])
                ->onlyInput('login', 'email');
        }

        if (! $user->isActive()) {
            return back()
                ->withErrors(['login' => 'El usuario está inactivo.'])
                ->onlyInput('login', 'email');
        }

        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'ultimo_acceso_at' => now(),
            'ultimo_acceso_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route($this->destinationFor($user)));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function destinationFor(?User $user): string
    {
        if ($user?->debe_cambiar_password) {
            return 'account';
        }

        return 'menu';
    }
}

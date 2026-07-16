<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccessSessionService
{
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    public function revokeOthers(
        User $user,
        ?int $currentTokenId = null,
        ?string $currentSessionId = null
    ): void {
        $tokens = $user->tokens();

        if ($currentTokenId !== null) {
            $tokens->whereKeyNot($currentTokenId);
        }

        $tokens->delete();

        if (! Schema::hasTable('sessions')) {
            return;
        }

        $sessions = DB::table('sessions')->where('user_id', $user->id);

        if ($currentSessionId !== null && $currentSessionId !== '') {
            $sessions->where('id', '!=', $currentSessionId);
        }

        $sessions->delete();
    }
}

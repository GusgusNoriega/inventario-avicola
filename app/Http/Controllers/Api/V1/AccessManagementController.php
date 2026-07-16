<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessModuleRegistry;
use Illuminate\Http\Request;

abstract class AccessManagementController extends Controller
{
    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless(
            $actor instanceof User
                && $actor->isActive()
                && ($actor->hasPermission(AccessModuleRegistry::MANAGEMENT_MODULE_CODE)
                    || $actor->hasPermission('USUARIOS_GESTIONAR')),
            403,
            'No tienes acceso a la administracion de usuarios y roles.'
        );

        return $actor;
    }
}

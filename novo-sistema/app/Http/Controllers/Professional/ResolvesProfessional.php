<?php

namespace App\Http\Controllers\Professional;

use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\Request;

/**
 * Quem esta usando a area do profissional e a ficha dele. A rota ja exige
 * professional_area.access e a ficha ligada (professional.profile): aqui so
 * se le, sempre do usuario logado (nunca de um id vindo da URL).
 */
trait ResolvesProfessional
{
    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }

    protected function professional(Request $request): Professional
    {
        $pro = $this->user($request)->professional;
        abort_if($pro === null, 403);

        return $pro;
    }
}

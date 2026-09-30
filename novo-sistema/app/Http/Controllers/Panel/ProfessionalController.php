<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Modules\Team\Models\Professional;
use Illuminate\View\View;

/**
 * Ficha do profissional (so leitura; a gestao completa e da Fase 4). A rota
 * passou pela ProfessionalPolicy@view: profissional so abre a propria ficha;
 * trocar o id na URL responde 404.
 */
class ProfessionalController extends Controller
{
    public function show(Professional $professional): View
    {
        $professional->load('user');

        return view('panel.professionals.show', ['professional' => $professional]);
    }
}

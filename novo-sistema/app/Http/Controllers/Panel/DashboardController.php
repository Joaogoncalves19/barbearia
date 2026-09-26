<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Painel real (autenticado). Nesta fase so confirma que autenticacao,
 * autorizacao e layout funcionam; os modulos entram a partir da Fase 3.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('panel.home');
    }
}

<?php

namespace App\Http\Controllers\Panel\Communication;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Lembretes, pedido de avaliacao e ritmo das campanhas (D-49, D-51).
 * So communications.settings; cada mudanca vai para a auditoria. Nada de
 * credencial aqui: o provedor de e-mail e configurado no ambiente.
 */
class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('panel.communication.settings', ['settings' => CommunicationSettings::current()->toArray(), 'fields' => CommunicationSettings::FIELDS]);
    }

    public function update(Request $request): RedirectResponse
    {
        $regras = [];
        $valores = [];
        foreach (CommunicationSettings::FIELDS as $campo => $def) {
            if (is_bool($def['default'])) {
                $valores[$campo] = $request->boolean($campo);
            } else {
                $regras[$campo] = ['required', 'integer', 'between:'.$def['min'].','.$def['max']];
            }
        }
        $dados = $request->validate($regras, [], array_map(fn ($d) => mb_strtolower($d['label']), CommunicationSettings::FIELDS));

        try {
            /** @var User $user */
            $user = $request->user();
            CommunicationSettings::save([...$dados, ...$valores], $user);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['settings' => $e->getMessage()]);
        }

        return redirect()->route('panel.communication.settings')->with('status', 'Configuração salva. Vale para os próximos lembretes e pedidos.');
    }
}

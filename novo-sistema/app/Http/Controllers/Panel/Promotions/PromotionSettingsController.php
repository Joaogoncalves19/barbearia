<?php

namespace App\Http\Controllers\Panel\Promotions;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Shared\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Regras de fidelidade, aniversario e indicacao (PromotionPolicy). Mudar
 * vale para o que acontecer depois; fica na auditoria.
 * Autorizacao na rota: promotions.configure.
 */
class PromotionSettingsController extends Controller
{
    /** Campos digitados como dinheiro/percentual e convertidos para centavos/pontos-base. */
    private const DECIMAIS = ['loyalty_cents_per_point', 'loyalty_reward_percent_bp', 'loyalty_reward_fixed_cents', 'birthday_percent_bp', 'referral_percent_bp'];

    public function edit(): View
    {
        return view('panel.promotions.settings', ['policy' => PromotionPolicy::current()->toArray()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'loyalty_enabled' => ['nullable', 'boolean'],
            'loyalty_earn_mode' => ['required', 'in:visit,value'],
            'loyalty_points_per_visit' => ['required', 'integer'],
            'loyalty_cents_per_point' => ['required', 'string', 'max:20'],
            'loyalty_points_required' => ['required', 'integer'],
            'loyalty_reward_type' => ['required', 'in:percent,fixed,free_service'],
            'loyalty_reward_base' => ['required', 'in:cheapest,most_expensive,total'],
            'loyalty_reward_percent_bp' => ['required', 'string', 'max:20'],
            'loyalty_reward_fixed_cents' => ['required', 'string', 'max:20'],
            'birthday_enabled' => ['nullable', 'boolean'],
            'birthday_percent_bp' => ['required', 'string', 'max:20'],
            'referral_enabled' => ['nullable', 'boolean'],
            'referral_percent_bp' => ['required', 'string', 'max:20'],
            'referral_bonus_points' => ['required', 'integer'],
        ]);

        foreach (self::DECIMAIS as $campo) {
            try {
                $dados[$campo] = Decimal::toScaledInt((string) $dados[$campo], 2)['value'];
            } catch (InvalidArgumentException) {
                return back()->withInput()->withErrors([$campo => 'Valor inválido.']);
            }
        }
        foreach (['loyalty_enabled', 'birthday_enabled', 'referral_enabled'] as $campo) {
            $dados[$campo] = $request->boolean($campo);
        }

        try {
            PromotionPolicy::save($dados, $this->user($request));
        } catch (InvalidArgumentException) {
            return back()->withInput()->withErrors(['policy' => 'Algum valor está fora do limite. Confira percentuais (0,01% a 100%), pontos e valores.']);
        }

        return back()->with('status', 'Regras salvas. Valem para o que acontecer daqui em diante.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

<?php

namespace App\Http\Controllers\Panel\Promotions;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Cupons (promocoes.md §3). Cadastro e situacao; usar o cupom passa pelo
 * motor de promocoes. Alterar um cupom nao muda agendamentos que ja o usam
 * (eles guardam a propria regra). Auditado pelo model (Auditable).
 * Autorizacao na rota: coupons.view / coupons.manage.
 */
class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $filtro = (string) $request->query('situacao', 'ativos');

        return view('panel.promotions.coupons.index', [
            'coupons' => Coupon::query()
                ->when($filtro === 'ativos', fn ($q) => $q->where('is_active', true))
                ->when($filtro === 'inativos', fn ($q) => $q->where('is_active', false))
                ->withCount(['redemptions as redeemed_count' => fn ($q) => $q->where('status', RedemptionStatus::Redeemed)])
                ->withCount(['redemptions as reserved_count' => fn ($q) => $q->where('status', RedemptionStatus::Reserved)])
                ->orderBy('code')->get(),
            'filter' => $filtro,
        ]);
    }

    public function create(): View
    {
        return view('panel.promotions.coupons.form', ['coupon' => new Coupon(['is_active' => true, 'discount_type' => DiscountType::Percent])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validated($request, null);
        if ($dados instanceof RedirectResponse) {
            return $dados;
        }

        try {
            $c = Coupon::query()->create([...$dados, 'is_active' => true, 'created_by_user_id' => $this->user($request)->id]);
        } catch (DomainRuleViolation $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('panel.coupons.index')->with('status', "Cupom {$c->code} criado.");
    }

    public function edit(Coupon $coupon): View
    {
        return view('panel.promotions.coupons.form', ['coupon' => $coupon]);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $dados = $this->validated($request, $coupon);
        if ($dados instanceof RedirectResponse) {
            return $dados;
        }

        try {
            $coupon->update($dados);
        } catch (DomainRuleViolation $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('panel.coupons.index')->with('status', "Cupom {$coupon->code} atualizado. Agendamentos que já o usam mantêm o desconto combinado.");
    }

    public function setStatus(Request $request, Coupon $coupon): RedirectResponse
    {
        $ativo = $request->boolean('active');
        $coupon->update(['is_active' => $ativo]);

        return back()->with('status', "Cupom {$coupon->code} ".($ativo ? 'ativado.' : 'desativado. Reservas já feitas continuam valendo.'));
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    private function validated(Request $request, ?Coupon $coupon): array|RedirectResponse
    {
        $dados = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'string', 'max:20'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ], ['code.regex' => 'Use letras, números, hífen ou sublinhado.'],
            ['code' => 'código', 'description' => 'descrição', 'discount_type' => 'tipo', 'value' => 'valor', 'max_uses' => 'limite de usos', 'expires_on' => 'validade']);

        try {
            $valor = Decimal::toScaledInt($dados['value'], 2)['value'];
        } catch (InvalidArgumentException) {
            return back()->withInput()->withErrors(['value' => 'Informe o percentual (ex.: 10) ou o valor (ex.: 15,00).']);
        }
        $percentual = $dados['discount_type'] === 'percent';
        if ($valor < 1 || ($percentual && $valor > 10000)) {
            return back()->withInput()->withErrors(['value' => 'Percentual entre 0,01% e 100%, ou valor maior que zero.']);
        }

        return [
            'code' => Coupon::normalizeCode($dados['code']),
            'description' => ($dados['description'] ?? null) ?: null,
            'discount_type' => $dados['discount_type'],
            'percent_bp' => $percentual ? $valor : null,
            'amount_cents' => $percentual ? null : $valor,
            'max_uses' => $dados['max_uses'] ?? null,
            'expires_on' => $dados['expires_on'] ?? null,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

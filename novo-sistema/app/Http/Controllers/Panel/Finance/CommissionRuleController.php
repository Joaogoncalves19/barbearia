<?php

namespace App\Http\Controllers\Panel\Finance;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Decimal;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Regras de comissao (comissoes.md §2) e historico (regras, correcoes,
 * estornos de repasse). Gravar passa pelo CommissionRules.
 * Autorizacao na rota: commissions.configure / commissions.history.
 */
class CommissionRuleController extends Controller
{
    public function __construct(private readonly CommissionRules $rules) {}

    public function index(): View
    {
        return view('panel.finance.rules.index', [
            'current' => CommissionRule::query()->whereNotNull('current_scope')->with(['professional', 'service', 'createdBy'])
                ->orderBy('target')->orderByRaw('professional_id IS NOT NULL')->orderBy('professional_id')->orderByRaw('service_id IS NOT NULL')->orderBy('service_id')->get(),
            'professionals' => Professional::query()->where('is_active', true)->ordered()->get(),
            'services' => Service::query()->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'target' => ['required', Rule::in(['service', 'product'])],
            'professional_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in(['percent', 'fixed', 'none'])],
            'value' => ['nullable', 'required_unless:type,none', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['value.required_unless' => 'Informe o percentual ou o valor.'],
            ['target' => 'sobre', 'professional_id' => 'profissional', 'service_id' => 'serviço', 'type' => 'tipo', 'value' => 'valor', 'reason' => 'motivo']);

        $pro = ! empty($dados['professional_id']) ? Professional::query()->findOrFail($dados['professional_id']) : null;
        $srv = ! empty($dados['service_id']) && $dados['target'] === 'service' ? Service::query()->findOrFail($dados['service_id']) : null;
        $tipo = CommissionRuleType::from($dados['type']);

        $valor = null;
        if ($tipo !== CommissionRuleType::None) {
            try {
                $valor = Decimal::toScaledInt((string) $dados['value'], 2)['value'];
            } catch (InvalidArgumentException) {
                return back()->withInput()->withErrors(['value' => CommissionRuleViolation::MESSAGES['invalid_rule']]);
            }
        }

        try {
            $nova = $this->rules->set(CommissionTarget::from($dados['target']), $pro, $srv, $tipo,
                $tipo === CommissionRuleType::Percent ? $valor : null,
                $tipo === CommissionRuleType::Fixed ? $valor : null,
                $dados['reason'] ?? null, $this->user($request));
        } catch (CommissionRuleViolation $e) {
            return back()->withInput()->withErrors(['rule' => $e->getMessage()]);
        }

        return back()->with('status', 'Regra definida: '.$nova->scopeLabel().' = '.$nova->describe().'. Vale para atendimentos concluídos a partir de agora; o que já foi calculado não muda.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);
        $regra = CommissionRule::query()->whereNotNull('current_scope')->findOrFail($dados['rule_id']);

        try {
            $this->rules->clear($regra->target, $regra->professional, $regra->service, $this->user($request));
        } catch (CommissionRuleViolation $e) {
            return back()->withErrors(['rule' => $e->getMessage()]);
        }

        return back()->with('status', 'Regra encerrada: '.$regra->scopeLabel().'. Passa a valer a regra mais geral.');
    }

    public function history(): View
    {
        return view('panel.finance.rules.history', [
            'rules' => CommissionRule::query()->with(['professional', 'service', 'createdBy'])->latest('id')->limit(200)->get(),
            'adjustments' => CommissionEntry::query()->where('kind', LedgerEntryKind::Adjustment)->with(['professional', 'attendance', 'createdBy'])->latest('id')->limit(100)->get()
                ->concat(TipEntry::query()->where('kind', LedgerEntryKind::Adjustment)->with(['professional', 'attendance', 'createdBy'])->latest('id')->limit(100)->get())
                ->sortByDesc('occurred_at')->values(),
            'reversals' => CommissionPayout::query()->whereNotNull('reversed_at')->with(['professional', 'reversedBy'])->latest('reversed_at')->limit(50)->get(),
            'fmt' => fn (int $c) => Money::fromCents($c)->format(),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

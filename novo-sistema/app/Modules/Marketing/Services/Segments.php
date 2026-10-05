<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;
use Illuminate\Database\Eloquent\Builder;

/**
 * Publicos das campanhas (campanhas.md §2; os do sistema antigo). SEMPRE
 * restritos a quem pode receber marketing: cliente ativo, nao anonimizado,
 * com e-mail, consentimento CONCEDIDO ("desconhecido" nao entra; nada e
 * presumido) e fora da lista de supressao (descadastro, devolucao,
 * reclamacao).
 */
final class Segments
{
    public const ALL = [
        'todos' => 'Todos que aceitaram receber novidades',
        'ativos' => 'Atendidos nos últimos 90 dias',
        'sem_retorno' => 'Sem atendimento há mais de N dias',
        'novos' => 'Nunca atendidos',
        'aniversariantes' => 'Aniversariantes do mês',
        'assinantes' => 'Assinantes (com benefício hoje)',
        'profissional' => 'Já atendidos por um profissional',
    ];

    /**
     * @param  array<string, int|string|null>  $params
     * @return Builder<Customer>
     */
    public function query(string $segment, array $params = []): Builder
    {
        $q = Customer::query()
            ->where('status', CustomerStatus::Active->value)
            ->whereNull('anonymized_at')
            ->whereNotNull('email')
            ->where('marketing_email_consent', MarketingConsent::Granted->value)
            ->whereNotExists(fn ($s) => $s->from('email_suppressions')->whereColumn('email_suppressions.email', 'customers.email'));
        $concluidos = fn ($s) => $s->from('attendances')->whereColumn('attendances.customer_id', 'customers.id')->where('attendances.status', AttendanceStatus::Completed->value);

        return match ($segment) {
            'ativos' => $q->whereExists(fn ($s) => $concluidos($s)->where('attendances.completed_at', '>=', BusinessTime::now()->subDays(90))),
            'sem_retorno' => $q->whereExists($concluidos)
                ->whereNotExists(fn ($s) => $concluidos($s)->where('attendances.completed_at', '>=', BusinessTime::now()->subDays(max(1, (int) ($params['dias'] ?? 60))))),
            'novos' => $q->whereNotExists($concluidos),
            'aniversariantes' => $q->whereNotNull('birth_date')->whereRaw(
                $q->getConnection()->getDriverName() === 'sqlite' ? "CAST(strftime('%m', birth_date) AS INTEGER) = ?" : 'MONTH(birth_date) = ?',
                [(int) BusinessTime::local(BusinessTime::now())->format('n')],
            ),
            'assinantes' => $q->whereIn('id', $this->subscriberIds()),
            'profissional' => $q->whereExists(fn ($s) => $concluidos($s)->where('attendances.professional_id', (int) ($params['professional_id'] ?? 0))),
            default => $q,
        };
    }

    /**
     * @return list<int>
     */
    private function subscriberIds(): array
    {
        $b = app(SubscriptionBenefits::class);
        $hoje = BusinessTime::today();

        return \App\Modules\Subscriptions\Models\Subscription::query()->whereNotNull('ends_on')->where('ends_on', '>=', $hoje)->get()
            ->filter(fn ($s) => $b->validOn($s, $hoje))->pluck('customer_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}

@php
    use App\Modules\Customers\Enums\CustomerStatus;
    use App\Modules\Customers\Support\Cpf;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $staff = auth('web')->user();
    $removido = $customer->anonymized_at !== null;
    $corAgendamento = fn ($s) => match ($s->value) { 'pending' => 'warning', 'confirmed' => 'info', 'completed' => 'success', 'cancelled', 'no_show' => 'danger', default => 'neutral' };
    $corAtendimento = fn ($s) => match ($s->value) { 'open' => 'warning', 'in_progress' => 'info', 'completed' => 'success', default => 'neutral' };
    $servicos = fn ($a) => $a->items->pluck('name')->filter()->implode(', ') ?: '—';
@endphp
<x-layouts.staff :title="$customer->name">
    <header class="page-head">
        {{-- Envolvido: ".page-head > .cluster" e o lugar das acoes (a direita). --}}
        <div>
            <div class="cluster">
                <x-ui.avatar :name="$customer->name" size="lg" />
                <div class="stack stack-sm">
                    <a class="link-arrow text-sm" href="{{ route('panel.customers.index') }}">Voltar para clientes</a>
                    <h1 class="page-head__title">{{ $customer->name }}</h1>
                    <p class="text-muted">Cliente desde {{ $customer->created_at ? BusinessTime::formatLocal($customer->created_at, 'd/m/Y') : '—' }}</p>
                </div>
            </div>
        </div>
        <div class="cluster">
            @can('update', $customer)
                <x-ui.button :href="route('panel.customers.edit', $customer->public_id)" variant="secondary" icon="pencil">Editar</x-ui.button>
            @endcan
            @if (! $removido && $staff->can('loyalty.view'))
                <x-ui.button :href="route('panel.loyalty.customer', $customer->public_id)" variant="secondary" icon="star">Pontos</x-ui.button>
            @endif
        </div>
    </header>

    @if ($removido)
        <x-ui.alert>Cadastro anonimizado em {{ BusinessTime::formatLocal($customer->anonymized_at, 'd/m/Y') }} (LGPD). Os dados pessoais foram apagados; o histórico da agenda e do caixa continua, sem o nome.</x-ui.alert>
    @elseif ($mergedInto)
        <x-ui.alert>Este cadastro foi unido a <a href="{{ route('panel.customers.show', $mergedInto->public_id) }}">{{ $mergedInto->name }}</a>. O histórico atual fica no outro cadastro.</x-ui.alert>
    @endif

    <div class="dashboard-grid">
        <x-ui.card title="Contato e cadastro">
            <dl class="summary-list">
                <div><dt>Celular</dt><dd data-customer-phone>{{ $customer->phone ?? '—' }}</dd></div>
                <div><dt>E-mail</dt><dd>{{ $customer->email ?? '—' }}@if ($customer->email && $customer->email_verified_at === null) <x-ui.badge variant="warning">não confirmado</x-ui.badge>@endif</dd></div>
                <div><dt>CPF</dt><dd data-cpf>
                    @if ($customer->cpf === null)
                        {{ $removido ? '—' : 'Não informado (pedido no próximo acesso do cliente)' }}
                    @else
                        {{ $canSeeCpf ? substr($customer->cpf, 0, 3).'.'.substr($customer->cpf, 3, 3).'.'.substr($customer->cpf, 6, 3).'-'.substr($customer->cpf, 9, 2) : Cpf::mask($customer->cpf) }}
                    @endif
                </dd></div>
                <div><dt>Nascimento</dt><dd>{{ $customer->birth_date?->format('d/m/Y') ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Situação da conta">
            <dl class="summary-list">
                <div><dt>Cadastro</dt><dd data-customer-status>{{ $removido ? 'Anonimizado' : $customer->status->label() }}</dd></div>
                <div><dt>Acesso pelo site</dt><dd>
                    @if (! $customer->canSignIn())
                        Sem acesso
                    @elseif ($customer->email === null)
                        Sem e-mail (só atendimento no balcão)
                    @else
                        {{ $customer->hasPassword() ? 'Senha ou link por e-mail' : 'Link por e-mail' }}
                    @endif
                </dd></div>
                <div><dt>Último acesso</dt><dd>{{ $customer->last_login_at ? BusinessTime::formatLocal($customer->last_login_at) : 'Nunca' }}</dd></div>
                <div><dt>Lembretes por e-mail</dt><dd>{{ $customer->email_reminders_enabled ? 'Sim' : 'Não' }}</dd></div>
                <div><dt>Novidades e promoções</dt><dd>{{ $customer->marketing_email_consent->label() }}</dd></div>
            </dl>
            @can('update', $customer)
                <x-slot:actions>
                    @if ($customer->status === \App\Modules\Customers\Enums\CustomerStatus::Active)
                        <x-ui.button variant="secondary" size="sm" icon="circle-x" data-dialog-open="desativar-cliente">Desativar</x-ui.button>
                    @else
                        <form method="POST" action="{{ route('panel.customers.status', $customer->public_id) }}">
                            @csrf
                            <input type="hidden" name="active" value="1">
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="circle-check">Reativar</x-ui.button>
                        </form>
                    @endif
                </x-slot:actions>
            @endcan
        </x-ui.card>
    </div>

    @can('update', $customer)
        @if ($customer->status === \App\Modules\Customers\Enums\CustomerStatus::Active)
            <x-ui.confirm id="desativar-cliente" title="Desativar o cadastro?" :action="route('panel.customers.status', $customer->public_id)" :fields="['active' => 0]" confirm-label="Desativar cadastro">
                <p>O cliente deixa de entrar no site e sai da busca do balcão. Histórico, pontos e horários já marcados continuam. Dá para reativar depois.</p>
            </x-ui.confirm>
        @endif
    @endcan

    @unless ($removido)
        <div class="dashboard-grid">
            <x-ui.card title="Para o atendimento">
                <div class="stack stack-sm">
                    <h3 class="text-sm">Próximos horários</h3>
                    @if ($upcoming->isEmpty())
                        <p class="text-sm text-muted">Nenhum horário marcado.</p>
                    @else
                        <ul class="stack stack-sm text-sm" data-upcoming>
                            @foreach ($upcoming as $a)
                                <li>
                                    @can('view', $a)<a href="{{ route('panel.appointments.show', $a->code) }}">{{ BusinessTime::formatLocal($a->starts_at, 'd/m H:i') }}</a>@else {{ BusinessTime::formatLocal($a->starts_at, 'd/m H:i') }} @endcan
                                    · {{ $servicos($a) }} · {{ $a->professional_name ?? '—' }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($customer->favoriteProfessionals->isNotEmpty())
                        <h3 class="text-sm">Profissionais favoritos</h3>
                        <p class="text-sm">{{ $customer->favoriteProfessionals->pluck('display_name')->implode(', ') }}</p>
                    @endif

                    <h3 class="text-sm">Anotações</h3>
                    @if ($notes->isEmpty())
                        <p class="text-sm text-muted">Nenhuma anotação. Os profissionais registram preferências e cuidados pela área deles.</p>
                    @else
                        <ul class="stack stack-sm text-sm" data-customer-notes>
                            @foreach ($notes as $n)
                                <li>{{ $n->body }} <span class="text-muted">· {{ $n->author_label ?? 'Equipe' }}, {{ $n->created_at ? BusinessTime::formatLocal($n->created_at, 'd/m/Y') : '' }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Benefícios">
                <dl class="summary-list">
                    <div><dt>Assinatura</dt><dd data-customer-subscription>
                        @if ($subscription)
                            @if ($staff->can('subscriptions.view'))
                                <a href="{{ route('panel.subscriptions.show', $subscription) }}">{{ $subscription->planName() }}</a>
                            @else
                                {{ $subscription->planName() }}
                            @endif
                            · {{ $subscription->status?->label() }}
                        @else
                            Nenhuma vigente
                        @endif
                    </dd></div>
                    @if ($points)
                        <div><dt>Pontos</dt><dd data-customer-points>{{ $points['balance'] }} (disponíveis: {{ $points['available'] }})</dd></div>
                    @endif
                    <div><dt>Valendo hoje</dt><dd data-customer-entitlements>{{ collect($entitlements)->map(fn ($d) => $d['kind']->label())->implode(', ') ?: 'Nenhum' }}</dd></div>
                    <div><dt>Código de indicação</dt><dd>{{ $customer->referral_code ?? '—' }}</dd></div>
                </dl>
            </x-ui.card>
        </div>
    @endunless

    <x-ui.card title="Agendamentos">
        @if ($appointments->isEmpty())
            <x-ui.empty-state compact icon="calendar-days" title="Nenhum agendamento." />
        @else
            <x-ui.table caption="Agendamentos do cliente" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Serviços</th><th scope="col">Profissional</th><th scope="col">Situação</th><th scope="col">Total</th></tr></thead>
                <tbody>
                    @foreach ($appointments as $a)
                        <tr data-appointment-row>
                            <td data-label="Quando" class="numeric">
                                @can('view', $a)<a href="{{ route('panel.appointments.show', $a->code) }}">{{ $a->starts_at ? BusinessTime::formatLocal($a->starts_at) : '—' }}</a>@else {{ $a->starts_at ? BusinessTime::formatLocal($a->starts_at) : '—' }} @endcan
                            </td>
                            <td data-label="Serviços">{{ $servicos($a) }}</td>
                            <td data-label="Profissional">{{ $a->professional_name ?? '—' }}</td>
                            <td data-label="Situação"><x-ui.badge :variant="$corAgendamento($a->status)">{{ $a->status->label() }}</x-ui.badge></td>
                            <td data-label="Total" class="numeric">{{ $a->total_cents !== null ? Money::fromCents($a->total_cents)->format() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            {{ $appointments->links() }}
        @endif
    </x-ui.card>

    <x-ui.card title="Atendimentos">
        @if ($attendances->isEmpty())
            <x-ui.empty-state compact icon="receipt" title="Nenhum atendimento." />
        @else
            <x-ui.table caption="Atendimentos do cliente" caption-hidden stacked>
                <thead><tr><th scope="col">Código</th><th scope="col">Quando</th><th scope="col">Profissional</th><th scope="col">Situação</th><th scope="col">Total</th></tr></thead>
                <tbody>
                    @foreach ($attendances as $at)
                        <tr data-attendance-row>
                            <td data-label="Código">@can('view', $at)<a href="{{ route('panel.attendances.show', $at) }}">{{ $at->code }}</a>@else {{ $at->code }} @endcan</td>
                            <td data-label="Quando" class="numeric">{{ BusinessTime::formatLocal($at->completed_at ?? $at->opened_at) }}</td>
                            <td data-label="Profissional">{{ $at->professional_name ?? '—' }}</td>
                            <td data-label="Situação"><x-ui.badge :variant="$corAtendimento($at->status)">{{ $at->status->label() }}</x-ui.badge></td>
                            <td data-label="Total" class="numeric">{{ $at->total_cents !== null ? Money::fromCents($at->total_cents)->format() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            {{ $attendances->links() }}
        @endif
    </x-ui.card>

    @can('anonymize', $customer)
        <x-ui.card title="Dados pessoais (LGPD)">
            <div class="stack stack-sm">
                <p class="text-sm">A pedido do cliente, a barbearia pode anonimizar o cadastro: nome, contatos, CPF e nascimento são apagados; agenda, caixa e comissões continuam, sem o nome. Não dá para desfazer.</p>
                @if ($blockers !== [])
                    <ul class="stack stack-sm text-sm" data-erasure-blockers>
                        @foreach ($blockers as $b)<li>{{ $b }}</li>@endforeach
                    </ul>
                @endif
                <div><x-ui.button :href="route('panel.customers.anonymize.confirm', $customer->public_id)" variant="secondary" icon="circle-x">Anonimizar cadastro</x-ui.button></div>
            </div>
        </x-ui.card>
    @endcan
</x-layouts.staff>

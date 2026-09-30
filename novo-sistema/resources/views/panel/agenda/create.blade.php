@php
    use App\Modules\Scheduling\Support\BusinessTime;
    // id do servico => rotulo. Laco explicito: flatMap/collapse renumeraria as
    // chaves e o value da opcao deixaria de ser o id (bug pego no teste E2E).
    $serviceOptions = [];
    foreach ($groups as $g) {
        foreach ($g['services'] as $s) {
            $serviceOptions[$s->id] = ($g['category']?->name ? $g['category']->name.' · ' : '').$s->name.' ('.$s->durationLabel().', '.$s->price()->format().')';
        }
    }
@endphp
<x-layouts.staff title="Novo agendamento">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.agenda', ['data' => $date]) }}">Voltar para a agenda</a>
            <h1 class="page-head__title">Novo agendamento</h1>
        </div>
    </header>

    @error('slot')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    {{-- 1. Servico e profissional (GET: so muda o que aparece). --}}
    <x-ui.card title="1. Serviço e profissional">
        <form method="GET" action="{{ route('panel.appointments.create') }}" class="stack">
            <input type="hidden" name="data" value="{{ $date }}">
            <x-ui.select name="servico" label="Serviço" :value="$service?->id" placeholder="Escolha o serviço"
                :options="$serviceOptions" />
            @if ($service)
                <x-ui.select name="profissional" label="Profissional" :value="$professional?->id" placeholder="Escolha o profissional"
                    :options="$professionals->pluck('display_name', 'id')->all()" />
                @if ($professionals->isEmpty())
                    <p class="text-sm text-muted">Nenhum profissional que você possa agendar executa este serviço.</p>
                @endif
            @endif
            <div><x-ui.button type="submit" variant="secondary">Continuar</x-ui.button></div>
        </form>
    </x-ui.card>

    @if ($service && $professional)
        <x-ui.card title="2. Dia e horário">
            @include('partials.day-picker', [
                'days' => $days, 'date' => $date,
                'url' => fn ($d) => route('panel.appointments.create', ['servico' => $service->id, 'profissional' => $professional->id, 'data' => $d, 'cliente' => $term]),
            ])
        </x-ui.card>

        <form method="POST" action="{{ route('panel.appointments.store') }}" class="stack" novalidate>
            @csrf
            <input type="hidden" name="service_id" value="{{ $service->id }}">
            <input type="hidden" name="professional_id" value="{{ $professional->id }}">
            <input type="hidden" name="data" value="{{ $date }}">

            <x-ui.card>
                <fieldset class="check-group">
                    <legend>Horários livres de {{ $professional->display_name }} em {{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $date, BusinessTime::zone())->format('d/m') }}</legend>
                    @if ($slots === [])
                        <p class="text-muted">Nenhum horário livre neste dia.</p>
                    @else
                        <div class="slots">
                            @foreach ($slots as $s)
                                @php $hora = BusinessTime::local($s['start'])->format('H:i'); @endphp
                                <label class="slot"><input type="radio" name="hora" value="{{ $hora }}" @checked(old('hora', $selectedTime) === $hora) required> {{ $hora }}</label>
                            @endforeach
                        </div>
                    @endif
                    @error('hora')<p class="field__error">{{ $message }}</p>@enderror
                </fieldset>
            </x-ui.card>

            <x-ui.card title="3. Cliente">
                <div class="stack">
                    @can('customers.view')
                        <div class="stack stack-sm">
                            <x-ui.input name="cliente" label="Buscar cliente cadastrado" :value="$term" hint="Nome, e-mail ou telefone. Depois toque em Buscar." form="busca-cliente" optional />
                            <div><x-ui.button type="submit" variant="secondary" size="sm" icon="search" form="busca-cliente">Buscar</x-ui.button></div>
                        </div>
                        @if ($customers->isNotEmpty())
                            <fieldset class="check-group">
                                <legend>Resultado</legend>
                                <div class="stack stack-sm">
                                    @foreach ($customers as $c)
                                        <x-ui.radio name="customer_id" :value="$c->id" :label="$c->name" :hint="$c->phone ?? $c->email" />
                                    @endforeach
                                </div>
                            </fieldset>
                        @elseif ($term !== '')
                            <p class="text-sm text-muted">Nenhum cliente encontrado. Use os campos abaixo.</p>
                        @endif
                    @endcan
                    <p class="text-sm text-muted">Sem cadastro? Informe o nome (e o telefone) de quem vai ser atendido.</p>
                    <x-ui.input name="contact_name" label="Nome do cliente" optional />
                    <x-ui.input name="contact_phone" label="Telefone" type="tel" optional />
                    <x-ui.textarea name="notes" label="Observações" rows="2" optional />
                </div>
            </x-ui.card>

            @if ($slots !== [])
                <div><x-ui.button type="submit" icon="calendar-plus">Agendar</x-ui.button></div>
            @endif
        </form>

        {{-- Busca de cliente: formulario GET separado (mantem a escolha atual). --}}
        <form id="busca-cliente" method="GET" action="{{ route('panel.appointments.create') }}">
            <input type="hidden" name="servico" value="{{ $service->id }}">
            <input type="hidden" name="profissional" value="{{ $professional->id }}">
            <input type="hidden" name="data" value="{{ $date }}">
        </form>
    @endif
</x-layouts.staff>

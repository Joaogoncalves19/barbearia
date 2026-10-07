{{--
    ATENDIMENTO na area do profissional (Fase 12.5), para usar ao lado da
    cadeira: quem, quanto tempo, o que foi feito, quanto da e a proxima acao.
    Tudo grava pelas rotas do painel (AttendanceService); concluir usa o mesmo
    formulario e a mesma conclusao atomica da comanda (Fase 6).
--}}
@php
    use App\Modules\Checkout\Enums\AttendanceStatus;
    use App\Modules\Scheduling\Enums\ItemType;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Duration;
    use App\Modules\Shared\Support\Money;
    $a = $attendance;
    $fmt = fn (?int $c) => $c !== null ? Money::fromCents($c)->format() : '—';
    $concluido = $a->status === AttendanceStatus::Completed;
    $emAndamento = $a->status === AttendanceStatus::InProgress;
    $total = $concluido ? $a->total_cents : $breakdown?->total?->cents;
    $desde = $a->started_at;
    $decorrido = $desde !== null ? (int) $desde->diffInMinutes($concluido && $a->completed_at ? $a->completed_at : $now) : null;
    $cor = match ($a->status->value) { 'open' => 'warning', 'in_progress' => 'info', 'completed' => 'success', default => 'neutral' };
    $servicos = $a->items->reject(fn ($i) => $i->item_type === ItemType::Product);
    $produtos = $a->items->filter(fn ($i) => $i->item_type === ItemType::Product);
@endphp
<x-layouts.professional :title="'Atendimento · '.$a->customer_name">
    <header class="pro-head">
        <div class="pro-head__text">
            <a class="link-arrow text-sm" href="{{ route('pro.attendances', ['data' => BusinessTime::dateOf($a->opened_at)]) }}"><x-icon name="arrow-left" /> Atendimentos do dia</a>
            <h1 class="pro-head__title">{{ $a->customer_name }}</h1>
            <p class="cluster">
                <x-ui.badge :variant="$cor">{{ $a->status->label() }}</x-ui.badge>
                <span class="text-muted text-sm">{{ $a->source->label() }} · {{ $a->code }}</span>
                @if ($a->appointment !== null)<a class="text-sm" href="{{ route('pro.appointments.show', $a->appointment) }}">Agendamento {{ $a->appointment->code }}</a>@endif
            </p>
        </div>
    </header>

    @include('professional.partials.errors')

    <div class="pro-split pro-split--ticket">
        {{-- A conta e a proxima acao: primeiro no celular, a direita no desktop --}}
        <aside class="pro-ticket" aria-label="Conta e próxima ação">
            <dl class="chair__times">
                <div><dt>Início</dt><dd class="figure">{{ $desde ? BusinessTime::formatLocal($desde, 'H:i') : '—' }}</dd></div>
                <div><dt>Previsto</dt><dd class="figure">{{ $expectedMinutes > 0 ? Duration::format($expectedMinutes) : '—' }}</dd></div>
                <div><dt>{{ $concluido ? 'Duração' : 'Decorrido' }}</dt><dd class="figure">
                    @if ($decorrido === null)—@elseif ($emAndamento)<span x-data="elapsed" data-since="{{ $desde->toIso8601String() }}" x-text="label">{{ Duration::format(max(0, $decorrido)) }}</span>@else{{ Duration::format(max(0, $decorrido)) }}@endif
                </dd></div>
            </dl>
            @if ($emAndamento && $expectedMinutes > 0 && $decorrido !== null && $decorrido > $expectedMinutes)
                <p><x-ui.badge variant="warning">Passou do previsto</x-ui.badge></p>
            @endif

            <div class="pro-ticket__total">
                <p class="pro-ticket__label">{{ $concluido ? 'Total pago' : 'Total a pagar' }}</p>
                <p class="figure figure--lg" data-total>{{ $fmt($total) }}</p>
                <dl class="summary-list">
                    <div><dt>Subtotal</dt><dd class="numeric">{{ $fmt($concluido ? $a->subtotal_cents : $breakdown?->subtotal?->cents) }}</dd></div>
                    @foreach ($a->discounts as $d)
                        <div><dt>Desconto {{ $d->rule()->label() }} <span class="text-sm text-muted">· {{ $d->kind->label() }}</span></dt><dd class="numeric">−{{ $fmt($d->amount_cents) }}</dd></div>
                    @endforeach
                    @if ($concluido)<div><dt>Gorjeta</dt><dd class="numeric" data-tip>{{ $fmt((int) $a->tip_cents) }}</dd></div>@endif
                </dl>
            </div>

            <div class="pro-ticket__actions">
                @if ($a->status === AttendanceStatus::Open)
                    @can('update', $a)
                        <form method="POST" action="{{ route('panel.attendances.start', $a) }}">@csrf<x-ui.button type="submit" icon="play" size="lg" block>Iniciar atendimento</x-ui.button></form>
                    @endcan
                @endif
                @if ($emAndamento)
                    @can('complete', $a)
                        <x-ui.button icon="check" size="lg" block data-dialog-open="concluir-atendimento">Finalizar e receber</x-ui.button>
                    @endcan
                @endif
                @if ($concluido)
                    <x-ui.button :href="route('panel.receipts.attendance', $a)" variant="secondary" icon="receipt" block>Comprovante</x-ui.button>
                @endif
                @if ($a->status->isEditable())
                    @can('cancel', $a)
                        <x-ui.button variant="ghost" icon="x" data-dialog-open="cancelar-atendimento" block>Cancelar atendimento</x-ui.button>
                    @endcan
                @endif
            </div>
            @if ($emAndamento && ! $cashOpen && (int) $total > 0)
                <x-ui.alert variant="warning">Não há caixa aberto. Peça para a recepção abrir o caixa antes de finalizar.</x-ui.alert>
            @endif
        </aside>

        <div class="stack stack-lg">
            <section class="pro-block" aria-labelledby="feito">
                <h2 id="feito" class="pro-section-title"><x-icon name="scissors" /> Serviços e produtos</h2>
                @if ($a->items->isEmpty())
                    <p class="text-sm text-muted">Nenhum item. Inclua ao menos um serviço ou produto.</p>
                @else
                    <ul class="items" role="list">
                        @foreach ($servicos->concat($produtos) as $item)
                            <li class="items__row">
                                <span class="items__name">{{ $item->name }}@if ($item->quantity > 1) <span class="text-muted">× {{ $item->quantity }}</span>@endif
                                    <span class="items__kind">{{ $item->item_type->label() }}@if ($item->duration_minutes) · {{ Duration::format((int) $item->duration_minutes) }}@endif</span></span>
                                <span class="items__price numeric">{{ $fmt($item->total_cents) }}</span>
                                @if ($editable)
                                    <form method="POST" action="{{ route('panel.attendances.items.destroy', [$a, $item]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2"><span class="visually-hidden">Retirar {{ $item->name }}</span></x-ui.button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($editable)
                    <div class="add-grid">
                        @if ($services->isNotEmpty())
                            <form method="POST" action="{{ route('panel.attendances.services.store', $a) }}" class="stack stack-sm" novalidate>
                                @csrf
                                <x-ui.select name="service_id" label="Incluir serviço" :options="$services->mapWithKeys(fn ($s) => [$s->id => $s->name.' — '.$s->price()->format()])->all()" placeholder="Escolha o serviço" />
                                <div><x-ui.button type="submit" variant="secondary" icon="plus">Incluir serviço</x-ui.button></div>
                            </form>
                        @endif
                        @if ($productsForSale->isNotEmpty())
                            <form method="POST" action="{{ route('panel.attendances.products.store', $a) }}" class="stack stack-sm" novalidate>
                                @csrf
                                <x-ui.select name="product_id" label="Vender produto" :options="$productsForSale->mapWithKeys(fn ($p) => [$p->id => $p->name.' — '.Money::fromCents((int) $p->price_cents)->format()])->all()" placeholder="Escolha o produto" />
                                <x-ui.input name="quantity" label="Quantidade" type="number" min="1" max="99" value="1" inputmode="numeric" />
                                <div><x-ui.button type="submit" variant="secondary" icon="plus">Incluir produto</x-ui.button></div>
                            </form>
                        @endif
                    </div>
                    <p class="text-sm text-muted">Serviço do agendamento pelo preço combinado; o que for incluído aqui, pelo preço do catálogo agora.</p>
                @endif
            </section>

            <section class="pro-block" aria-labelledby="material">
                <h2 id="material" class="pro-section-title"><x-icon name="package" /> Material usado</h2>
                @if ($a->consumptions->isEmpty())
                    <p class="text-sm text-muted">Nenhum material registrado.</p>
                @else
                    <ul class="items" role="list">
                        @foreach ($a->consumptions as $c)
                            <li class="items__row">
                                <span class="items__name">{{ $c->quantity }} × {{ $c->product_name }}</span>
                                @if ($editable)
                                    <form method="POST" action="{{ route('panel.attendances.consumptions.destroy', [$a, $c]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2"><span class="visually-hidden">Retirar {{ $c->product_name }}</span></x-ui.button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($editable && $products->isNotEmpty())
                    <details class="hint">
                        <summary><x-icon name="plus" /> Registrar material</summary>
                        <form method="POST" action="{{ route('panel.attendances.consumptions.store', $a) }}" class="stack stack-sm hint__body" novalidate>
                            @csrf
                            <x-ui.select name="consumption_product_id" label="Produto" :options="$products->pluck('name', 'id')->all()" placeholder="Escolha o produto" />
                            <x-ui.input name="consumption_quantity" label="Quantidade usada" type="number" min="1" max="99" value="1" inputmode="numeric" />
                            <div><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Registrar material</x-ui.button></div>
                            <p class="text-sm text-muted">Não é cobrado. Sai do estoque só na conclusão.</p>
                        </form>
                    </details>
                @endif
            </section>

            <section class="pro-block" aria-labelledby="obs-atendimento">
                <h2 id="obs-atendimento" class="pro-section-title">Observações do atendimento</h2>
                @if ($editable)
                    <form method="POST" action="{{ route('panel.attendances.notes', $a) }}" class="stack stack-sm">
                        @csrf
                        @method('PUT')
                        <x-ui.textarea name="notes" label="Observações" :value="$a->notes" rows="2" optional />
                        <div><x-ui.button type="submit" variant="secondary" size="sm">Salvar observações</x-ui.button></div>
                    </form>
                @else
                    <p>{{ $a->notes ?: 'Sem observações.' }}</p>
                @endif
            </section>

            @if ($a->customer !== null && $canNote)
                @include('professional.partials.customer-notes', ['customer' => $a->customer, 'notes' => $notes, 'noteMax' => $noteMax])
            @endif

            @if ($a->customer_phone)
                <p class="text-sm"><x-icon name="phone" /> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $a->customer_phone) }}">{{ $a->customer_phone }}</a></p>
            @endif
        </div>
    </div>

    @if ($emAndamento)
        @can('complete', $a)
            @include('panel.checkout.partials.complete-dialog', ['attendance' => $a, 'total' => $total, 'methods' => $methods, 'autoOpen' => request()->boolean('finalizar') && ! $errors->any()])
        @endcan
    @endif

    @if ($a->status->isEditable())
        @can('cancel', $a)
            <x-ui.modal id="cancelar-atendimento" title="Cancelar este atendimento?">
                <form method="POST" action="{{ route('panel.attendances.cancel', $a) }}" class="stack" id="form-cancelar" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="cancelar-atendimento">
                    <p>Nada foi cobrado nem saiu do estoque. O registro continua no histórico. Se o cliente não veio, registre a falta no agendamento.</p>
                    <x-ui.input name="reason" id="campo-motivo-cancelamento" label="Motivo" />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn btn--danger" form="form-cancelar">Cancelar atendimento</button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    @endif
</x-layouts.professional>

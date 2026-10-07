@php
    use App\Modules\Checkout\Enums\AttendanceStatus;
    use App\Modules\Finance\Enums\PaymentKind;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use Illuminate\Support\Str;

    $u = auth('web')->user();
    $a = $attendance;
    $fmt = fn (?int $c) => $c !== null ? Money::fromCents($c)->format() : '—';
    $concluido = $a->status === AttendanceStatus::Completed;
    $podeDesconto = $editable && $u->can('discount', $a);
    $total = $concluido ? $a->total_cents : $breakdown?->total?->cents;
    $cor = match ($a->status->value) { 'open' => 'warning', 'in_progress' => 'info', 'completed' => 'success', default => 'neutral' };
@endphp
<x-layouts.staff :title="'Atendimento '.$a->code">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.attendances.index', ['data' => BusinessTime::dateOf($a->opened_at)]) }}">Voltar para atendimentos</a>
            <p class="eyebrow">Comanda {{ $a->code }}</p>
            <h1 class="page-head__title">{{ $a->customer_name }}</h1>
            <p class="cluster"><x-ui.badge :variant="$cor">{{ $a->status->label() }}</x-ui.badge> <span class="text-muted">{{ $a->source->label() }} · {{ $a->professional_name ?? 'Sem profissional' }}</span></p>
        </div>
        <div class="cluster">
            @if ($a->appointment !== null && $u->can('view', $a->appointment))
                <x-ui.button :href="route('panel.appointments.show', $a->appointment)" variant="secondary" icon="calendar">Agendamento {{ $a->appointment->code }}</x-ui.button>
            @endif
            @if ($concluido)
                <x-ui.button :href="route('panel.receipts.attendance', $a)" variant="secondary" icon="receipt">Comprovante (imprimir ou e-mail)</x-ui.button>
            @endif
        </div>
    </header>

    @foreach (['attendance', 'complete', 'refund'] as $campo)
        @error($campo)<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    {{-- Comanda (redesign + refinamento): a conta (total e concluir) a direita,
         o trabalho a esquerda numa folha so (blocos separados por fio, nao um
         cartao por informacao) e os ajustes (desconto, promocao, dados) abaixo
         da conta. No celular a ordem do HTML vale: conta, itens, ajustes.
         As regras explicadas ficam em "Como funciona". --}}
    <div class="ticket-layout">
        <aside class="ticket-side" aria-label="Conta do atendimento">
            <section class="ticket" aria-labelledby="conta-titulo">
                <h2 id="conta-titulo" class="ticket__label">{{ $concluido ? 'Total pago' : 'Total a pagar' }}</h2>
                <p class="ticket__total figure figure--lg" data-total>{{ $fmt($total) }}</p>
                <dl class="summary-list">
                    <div><dt>Subtotal</dt><dd class="numeric">{{ $fmt($concluido ? $a->subtotal_cents : $breakdown?->subtotal?->cents) }}</dd></div>
                    @foreach ($a->discounts as $d)
                        <div>
                            <dt>Desconto {{ $d->rule()->label() }} <span class="text-sm text-muted">· {{ $d->kind->label() }}@if ($d->reason) · {{ $d->reason }}@endif</span></dt>
                            <dd class="numeric">−{{ $fmt($d->amount_cents) }}</dd>
                        </div>
                    @endforeach
                    @if ($concluido && (int) $a->tip_cents > 0)<div><dt>Gorjeta</dt><dd class="numeric">{{ $fmt($a->tip_cents) }}</dd></div>@endif
                </dl>
                <div class="cluster">
                    @if ($a->status === AttendanceStatus::Open)
                        @can('update', $a)
                            <form method="POST" action="{{ route('panel.attendances.start', $a) }}">@csrf<x-ui.button type="submit" icon="play">Iniciar atendimento</x-ui.button></form>
                        @endcan
                    @endif
                    @if ($a->status === AttendanceStatus::InProgress)
                        @can('complete', $a)
                            <x-ui.button icon="check" data-dialog-open="concluir-atendimento">Concluir e receber</x-ui.button>
                        @endcan
                    @endif
                    @if ($a->status->isEditable())
                        @can('cancel', $a)
                            <x-ui.button variant="danger" icon="x" data-dialog-open="cancelar-atendimento">Cancelar atendimento</x-ui.button>
                        @endcan
                    @endif
                </div>
                @if ($a->status === AttendanceStatus::InProgress && ! $cashOpen && (int) $total > 0)
                    <x-ui.alert variant="warning">Não há caixa aberto: abra o caixa antes de concluir.@can('cash.view') <a href="{{ route('panel.cash.index') }}">Ir para o caixa</a>@endcan</x-ui.alert>
                @endif
            </section>
        </aside>

        <div class="ticket-main sheet">
        <x-ui.card title="Serviços e produtos" variant="section" class="ticket-items">
            @if ($a->items->isEmpty())
                <p class="text-sm text-muted">Nenhum item. Inclua ao menos um serviço ou produto.</p>
            @else
                <x-ui.table caption="Itens do atendimento" caption-hidden stacked>
                    <thead><tr><th scope="col">Item</th><th scope="col">Qtd.</th><th scope="col">Preço</th><th scope="col">Total</th>@if ($editable)<th scope="col"><span class="visually-hidden">Ações</span></th>@endif</tr></thead>
                    <tbody>
                        @foreach ($a->items as $item)
                            <tr>
                                <td data-label="Item">{{ $item->name }} <span class="text-sm text-muted">· {{ $item->item_type->label() }}</span></td>
                                <td data-label="Qtd." class="numeric">{{ $item->quantity }}</td>
                                <td data-label="Preço" class="numeric">{{ $fmt($item->unit_price_cents) }}</td>
                                <td data-label="Total" class="numeric">{{ $fmt($item->total_cents) }}</td>
                                @if ($editable)
                                    <td>
                                        <form method="POST" action="{{ route('panel.attendances.items.destroy', [$a, $item]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2">Retirar<span class="visually-hidden"> {{ $item->name }}</span></x-ui.button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
            <x-ui.hint summary="Como os valores são registrados">
                <p>Serviço do agendamento pelo preço combinado ao agendar; o que for incluído aqui, pelo preço do catálogo agora. Mudanças no catálogo não alteram este atendimento.</p>
            </x-ui.hint>

            @if ($editable)
                <div class="add-grid">
                    @if ($services->isNotEmpty())
                        <form method="POST" action="{{ route('panel.attendances.services.store', $a) }}" class="stack stack-sm" novalidate>
                            @csrf
                            <x-ui.select name="service_id" label="Incluir serviço" :options="$services->mapWithKeys(fn ($s) => [$s->id => $s->name.' — '.$s->price()->format()])->all()" placeholder="Escolha o serviço" />
                            <div><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Incluir serviço</x-ui.button></div>
                        </form>
                    @endif
                    @if ($productsForSale->isNotEmpty())
                        <form method="POST" action="{{ route('panel.attendances.products.store', $a) }}" class="stack stack-sm" novalidate>
                            @csrf
                            <x-ui.select name="product_id" label="Vender produto" :options="$productsForSale->mapWithKeys(fn ($p) => [$p->id => $p->name.' — '.Money::fromCents((int) $p->price_cents)->format()])->all()" placeholder="Escolha o produto" />
                            <x-ui.input name="quantity" label="Quantidade" type="number" min="1" max="99" value="1" inputmode="numeric" />
                            <div><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Incluir produto</x-ui.button></div>
                        </form>
                    @endif
                </div>
            @endif
        </x-ui.card>
        <x-ui.card title="Material usado (não cobrado)" variant="section">
            @if ($a->consumptions->isEmpty())
                <p class="text-sm text-muted">Nenhum material registrado.</p>
            @else
                <ul class="stack stack-sm">
                    @foreach ($a->consumptions as $c)
                        <li class="cluster">
                            <span>{{ $c->quantity }} × {{ $c->product_name }}</span>
                            @if ($editable)
                                <form method="POST" action="{{ route('panel.attendances.consumptions.destroy', [$a, $c]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2">Retirar<span class="visually-hidden"> {{ $c->product_name }}</span></x-ui.button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-ui.hint summary="Quando o material sai do estoque">
                <p>Baixa do estoque só na conclusão, vinculada a este atendimento. Cancelar antes não mexe no estoque.</p>
            </x-ui.hint>
            @if ($editable && $products->isNotEmpty())
                <form method="POST" action="{{ route('panel.attendances.consumptions.store', $a) }}" class="stack stack-sm" novalidate>
                    @csrf
                    <x-ui.select name="consumption_product_id" label="Registrar material" :options="$products->pluck('name', 'id')->all()" placeholder="Escolha o produto" />
                    <x-ui.input name="consumption_quantity" label="Quantidade usada" type="number" min="1" max="99" value="1" inputmode="numeric" />
                    <div><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Registrar material</x-ui.button></div>
                </form>
            @endif
        </x-ui.card>
        @if ($concluido || $a->payments->isNotEmpty())
            <x-ui.card title="Pagamentos" variant="section">
                @if ($a->payments->isEmpty())
                    <p class="text-sm text-muted">Sem pagamento (total zero).</p>
                @else
                    <x-ui.table caption="Pagamentos e estornos" caption-hidden stacked>
                        <thead><tr><th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Forma</th><th scope="col">Valor</th><th scope="col">Gorjeta</th><th scope="col">Quem</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                        <tbody>
                            @foreach ($a->payments as $p)
                                <tr>
                                    <td data-label="Quando" class="numeric">{{ $p->paid_at ? BusinessTime::formatLocal($p->paid_at) : '—' }}</td>
                                    <td data-label="Tipo">{{ $p->kind?->label() }}@if ($p->reason)<span class="text-sm text-muted"> · {{ $p->reason }}</span>@endif</td>
                                    <td data-label="Forma">{{ $p->method->label() }}</td>
                                    <td data-label="Valor" class="numeric">{{ $p->kind === PaymentKind::Refund ? '−' : '' }}{{ $fmt($p->amount_cents) }}</td>
                                    <td data-label="Gorjeta" class="numeric">{{ (int) $p->tip_cents > 0 ? ($p->kind === PaymentKind::Refund ? '−' : '').$fmt($p->tip_cents) : '—' }}</td>
                                    <td data-label="Quem">{{ $p->received_by_label ?? '—' }}</td>
                                    <td>
                                        @if ($p->kind === PaymentKind::Payment && ($refundable[$p->id] ?? 0) > 0)
                                            @can('payments.refund')
                                                <x-ui.button variant="secondary" size="sm" icon="undo-2" data-dialog-open="estorno-{{ $p->id }}">Estornar<span class="visually-hidden"> pagamento de {{ $fmt($p->amount_cents) }}</span></x-ui.button>
                                                <x-ui.modal :id="'estorno-'.$p->id" title="Estornar pagamento">
                                                    <form method="POST" action="{{ route('panel.attendances.refund', [$a, $p]) }}" class="stack" id="form-estorno-{{ $p->id }}" novalidate>
                                                        @csrf
                                                        <input type="hidden" name="_dialog" value="estorno-{{ $p->id }}">
                                                        <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                                                        @php $gorjetaEstornavel = $refundableTip[$p->id] ?? 0; $deste = old('_dialog') === 'estorno-'.$p->id; @endphp
                                                        <p>{{ $p->method->label() }} de {{ $fmt($p->amount_cents) }}@if ((int) $p->tip_cents > 0) + gorjeta de {{ $fmt($p->tip_cents) }}@endif. Pode estornar até {{ $fmt($refundable[$p->id]) }}. O pagamento original continua no histórico; o estorno sai do caixa aberto. A comissão do profissional é reduzida na mesma proporção do valor estornado (sem contar a gorjeta).</p>
                                                        <x-ui.input name="refund_amount" :id="'valor-estorno-'.$p->id" label="Valor total do estorno" inputmode="decimal" :value="$deste ? old('refund_amount') : Money::fromCents($refundable[$p->id])->toInput()" :error="$deste ? ($errors->first('refund_amount') ?: false) : false" />
                                                        @if ($gorjetaEstornavel > 0)
                                                            <x-ui.input name="refund_tip" :id="'gorjeta-estorno-'.$p->id" label="Quanto disso é gorjeta" inputmode="decimal" :hint="'Até '.$fmt($gorjetaEstornavel).'. Essa parte é descontada da gorjeta do profissional.'" :value="$deste ? old('refund_tip') : Money::fromCents($gorjetaEstornavel)->toInput()" :error="$deste ? ($errors->first('refund_tip') ?: false) : false" />
                                                        @endif
                                                        <x-ui.input name="refund_reason" :id="'motivo-estorno-'.$p->id" label="Motivo" :value="$deste ? old('refund_reason') : null" :error="$deste ? ($errors->first('refund_reason') ?: false) : false" />
                                                    </form>
                                                    <x-slot:footer>
                                                        <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                                                        <button type="submit" class="btn btn--danger" form="form-estorno-{{ $p->id }}">Confirmar estorno</button>
                                                    </x-slot:footer>
                                                </x-ui.modal>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endif

        @if ($a->stockMovements->isNotEmpty())
            <x-ui.card title="Estoque" variant="section">
                <ul class="stack stack-sm">
                    @foreach ($a->stockMovements as $m)
                        <li class="cluster">
                            <span>{{ $m->kind->label() }}: {{ abs($m->quantity) }} × {{ $m->product->name ?? 'Produto' }}</span>
                            @if ($m->kind->comesFromAttendance() && $m->reversal === null)
                                @can('stock.adjust')
                                    <x-ui.button variant="ghost" size="sm" icon="undo-2" data-dialog-open="devolver-{{ $m->id }}">Devolver ao estoque<span class="visually-hidden"> {{ $m->product->name ?? '' }}</span></x-ui.button>
                                    <x-ui.modal :id="'devolver-'.$m->id" title="Devolver ao estoque">
                                        <form method="POST" action="{{ route('panel.attendances.return-stock', [$a, $m]) }}" class="stack" id="form-devolver-{{ $m->id }}" novalidate>
                                            @csrf
                                            <input type="hidden" name="_dialog" value="devolver-{{ $m->id }}">
                                            <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                                            <p>Lança a entrada inversa ({{ abs($m->quantity) }}), ligada a este atendimento. O movimento original continua no histórico. Para devolver o dinheiro, estorne o pagamento.</p>
                                            <x-ui.input name="return_reason" :id="'motivo-devolver-'.$m->id" label="Motivo" />
                                        </form>
                                        <x-slot:footer>
                                            <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                                            <button type="submit" class="btn btn--danger" form="form-devolver-{{ $m->id }}">Confirmar devolução</button>
                                        </x-slot:footer>
                                    </x-ui.modal>
                                @endcan
                            @elseif ($m->reversal !== null)
                                <x-ui.badge>Devolvido</x-ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <x-ui.card title="Histórico" variant="section">
            <ol class="timeline">
                @foreach ($a->events as $e)
                    <li>
                        <span class="text-sm text-muted">{{ BusinessTime::formatLocal($e->occurred_at) }} · {{ $e->actor_label }}</span>
                        <span>{{ $e->description }}</span>
                        @if (! empty($e->data['motivo']))<span class="text-sm text-muted">Motivo: {{ $e->data['motivo'] }}</span>@endif
                        @if (isset($e->data['antes_cents']))<span class="text-sm text-muted">Antes {{ $fmt((int) $e->data['antes_cents']) }} · desconto {{ $fmt((int) $e->data['desconto_cents']) }} · depois {{ $fmt((int) $e->data['depois_cents']) }}</span>@endif
                        @if (isset($e->data['total_cents']))<span class="text-sm text-muted">Total {{ $fmt((int) $e->data['total_cents']) }}@if (! empty($e->data['pagamentos'])) · {{ $e->data['pagamentos'] }}@endif</span>@endif
                        @if (! empty($e->data['de']))<span class="text-sm text-muted">De {{ $e->data['de'] }} para {{ $e->data['para'] }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
        </div>

        <div class="ticket-extra sheet">
            {{-- Desconto e promocao so aparecem quando ha o que fazer (atendimento aberto
                 e permissao); o desconto ja aplicado aparece na conta acima. --}}
            @if ($podeDesconto || ($editable && $a->customer_id !== null && $u->can('promotions.apply')))
            <x-ui.card title="Desconto" variant="section">
                <x-ui.hint summary="Como o desconto funciona">
                    <p>Desconto só sobre serviços, nunca sobre produtos; percentual arredondado ao centavo (meio centavo para cima), calculado uma vez sobre o total dos serviços.</p>
                    @if ($podeDesconto)<p>Vale um desconto só, o maior: se já houver cupom, pontos, aniversário ou indicação, o manual só entra se for maior (e libera o cupom/pontos).</p>@endif
                </x-ui.hint>
                @if ($podeDesconto)
                    @foreach ($a->discounts as $d)
                        <form method="POST" action="{{ route('panel.attendances.discount.destroy', [$a, $d]) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2">Retirar desconto de {{ $d->rule()->label() }}</x-ui.button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('panel.attendances.discount.store', $a) }}" class="stack stack-sm" novalidate>
                        @csrf
                        <x-ui.select name="discount_type" label="Tipo de desconto" :options="['percent' => 'Percentual (%)', 'fixed' => 'Valor (R$)']" value="percent" />
                        <x-ui.input name="discount_value" label="Desconto" inputmode="decimal" hint="Ex.: 10 (para 10%) ou 5,00 (para R$ 5,00)." />
                        <x-ui.input name="discount_reason" label="Motivo" hint="Obrigatório. Fica no histórico e na auditoria." />
                        <div><x-ui.button type="submit" variant="secondary" size="sm">Aplicar desconto</x-ui.button></div>
                    </form>
                @endif

                @if ($editable && $a->customer_id !== null && $u->can('promotions.apply'))
                    <form method="POST" action="{{ route('panel.attendances.promotion', $a) }}" class="stack stack-sm" novalidate>
                        @csrf
                        <h3 class="h4">Cupom ou pontos do cliente</h3>
                        <x-ui.input name="coupon" id="promocao-cupom" label="Cupom" optional />
                        <x-ui.checkbox name="use_loyalty" label="Usar os pontos do cliente" hint="Os pontos só saem do saldo quando o atendimento for concluído." />
                        <div><x-ui.button type="submit" variant="secondary" size="sm" icon="tag">Aplicar promoção</x-ui.button></div>
                    </form>
                @endif
            </x-ui.card>
            @endif

            <x-ui.card title="Atendimento" variant="section">
                <dl class="summary-list">
                    <div><dt>Profissional</dt><dd>{{ $a->professional_name ?? '—' }}</dd></div>
                    <div><dt>Aberto</dt><dd>{{ BusinessTime::formatLocal($a->opened_at) }}</dd></div>
                    @if ($a->started_at)<div><dt>Iniciado</dt><dd>{{ BusinessTime::formatLocal($a->started_at) }}</dd></div>@endif
                    @if ($a->completed_at)<div><dt>Concluído</dt><dd>{{ BusinessTime::formatLocal($a->completed_at) }}</dd></div>@endif
                    @if ($a->cancelled_at)<div><dt>Cancelado</dt><dd>{{ BusinessTime::formatLocal($a->cancelled_at) }}</dd></div>@endif
                    @if ($a->cancellation_reason)<div><dt>Motivo do cancelamento</dt><dd>{{ $a->cancellation_reason }}</dd></div>@endif
                    <div><dt>Telefone</dt><dd>{{ $a->customer_phone ?? '—' }}</dd></div>
                </dl>

                @if ($editable && $professionals->count() > 1)
                    <form method="POST" action="{{ route('panel.attendances.professional', $a) }}" class="cluster">
                        @csrf
                        @method('PUT')
                        <x-ui.select name="professional_id" label="Quem está atendendo" :options="$professionals->pluck('display_name', 'id')->all()" :value="$a->professional_id" />
                        <x-ui.button type="submit" variant="secondary" size="sm">Trocar profissional</x-ui.button>
                    </form>
                @endif
                @if ($editable)
                    <form method="POST" action="{{ route('panel.attendances.notes', $a) }}" class="stack stack-sm">
                        @csrf
                        @method('PUT')
                        <x-ui.textarea name="notes" label="Observações" :value="$a->notes" rows="2" optional />
                        <div><x-ui.button type="submit" variant="secondary" size="sm">Salvar observações</x-ui.button></div>
                    </form>
                @elseif ($a->notes)
                    <p class="text-sm"><strong>Observações:</strong> {{ $a->notes }}</p>
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($a->status === AttendanceStatus::InProgress)
        @can('complete', $a)
            <x-ui.modal id="concluir-atendimento" title="Concluir e receber">
                <form method="POST" action="{{ route('panel.attendances.complete', $a) }}" class="stack" id="form-concluir" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="concluir-atendimento">
                    @error('complete')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                    <input type="hidden" name="completion_key" value="{{ Str::uuid() }}">
                    <p><strong>Total a pagar: {{ $fmt($total) }}</strong>. A soma dos valores precisa fechar exatamente com o total. Para dividir, use mais de uma linha. Gorjeta é à parte.</p>
                    @for ($i = 0; $i < 3; $i++)
                        <fieldset class="stack stack-sm">
                            <legend>{{ $i === 0 ? 'Pagamento' : 'Outra forma (opcional)' }}</legend>
                            <x-ui.select :name="'payments['.$i.'][method]'" :id="'pagamento-'.$i.'-forma'" label="Forma" :options="$methods" :value="old('payments.'.$i.'.method')" placeholder="Escolha a forma" optional />
                            <x-ui.input :name="'payments['.$i.'][amount]'" :id="'pagamento-'.$i.'-valor'" label="Valor" inputmode="decimal" :value="old('payments.'.$i.'.amount', $i === 0 && (int) $total > 0 ? Money::fromCents((int) $total)->toInput() : null)" :error="$errors->first('payments.'.$i.'.amount') ?: null" optional />
                            <x-ui.input :name="'payments['.$i.'][tip]'" :id="'pagamento-'.$i.'-gorjeta'" label="Gorjeta" inputmode="decimal" :value="old('payments.'.$i.'.tip')" optional />
                            <x-ui.input :name="'payments['.$i.'][gift_code]'" :id="'pagamento-'.$i.'-vale'" label="Código do vale-presente" :value="old('payments.'.$i.'.gift_code')" hint="Só quando a forma for vale-presente. Uso único: use o valor do vale, até o total." :error="$errors->first('payments.'.$i.'.gift_code') ?: null" optional />
                        </fieldset>
                    @endfor
                    <p class="text-sm text-muted">Ao confirmar: o valor fica congelado, o pagamento entra no caixa aberto, os produtos saem do estoque e o agendamento é concluído. Não dá para desfazer; correções são feitas por estorno.</p>
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn" form="form-concluir">Confirmar conclusão</button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    @endif

    @if ($a->status->isEditable())
        @can('cancel', $a)
            <x-ui.modal id="cancelar-atendimento" title="Cancelar este atendimento?">
                <form method="POST" action="{{ route('panel.attendances.cancel', $a) }}" class="stack" id="form-cancelar" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="cancelar-atendimento">
                    <p>Nada foi cobrado nem saiu do estoque. O registro continua no histórico. O agendamento de origem não muda: se o cliente não veio ou desistiu, registre isso na agenda.</p>
                    <x-ui.input name="reason" id="campo-motivo-cancelamento" label="Motivo" />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn btn--danger" form="form-cancelar">Cancelar atendimento</button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    @endif

</x-layouts.staff>

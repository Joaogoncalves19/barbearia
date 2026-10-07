{{--
    Concluir e receber (Fase 6): o MESMO formulario na comanda do painel e no
    atendimento da area do profissional. Envia para panel.attendances.complete
    (conclusao atomica: pagamentos, gorjeta, caixa, estoque, comissao).
    Espera $attendance, $total (centavos) e $methods (PaymentMethod::counterOptions());
    $autoOpen (opcional) abre o modal ao carregar ("Finalizar" vindo do Hoje).
--}}
@php use App\Modules\Shared\Support\Money; use Illuminate\Support\Str; $fmt = fn (?int $c) => $c !== null ? Money::fromCents($c)->format() : '—'; @endphp
<x-ui.modal id="concluir-atendimento" title="Concluir e receber" :data-dialog-autoopen="($autoOpen ?? false) ?: null">
    <form method="POST" action="{{ route('panel.attendances.complete', $attendance) }}" class="stack" id="form-concluir" novalidate>
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

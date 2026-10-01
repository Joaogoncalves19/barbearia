@php
    use App\Modules\Loyalty\Enums\DiscountType;
    use App\Modules\Shared\Support\Money;
    $novo = ! $coupon->exists;
    $valor = $coupon->discount_type === DiscountType::Fixed
        ? ($coupon->amount_cents !== null ? Money::fromCents($coupon->amount_cents)->toInput() : null)
        : ($coupon->percent_bp !== null ? rtrim(rtrim(number_format($coupon->percent_bp / 100, 2, ',', ''), '0'), ',') : null);
@endphp
<x-layouts.staff :title="$novo ? 'Novo cupom' : 'Editar cupom'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.coupons.index') }}">Voltar para cupons</a>
            <h1 class="page-head__title">{{ $novo ? 'Novo cupom' : $coupon->code }}</h1>
            @unless ($novo)
                <p class="text-muted">Mudar o cupom vale para usos novos. Agendamentos que já reservaram o cupom mantêm o desconto combinado.</p>
            @endunless
        </div>
    </header>

    <form method="POST" action="{{ $novo ? route('panel.coupons.store') : route('panel.coupons.update', $coupon) }}" class="stack" novalidate>
        @csrf
        @unless ($novo) @method('PUT') @endunless
        <div class="dashboard-grid">
            <x-ui.card title="Cupom">
                <div class="stack">
                    <x-ui.input name="code" label="Código" :value="$coupon->code" hint="Letras, números, hífen ou sublinhado. Ex.: BEMVINDO10." />
                    <x-ui.input name="description" label="Descrição" :value="$coupon->description" optional />
                </div>
            </x-ui.card>
            <x-ui.card title="Desconto e limites">
                <div class="stack">
                    <x-ui.select name="discount_type" label="Tipo" :options="['percent' => 'Percentual', 'fixed' => 'Valor fixo']" :value="$coupon->discount_type?->value ?? 'percent'" />
                    <x-ui.input name="value" label="Percentual ou valor" inputmode="decimal" :value="$valor" hint="Ex.: 10 (para 10%) ou 15,00." />
                    <x-ui.input name="max_uses" label="Limite de usos" type="number" min="1" inputmode="numeric" :value="$coupon->max_uses" hint="Vazio = sem limite. Cada cliente usa uma vez." optional />
                    <x-ui.input name="expires_on" label="Válido até" type="date" :value="$coupon->expires_on?->toDateString()" optional />
                </div>
            </x-ui.card>
        </div>
        <div><x-ui.button type="submit">{{ $novo ? 'Criar cupom' : 'Salvar alterações' }}</x-ui.button></div>
    </form>
</x-layouts.staff>

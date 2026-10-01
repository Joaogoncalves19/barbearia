@php use Illuminate\Support\Str; @endphp
<x-layouts.staff title="Vender vale-presente">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.gift-cards.index') }}">Voltar para vales-presente</a>
            <h1 class="page-head__title">Vender vale-presente</h1>
            <p class="text-muted">O valor entra no caixa aberto na forma escolhida. O código é gerado pelo sistema; depois é possível imprimir ou enviar por e-mail.</p>
        </div>
    </header>

    @error('gift_card')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <form method="POST" action="{{ route('panel.gift-cards.store') }}" class="stack" novalidate>
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', (string) Str::uuid()) }}">
        <div class="dashboard-grid">
            <x-ui.card title="Valor e pagamento">
                <div class="stack">
                    <x-ui.input name="amount" label="Valor do vale" inputmode="decimal" hint="Ex.: 100,00" />
                    <x-ui.select name="method" label="Forma de pagamento" :options="$methods" value="pix" />
                    <x-ui.input name="expires_on" label="Válido até" type="date" :value="$expiry" hint="Deixe em branco para sem validade." optional />
                </div>
            </x-ui.card>
            <x-ui.card title="Quem compra e quem ganha">
                <div class="stack">
                    <x-ui.input name="purchaser_name" label="Quem comprou" optional />
                    <x-ui.input name="purchaser_email" label="E-mail de quem comprou" type="email" optional />
                    <x-ui.input name="recipient_name" label="Presenteado" optional />
                    <x-ui.input name="recipient_email" label="E-mail do presenteado" type="email" hint="Para enviar o vale por e-mail." optional />
                    <x-ui.textarea name="message" label="Mensagem" rows="2" optional />
                </div>
            </x-ui.card>
        </div>
        <div><x-ui.button type="submit" icon="receipt">Registrar venda</x-ui.button></div>
    </form>
</x-layouts.staff>

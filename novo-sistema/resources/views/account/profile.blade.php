<x-layouts.account title="Meus dados">
    <header class="stack stack-sm">
        <h1 class="h2">Meus dados</h1>
        <p class="text-muted">Mantenha seu celular em dia para receber os avisos do seu horário.</p>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('account.profile.update') }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            <x-ui.input name="name" label="Nome completo" autocomplete="name" :value="$customer->name" />
            <x-ui.input name="phone" label="Celular com DDD" type="tel" autocomplete="tel-national" :value="$customer->phone" optional />
            <x-ui.input name="birth_date" label="Data de nascimento" type="date" :value="$customer->birth_date?->format('Y-m-d')" optional />
            <div><x-ui.button type="submit" variant="accent">Salvar</x-ui.button></div>
        </form>
    </x-ui.card>

    {{-- Fase 10: preferencias de e-mail. Marketing so com escolha explicita; lembretes sao opcionais. --}}
    @php $consent = $customer->marketing_email_consent; @endphp
    <x-ui.card title="E-mails que você recebe" id="emails">
        <form method="POST" action="{{ route('account.preferences.update') }}" class="stack" data-preferences>
            @csrf
            @method('PUT')
            <p class="text-sm text-muted">Confirmação, remarcação e cancelamento do seu horário, comprovantes e avisos da assinatura sempre chegam: são necessários ao serviço.</p>
            <x-ui.switch name="reminders" label="Lembretes do meu horário por e-mail (véspera e algumas horas antes)" :checked="$customer->email_reminders_enabled" />
            <fieldset class="stack stack-sm">
                <legend>Novidades e promoções por e-mail</legend>
                @if ($consent === \App\Modules\Customers\Enums\MarketingConsent::Unknown)
                    <p class="text-sm text-muted">Você ainda não escolheu. Enquanto não escolher, não enviamos novidades.</p>
                @endif
                <x-ui.radio name="marketing" value="sim" label="Quero receber" :checked="$consent === \App\Modules\Customers\Enums\MarketingConsent::Granted" />
                <x-ui.radio name="marketing" value="nao" label="Não quero receber" :checked="$consent === \App\Modules\Customers\Enums\MarketingConsent::Revoked" />
            </fieldset>
            <div><x-ui.button type="submit" variant="secondary">Salvar preferências</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Acesso">
        <div class="stack">
            <dl class="summary-list">
                <div><dt>E-mail</dt><dd>{{ $customer->email }}</dd></div>
                {{-- CPF sempre mascarado na tela. --}}
                <div><dt>CPF</dt><dd data-cpf>{{ $customer->cpf ? \App\Modules\Customers\Support\Cpf::mask($customer->cpf) : 'Não informado' }}</dd></div>
                <div><dt>Senha</dt><dd>{{ $customer->hasPassword() ? 'Definida' : 'Você entra pelo link do e-mail' }}</dd></div>
            </dl>
            @if ($pendingEmail)
                <x-ui.alert data-pending-email>
                    Troca de e-mail aguardando confirmação: enviamos um link para {{ $pendingEmail }}.
                    <form method="POST" action="{{ route('account.email.cancel') }}" class="inline-form">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn--ghost btn--sm">Cancelar pedido</button>
                    </form>
                </x-ui.alert>
            @endif
            @error('email')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
            <div class="cluster">
                <x-ui.button :href="route('account.email.edit')" variant="secondary" size="sm" icon="mail">Trocar e-mail</x-ui.button>
                <x-ui.button :href="route('account.password.edit')" variant="secondary" size="sm" icon="key-round">{{ $customer->hasPassword() ? 'Alterar senha' : 'Criar senha' }}</x-ui.button>
            </div>
            <p class="text-sm text-muted">O CPF não muda pela conta: para corrigir, fale com a barbearia.</p>
        </div>
    </x-ui.card>
</x-layouts.account>

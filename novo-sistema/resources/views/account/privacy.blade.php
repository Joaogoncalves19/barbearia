@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $finalidade = ['marketing_email' => 'Novidades e promoções por e-mail', 'reminder_email' => 'Lembretes por e-mail'];
    $origem = ['account_preferences' => 'na sua conta', 'signup' => 'no cadastro', 'unsubscribe_link' => 'pelo link de descadastro', 'account_erasure' => 'na exclusão da conta', 'legacy_import' => 'no sistema anterior'];
@endphp
<x-layouts.account title="Privacidade">
    <header class="stack stack-sm">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h2">Privacidade e seus dados</h1>
        <p class="text-muted">Pela LGPD, você pode ver, levar e apagar os dados que a barbearia guarda sobre você.</p>
    </header>

    <x-ui.card title="O que guardamos e por quê">
        <ul class="stack stack-sm text-sm">
            <li><strong>Cadastro</strong> (nome, e-mail, celular, CPF, nascimento): para identificar você, marcar horários e enviar os avisos do serviço.</li>
            <li><strong>Agendamentos, atendimentos e pagamentos</strong>: histórico do serviço e obrigação fiscal; ficam guardados mesmo se a conta for excluída, sem o seu nome.</li>
            <li><strong>Registro de e-mails e avisos da conta</strong>: 12 meses; depois o endereço e o conteúdo são apagados automaticamente.</li>
            <li><strong>Suas escolhas sobre e-mails</strong>: guardamos a prova de cada escolha, como manda a lei.</li>
        </ul>
        @if ($hasPolicy)
            <p class="text-sm"><a href="{{ route('site.privacy') }}">Leia a política de privacidade completa</a>.</p>
        @endif
    </x-ui.card>

    <x-ui.card title="Suas escolhas de e-mail">
        <div class="stack">
            <p class="text-sm">Novidades e promoções: <strong>{{ $customer->marketing_email_consent->label() }}</strong>. Lembretes: <strong>{{ $customer->email_reminders_enabled ? 'ligados' : 'desligados' }}</strong>.</p>
            <div><x-ui.button :href="route('account.profile.edit').'#emails'" variant="secondary" size="sm" icon="mail">Mudar minhas escolhas</x-ui.button></div>
            @if ($consents->isNotEmpty())
                <x-ui.table caption="Histórico das suas escolhas" stacked>
                    <thead><tr><th scope="col">Quando</th><th scope="col">O quê</th><th scope="col">Escolha</th><th scope="col">Onde</th></tr></thead>
                    <tbody>
                        @foreach ($consents as $c)
                            <tr>
                                <td data-label="Quando" class="numeric">{{ $c->occurred_at ? BusinessTime::formatLocal($c->occurred_at, 'd/m/Y H:i') : '—' }}</td>
                                <td data-label="O quê">{{ $finalidade[$c->purpose] ?? $c->purpose }}</td>
                                <td data-label="Escolha">{{ $c->action->value === 'granted' ? 'Aceitou' : 'Recusou' }}</td>
                                <td data-label="Onde">{{ $origem[$c->source] ?? 'outro' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card title="Baixar meus dados">
        <form method="POST" action="{{ route('account.privacy.export') }}" class="stack" data-export>
            @csrf
            <p class="text-sm">Um arquivo (JSON) com o seu cadastro, escolhas de e-mail, agendamentos, atendimentos, pagamentos, pontos, assinatura, avaliações e avisos. Ele é gerado na hora e não fica guardado no servidor. Por segurança, pedimos a sua senha antes.</p>
            <div><x-ui.button type="submit" variant="secondary" icon="arrow-down">Baixar meus dados</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Excluir minha conta">
        <div class="stack">
            <p class="text-sm">Apaga seus dados pessoais e encerra o acesso. O histórico de atendimentos e pagamentos continua guardado sem o seu nome (obrigação fiscal); suas avaliações ficam anônimas; pontos de fidelidade são perdidos. Não dá para desfazer.</p>
            <div><x-ui.button :href="route('account.close')" variant="danger" icon="trash-2">Quero excluir minha conta</x-ui.button></div>
        </div>
    </x-ui.card>
</x-layouts.account>

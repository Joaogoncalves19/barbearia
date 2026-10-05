{{-- Descadastro do marketing pelo link da campanha (Fase 10). Abrir nao descadastra; o botao sim. --}}
<x-layouts.auth title="Descadastro" area="site">
    <header class="stack stack-sm">
        <h1 class="h2">Novidades por e-mail</h1>
    </header>

    @if ($done)
        <x-ui.alert variant="success" title="Você está descadastrado">Não enviaremos mais novidades e promoções para este e-mail.</x-ui.alert>
        <p class="text-sm text-muted">Confirmações, lembretes e comprovantes do seu atendimento continuam chegando: eles são necessários ao serviço. Mudou de ideia? Ative de novo em “Meus dados”, na sua conta.</p>
    @else
        <p>Quer parar de receber novidades e promoções por e-mail?</p>
        <form method="POST" action="{{ request()->fullUrl() }}" class="stack">
            @csrf
            <x-ui.button type="submit" variant="accent" block>Não quero mais receber</x-ui.button>
        </form>
        <p class="text-sm text-muted">Confirmações, lembretes e comprovantes do seu atendimento continuam chegando.</p>
    @endif
</x-layouts.auth>

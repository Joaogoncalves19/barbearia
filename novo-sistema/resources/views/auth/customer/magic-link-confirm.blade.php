<x-layouts.auth title="Entrar com link" area="site">
    @if ($valid)
        <div class="stack stack-sm">
            <h1 class="h2">Tudo certo</h1>
            <p class="text-muted">Toque no botão para entrar na sua conta.</p>
        </div>

        <form method="POST" action="{{ route('customer.magic.consume', ['token' => $token]) }}" class="stack">
            @csrf
            <x-ui.button type="submit" variant="accent" block>Entrar na minha conta</x-ui.button>
        </form>
    @else
        <div class="stack stack-sm">
            <h1 class="h2">Link inválido</h1>
            <p class="text-muted">Este link não é válido, já foi usado ou expirou.</p>
        </div>

        <x-ui.button :href="route('customer.magic.request')" variant="accent" block>Pedir um novo link</x-ui.button>
    @endif
</x-layouts.auth>

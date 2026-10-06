<x-layouts.account title="Confirmar novo e-mail">
    <header class="stack stack-sm">
        <h1 class="h2">Confirmar novo e-mail</h1>
        <p class="text-muted">O e-mail da sua conta passa a ser <strong>{{ $newEmail }}</strong>. Você passa a entrar com ele, e os avisos chegam nele.</p>
    </header>

    {{-- Confirmar e um POST: abrir o link (inclusive um leitor de e-mail que "visita" links) nao troca nada. --}}
    <form method="POST" action="{{ route('account.email.confirm', ['token' => $token]) }}">
        @csrf
        <x-ui.button type="submit" variant="accent" icon="check">Confirmar troca de e-mail</x-ui.button>
    </form>
</x-layouts.account>

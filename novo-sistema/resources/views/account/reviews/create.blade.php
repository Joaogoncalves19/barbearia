@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.account title="Avaliar atendimento">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.reviews.index') }}">Voltar para avaliações</a>
        <h1 class="h2">Como foi o seu atendimento?</h1>
        <p class="text-muted">{{ $attendance->completed_at ? BusinessTime::formatLocal($attendance->completed_at, 'd/m/Y') : '' }} · {{ $attendance->professional->display_name ?? 'Profissional' }} · {{ $attendance->items->pluck('name')->join(', ') }}</p>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('account.reviews.store', $attendance) }}" class="stack" novalidate>
            @csrf
            <fieldset class="stack stack-sm">
                <legend>Sua nota</legend>
                @error('rating')<p class="field__error" role="alert">{{ $message }}</p>@enderror
                <div class="cluster">
                    @for ($n = 5; $n >= 1; $n--)
                        <x-ui.radio name="rating" :value="$n" :label="$n.' '.($n === 1 ? 'estrela' : 'estrelas')" />
                    @endfor
                </div>
            </fieldset>
            <x-ui.textarea name="comment" label="Comentário" :hint="'Até '.$max.' caracteres. Aparece junto da sua nota, com o seu primeiro nome.'" :maxlength="$max" optional />
            <div><x-ui.button type="submit" variant="accent">Enviar avaliação</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.account>

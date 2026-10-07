{{--
    Anotacoes dos profissionais sobre o cliente (preferencias, cuidados).
    Espera $customer, $notes (CustomerNote, visibilidade "profissionais") e
    $noteMax. So aparece para quem pode (CustomerPolicy@notes).
--}}
@php use App\Modules\Scheduling\Support\BusinessTime; $eu = auth('web')->id(); @endphp
<section class="pro-block" aria-labelledby="anotacoes">
    <h2 id="anotacoes" class="pro-section-title"><x-icon name="notebook-pen" /> Anotações do cliente</h2>
    @if ($notes->isEmpty())
        <p class="text-sm text-muted">Nenhuma anotação ainda. Registre preferências e cuidados (ex.: "disfarçado na zero", "pele sensível").</p>
    @else
        <ul class="notes" role="list">
            @foreach ($notes as $n)
                <li class="note">
                    <p class="note__body">{{ $n->body }}</p>
                    <p class="note__meta">{{ $n->author_label ?? 'Equipe' }}@if ($n->created_at) · {{ BusinessTime::formatLocal($n->created_at, 'd/m/Y') }}@endif</p>
                    @if ($n->author_user_id !== null && $n->author_user_id === $eu)
                        <form method="POST" action="{{ route('pro.customers.notes.destroy', [$customer, $n]) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2">Remover<span class="visually-hidden"> anotação</span></x-ui.button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    <form method="POST" action="{{ route('pro.customers.notes.store', $customer) }}" class="stack stack-sm" novalidate>
        @csrf
        <x-ui.textarea name="note" id="nova-anotacao" label="Nova anotação" rows="2" :maxlength="$noteMax" :hint="'Até '.$noteMax.' caracteres. Outros profissionais também veem.'" />
        <div><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Registrar anotação</x-ui.button></div>
    </form>
</section>

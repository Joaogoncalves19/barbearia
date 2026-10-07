{{--
    PERFIL do profissional (Fase 12.5). Edita o que qualquer pessoa da equipe
    ja edita (o proprio nome e a senha); a ficha, o expediente, as pausas e
    as folgas sao consultados (quem altera e a gerencia; P12.5-02/03).
--}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $dias = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 0 => 'Domingo'];
    $hm = fn (string $t) => substr($t, 0, 5);
    $foto = $professional->photoUrl();
@endphp
<x-layouts.professional title="Meu perfil">
    <header class="pro-head pro-head--profile">
        <x-ui.avatar :name="$professional->display_name" :src="$foto" size="xl" />
        <div class="pro-head__text">
            <p class="eyebrow">Meu perfil</p>
            <h1 class="pro-head__title">{{ $professional->display_name }}</h1>
            @if ($professional->headline)<p class="text-muted">{{ $professional->headline }}</p>@endif
        </div>
    </header>

    @include('professional.partials.errors')

    <div class="pro-split">
        <div class="stack stack-lg">
            <section class="pro-block" aria-labelledby="conta">
                <h2 id="conta" class="pro-section-title"><x-icon name="key-round" /> Conta de acesso</h2>
                <form method="POST" action="{{ route('panel.account.update') }}" class="stack stack-sm" novalidate>
                    @csrf
                    @method('PUT')
                    <x-ui.input name="name" label="Seu nome" :value="$user->name" autocomplete="name" />
                    <div><x-ui.button type="submit" variant="secondary" size="sm">Salvar nome</x-ui.button></div>
                </form>
                <dl class="summary-list">
                    <div><dt>Usuário</dt><dd>{{ $user->username }}</dd></div>
                    <div><dt>E-mail</dt><dd>{{ $user->email ?? '—' }}</dd></div>
                </dl>
                <p><x-ui.button :href="route('panel.password.edit')" variant="secondary" icon="key-round">Trocar senha</x-ui.button></p>
                <p class="text-sm text-muted">Usuário e e-mail de acesso são definidos pelo proprietário.</p>
            </section>

            <section class="pro-block" aria-labelledby="ficha">
                <h2 id="ficha" class="pro-section-title"><x-icon name="user" /> Ficha no site</h2>
                <dl class="summary-list">
                    <div><dt>Nome no site</dt><dd>{{ $professional->display_name }}</dd></div>
                    <div><dt>Aparece no site</dt><dd>{{ $professional->is_public ? 'Sim' : 'Não' }}</dd></div>
                    <div><dt>Recebe agendamentos</dt><dd>{{ $professional->is_bookable ? 'Sim' : 'Não' }}</dd></div>
                </dl>
                @if ($professional->bio)<p>{{ $professional->bio }}</p>@endif
                <p class="text-sm text-muted">Foto, apresentação e serviços são cadastrados pela gerência.</p>
            </section>

            <section class="pro-block" aria-labelledby="avaliacoes">
                <h2 id="avaliacoes" class="pro-section-title"><x-icon name="star" /> Avaliações</h2>
                @if ($reviews['count'] === 0)
                    <p class="text-sm text-muted">Nenhuma avaliação publicada ainda.</p>
                @else
                    <p><span class="figure figure--lg" data-rating>{{ number_format((float) $reviews['average'], 1, ',', '') }}</span> <span class="text-muted">de 5 · {{ $reviews['count'] }} {{ $reviews['count'] === 1 ? 'avaliação publicada' : 'avaliações publicadas' }}</span></p>
                @endif
                @if ($canSeeReviews)
                    <a class="link-arrow text-sm" href="{{ route('panel.reviews.index') }}">Ver avaliações <x-icon name="arrow-right" /></a>
                @endif
            </section>
        </div>

        <div class="stack stack-lg">
            <section class="pro-block" aria-labelledby="servicos">
                <h2 id="servicos" class="pro-section-title"><x-icon name="scissors" /> Serviços que você faz</h2>
                @if ($services->isEmpty())
                    <p class="text-sm text-muted">Nenhum serviço ligado à sua ficha. Fale com a gerência.</p>
                @else
                    <ul class="items" role="list">
                        @foreach ($services as $s)
                            <li class="items__row">
                                <span class="items__name">{{ $s->name }}<span class="items__kind">{{ $s->durationLabel() }}{{ $s->is_active ? '' : ' · inativo' }}</span></span>
                                <span class="items__price numeric">{{ $s->price()->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="pro-block" aria-labelledby="expediente">
                <h2 id="expediente" class="pro-section-title"><x-icon name="clock" /> Expediente e pausas</h2>
                @if ($workingHours->isEmpty())
                    <p class="text-sm text-muted">Sem expediente próprio: segue o funcionamento da barbearia.</p>
                @else
                    <dl class="summary-list">
                        @foreach ($dias as $n => $nome)
                            <div><dt>{{ $nome }}</dt><dd>{{ $workingHours->has($n) ? $workingHours[$n]->map(fn ($w) => $hm($w->starts_at).'–'.$hm($w->ends_at))->join(', ') : 'Folga' }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                @if ($breaks->isNotEmpty())
                    <p class="text-sm"><strong>Pausas:</strong>
                        {{ $breaks->map(fn ($b) => ($b->label ?: 'Pausa').' '.$hm($b->starts_at).'–'.$hm($b->ends_at).($b->weekday !== null ? ' ('.($dias[$b->weekday] ?? '').')' : ' (todos os dias)'))->join('; ') }}</p>
                @endif
                @if ($timeOff->isNotEmpty())
                    <p class="text-sm"><strong>Próximas folgas:</strong>
                        {{ $timeOff->map(fn ($t) => $t->starts_on->format('d/m').($t->ends_on->ne($t->starts_on) ? ' a '.$t->ends_on->format('d/m') : '').' ('.mb_strtolower($t->kind->label()).')')->join('; ') }}</p>
                @endif
                <p class="text-sm text-muted">Expediente, pausas e folgas são definidos pela gerência. Precisa mudar? Fale com ela.</p>
            </section>
        </div>
    </div>
</x-layouts.professional>

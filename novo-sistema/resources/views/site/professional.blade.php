{{-- PROFISSIONAL (Fase 11): dados do cadastro do profissional; "agendar com" leva direto aos horarios dele. --}}
@php
    $iniciais = \App\Modules\SiteContent\Services\PublicSite::initials($pro->display_name);
    $primeiro = strtok($pro->display_name, ' ');
@endphp
<x-site.page :title="$pro->display_name" :description="($pro->headline ? $pro->headline.'. ' : '').'Agende com '.$pro->display_name.' na '.$cfg->name().'.'" current="team" :og-image="\App\Modules\Shared\Media\ImageStore::url($pro->photo_path)">
    <section class="section section--tight">
        <div class="container pro-profile">
            <div class="pro-profile__media">
                @if ($pro->photo_path)
                    <x-site.img :path="$pro->photo_path" :alt="'Retrato de '.$pro->display_name" sizes="(min-width: 64rem) 30vw, 100vw" eager />
                @else
                    <span class="monogram monogram--lg" aria-hidden="true">{{ $iniciais }}</span>
                @endif
            </div>
            <div class="stack stack-lg">
                <div class="stack">
                    <a class="link-arrow text-sm" href="{{ route('site.team') }}">Equipe</a>
                    <h1 class="h1">{{ $pro->display_name }}</h1>
                    @if ($pro->headline)<p class="lead">{{ $pro->headline }}</p>@endif
                    @if ($pro->bio)<p class="pre-line">{{ $pro->bio }}</p>@endif
                </div>

                <section class="stack" aria-labelledby="servicos-pro">
                    <h2 id="servicos-pro" class="h3">Agendar com {{ $primeiro }}</h2>
                    @if ($services->isEmpty())
                        <p class="text-muted">No momento, {{ $primeiro }} não tem serviços para agendar pelo site.</p>
                    @else
                        <ul class="menu-board" role="list">
                            @foreach ($services as $s)
                                <li class="menu-line" data-service="{{ $s->slug }}">
                                    <a class="menu-line__link" href="{{ route('booking.slots', ['service' => $s, 'profissional' => $pro->slug]) }}">
                                        <span class="menu-line__top">
                                            <span class="menu-line__name">{{ $s->name }}</span>
                                            <span class="menu-line__dots" aria-hidden="true"></span>
                                            <span class="menu-line__price">{{ $s->price()->format() }}</span>
                                        </span>
                                        <span class="menu-line__meta">
                                            <span><x-icon name="clock" /> {{ $s->durationLabel() }}</span>
                                            <span class="menu-line__cta">Ver horários<span class="visually-hidden"> de {{ $s->name }} com {{ $primeiro }}</span> <x-icon name="arrow-right" /></span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </section>
</x-site.page>

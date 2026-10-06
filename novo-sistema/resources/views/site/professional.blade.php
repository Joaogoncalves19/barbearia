{{-- PROFISSIONAL: dados do cadastro; "agendar com" leva direto aos horarios dele. --}}
@php
    $iniciais = \App\Modules\SiteContent\Services\PublicSite::initials($pro->display_name);
    $primeiro = strtok($pro->display_name, ' ');
@endphp
<x-site.page :title="$pro->display_name" :description="($pro->headline ? $pro->headline.'. ' : '').'Agende com '.$pro->display_name.' na '.$cfg->name().'.'" current="team" :og-image="\App\Modules\Shared\Media\ImageStore::url($pro->photo_path)">
    <section class="profile" aria-labelledby="pro-titulo">
        <div class="container profile__grid">
            <div class="profile__media">
                @if ($pro->photo_path)
                    <x-site.img :path="$pro->photo_path" :alt="'Retrato de '.$pro->display_name" sizes="(min-width: 64rem) 34vw, 100vw" eager />
                @else
                    <span class="monogram monogram--lg" aria-hidden="true"><span class="monogram__letters">{{ $iniciais }}</span></span>
                @endif
            </div>
            <div class="stack stack-lg profile__text">
                <div class="stack">
                    <a class="link-arrow back-link" href="{{ route('site.team') }}"><x-icon name="chevron-left" /> Equipe</a>
                    <h1 id="pro-titulo" class="display caps profile__name">{{ $pro->display_name }}</h1>
                    @if ($pro->headline)<p class="eyebrow eyebrow--plain">{{ $pro->headline }}</p>@endif
                    @if ($pro->bio)<p class="lead pre-line">{{ $pro->bio }}</p>@endif
                </div>

                <section class="stack" aria-labelledby="servicos-pro">
                    <h2 id="servicos-pro" class="h3 caps">Agendar com {{ $primeiro }}</h2>
                    @if ($services->isEmpty())
                        <p class="text-muted">No momento, {{ $primeiro }} não tem serviços para agendar pelo site.</p>
                    @else
                        <ul class="service-list" role="list">
                            @foreach ($services as $s)
                                @include('site.partials.service-row', ['s' => $s, 'href' => route('booking.slots', ['service' => $s, 'profissional' => $pro->slug]), 'cta' => 'Ver horários', 'extra' => 'de '.$s->name.' com '.$primeiro])
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </section>
</x-site.page>

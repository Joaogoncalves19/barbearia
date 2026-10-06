{{-- Cartao de profissional: retrato real (4:5) ou monograma; nome, especialidade e "agendar com". --}}
@php
    $iniciais = \App\Modules\SiteContent\Services\PublicSite::initials($pro->display_name);
    $primeiro = strtok($pro->display_name, ' ');
@endphp
<article class="pro-card" data-professional="{{ $pro->slug }}">
    <a class="pro-card__media" href="{{ route('site.professional', $pro) }}" tabindex="-1" aria-hidden="true">
        @if ($pro->photo_path)
            <x-site.img :path="$pro->photo_path" alt="" sizes="(min-width: 64rem) 22vw, 70vw" />
        @else
            <span class="monogram"><span class="monogram__letters">{{ $iniciais }}</span></span>
        @endif
    </a>
    <div class="pro-card__body">
        <h3 class="pro-card__name"><a href="{{ route('site.professional', $pro) }}">{{ $pro->display_name }}</a></h3>
        @if ($pro->headline)<p class="pro-card__role">{{ $pro->headline }}</p>@endif
        <a class="link-arrow" href="{{ route('site.professional', $pro) }}">Agendar com {{ $primeiro }} <x-icon name="arrow-right" /></a>
    </div>
</article>

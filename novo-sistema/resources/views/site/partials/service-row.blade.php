{{--
    Linha de servico (site): nome em condensada, preco em destaque, duracao e
    descricao; a linha inteira leva aos horarios. Foto do servico, se houver.
    $s: Service; $href: destino; $cta: texto da acao; $extra: texto escondido da acao.
--}}
<li class="service-row" data-service="{{ $s->slug }}">
    <a @class(['has-photo' => $s->image_path]) href="{{ $href }}">
        @if ($s->image_path)
            <span class="service-row__photo"><x-site.img :path="$s->image_path" alt="" sizes="7rem" /></span>
        @endif
        <span class="service-row__name">{{ $s->name }}@if (! empty($featured) && $s->is_featured) <span class="tag">Destaque</span>@endif</span>
        <span class="service-row__price figure">{{ $s->price()->format() }}</span>
        <span class="service-row__meta"><x-icon name="clock" class="icon-sm" /> {{ $s->durationLabel() }}</span>
        @if ($s->description)<span class="service-row__desc">{{ $s->description }}</span>@endif
        <span class="service-row__cta">{{ $cta }}<span class="visually-hidden"> {{ $extra ?? $s->name }}</span> <x-icon name="arrow-right" /></span>
    </a>
</li>

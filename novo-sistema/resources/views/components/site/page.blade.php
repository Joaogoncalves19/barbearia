{{--
    Pagina do SITE PUBLICO (Fase 11; site-publico.md). Monta o layout do site
    com a marca, o menu, o rodape e o SEO (titulo, descricao, canonico, Open
    Graph, dados estruturados) a partir do conteudo real (PublicSite).
    current: services | team | plans | about | contact | null
--}}
@props(['title' => null, 'description' => null, 'current' => null, 'ogImage' => null, 'jsonLd' => null, 'canonical' => null])
@php
    /** @var \App\Modules\SiteContent\Services\PublicSite $site */
    $site = app(\App\Modules\SiteContent\Services\PublicSite::class);
    $cfg = $site->settings();
    $marca = \App\Modules\SiteContent\Support\Brand::current();
    $temPlanos = $site->plans()->isNotEmpty();
    $nav = array_values(array_filter([
        ['label' => 'Serviços', 'href' => route('site.services'), 'current' => $current === 'services'],
        $site->team()->isNotEmpty() ? ['label' => 'Equipe', 'href' => route('site.team'), 'current' => $current === 'team'] : null,
        $temPlanos ? ['label' => 'Assinatura', 'href' => route('site.plans'), 'current' => $current === 'plans'] : null,
        ['label' => 'Como chegar', 'href' => route('home').'#local', 'current' => false],
        auth('customer')->check() ? ['label' => 'Minha conta', 'href' => route('account.home')] : ['label' => 'Entrar', 'href' => route('customer.login')],
    ]));
    $descricao = $description ?? ($cfg->has('hero_subtitle') ? $cfg->get('hero_subtitle') : 'Agende seu horário na '.$cfg->name().': serviços, preços, equipe e horários.');
    $imagemOg = $ogImage ?? ($site->image('hero') ?? $site->image('about'))?->url();
    $urlCanonica = $canonical ?? url()->current();
    $tituloOg = $title ? $title.' · '.$cfg->name() : $cfg->name();
@endphp
<x-layouts.site :title="$title" :description="$descricao" :brand="$marca['name']" :logo="$marca['logo']" :site-name="$marca['name']" :nav="$nav"
    :booking-url="route('booking.services')" :whatsapp-url="$cfg->whatsappUrl()" :home-url="route('home')">
    @push('head')
        <link rel="canonical" href="{{ $urlCanonica }}">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
        <meta property="og:type" content="website">
        <meta property="og:locale" content="pt_BR">
        <meta property="og:site_name" content="{{ $cfg->name() }}">
        <meta property="og:title" content="{{ $tituloOg }}">
        <meta property="og:description" content="{{ $descricao }}">
        <meta property="og:url" content="{{ $urlCanonica }}">
        @if ($imagemOg)
            <meta property="og:image" content="{{ $imagemOg }}">
            <meta name="twitter:card" content="summary_large_image">
        @else
            <meta name="twitter:card" content="summary">
        @endif
        @if ($jsonLd)
            <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! $jsonLd !!}</script>
        @endif
    @endpush

    {{ $slot }}

    <x-slot:footer>
        @include('site.partials.footer', ['site' => $site, 'cfg' => $cfg, 'marca' => $marca, 'temPlanos' => $temPlanos])
    </x-slot:footer>
</x-layouts.site>

{{-- Rodape do site publico: so dados reais (o que nao estiver configurado nao aparece). Sem link para o painel. --}}
@php $status = $site->hours()->status(); @endphp
<div class="barber-stripe" aria-hidden="true"></div>
<footer class="site-footer">
    <div class="container site-footer__grid">
        <div class="stack">
            <a class="brand" href="{{ route('home') }}">
                @if ($marca['logo'])
                    <img class="brand__logo" src="{{ $marca['logo']['url'] }}" alt="{{ $marca['name'] }}" loading="lazy" @if ($marca['logo']['width']) width="{{ $marca['logo']['width'] }}" height="{{ $marca['logo']['height'] }}" @endif>
                @else
                    <span class="brand__mark" aria-hidden="true">{{ mb_substr($marca['name'], 0, 1) }}</span>
                    <span class="brand__name">{{ $marca['name'] }}</span>
                @endif
            </a>
            @if ($cfg->has('address'))<p>{{ $cfg->get('address') }}</p>@endif
            <ul class="site-footer__contacts">
                @if ($cfg->phoneHref())<li><a href="{{ $cfg->phoneHref() }}">{{ $cfg->get('phone') }}</a></li>@endif
                @if ($cfg->whatsappUrl())<li><a href="{{ $cfg->whatsappUrl() }}" rel="noopener" target="_blank">WhatsApp<span class="visually-hidden"> (abre em outra aba)</span></a></li>@endif
                @if ($cfg->has('public_email'))<li><a href="mailto:{{ $cfg->get('public_email') }}">{{ $cfg->get('public_email') }}</a></li>@endif
            </ul>
            @if ($status)<p class="site-footer__status">{{ $status['text'] }}</p>@endif
        </div>
        <div>
            <h2 class="eyebrow">Navegar</h2>
            <ul>
                <li><a href="{{ route('site.services') }}">Serviços e preços</a></li>
                @if ($site->team()->isNotEmpty())<li><a href="{{ route('site.team') }}">Equipe</a></li>@endif
                @if ($temPlanos)<li><a href="{{ route('site.plans') }}">Assinatura</a></li>@endif
                <li><a href="{{ route('booking.services') }}">Agendar horário</a></li>
                <li><a href="{{ route('account.home') }}">Minha conta</a></li>
            </ul>
        </div>
        <div>
            <h2 class="eyebrow">Redes e informações</h2>
            <ul>
                @if ($cfg->has('instagram'))<li><a href="{{ $cfg->get('instagram') }}" rel="noopener" target="_blank">Instagram<span class="visually-hidden"> (abre em outra aba)</span></a></li>@endif
                @if ($cfg->has('facebook'))<li><a href="{{ $cfg->get('facebook') }}" rel="noopener" target="_blank">Facebook<span class="visually-hidden"> (abre em outra aba)</span></a></li>@endif
                @if ($cfg->has('privacy_policy'))<li><a href="{{ route('site.privacy') }}">Política de privacidade</a></li>@endif
                @if ($cfg->has('terms'))<li><a href="{{ route('site.terms') }}">Termos de uso</a></li>@endif
            </ul>
        </div>
    </div>
    <div class="container site-footer__legal">
        <p>© {{ now()->year }} {{ $marca['name'] }}@if ($cfg->has('cnpj')) · CNPJ {{ $cfg->get('cnpj') }}@endif</p>
    </div>
</footer>

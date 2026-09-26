<footer class="site-footer">
    <div class="container site-footer__grid">
        <div class="stack">
            <span class="brand">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr($brand, 0, 1) }}</span>
                <span class="brand__name">{{ $brand }}</span>
            </span>
            <p>Rua Exemplo, 123 — Centro · (00) 00000-0000</p>
        </div>
        <div>
            <h2 class="eyebrow">Navegar</h2>
            <ul>
                <li><a href="{{ route('prototypes.services', $q) }}">Serviços e preços</a></li>
                <li><a href="{{ route('prototypes.booking', $q) }}">Agendar horário</a></li>
                <li><a href="#minha-conta">Minha conta</a></li>
            </ul>
        </div>
        <div>
            <h2 class="eyebrow">Redes e legal</h2>
            <ul>
                <li><a href="#instagram-exemplo">Instagram</a></li>
                <li><a href="#termos">Termos de uso</a></li>
                <li><a href="#privacidade">Política de privacidade</a></li>
            </ul>
        </div>
    </div>
</footer>

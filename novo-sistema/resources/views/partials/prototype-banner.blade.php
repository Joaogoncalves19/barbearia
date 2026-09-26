{{-- Faixa obrigatoria em toda pagina de referencia: nada ali e dado real. --}}
<div class="proto-banner" role="note">
    <div class="container cluster">
        <span>Protótipo de referência visual — textos, preços, nomes e fotos são <strong>exemplos</strong>.</span>
        <span>Direção:
            <a href="{{ request()->fullUrlWithQuery(['direcao' => 'a']) }}" @if (($direcao ?? 'a') === 'a') aria-current="true" @endif>A · Ofício</a>
            ·
            <a href="{{ request()->fullUrlWithQuery(['direcao' => 'b']) }}" @if (($direcao ?? 'a') === 'b') aria-current="true" @endif>B · Urbano</a>
            · <a href="{{ route('prototypes.index', ['direcao' => $direcao ?? 'a']) }}">todas as telas</a>
        </span>
    </div>
</div>

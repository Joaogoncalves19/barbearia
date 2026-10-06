{{--
    Ornamentos graficos proprios do site (traco fino, cor do acento), sempre
    decorativos (aria-hidden). Desenhos originais em SVG: tesoura, navalha e
    pente. Nada de imagem externa.
--}}
@props(['name' => 'scissors'])
<svg {{ $attributes->class(['ornament', 'ornament--'.$name]) }} viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('razor')
            {{-- Navalha aberta: cabo, pino e lamina --}}
            <path d="M18 92 L64 46" />
            <path d="M14 96 L60 50 a6 6 0 0 1 8 8 L22 104 a6 6 0 0 1 -8 -8 z" />
            <circle cx="64" cy="54" r="2.5" />
            <path d="M66 52 L104 24 Q110 22 108 30 L74 62 Q70 64 66 60" />
            <path d="M74 50 L100 31" />
            @break
        @case('comb')
            <rect x="16" y="46" width="88" height="14" rx="3" />
            @for ($i = 0; $i < 17; $i++)
                <path d="M{{ 21 + $i * 5 }} 60 L{{ 21 + $i * 5 }} {{ $i < 8 ? 78 : 72 }}" />
            @endfor
            @break
        @default
            {{-- Tesoura aberta: laminas a -30 e -62 graus a partir do eixo (60,60), cabos opostos com aneis --}}
            <path d="M60 60 L107.6 32.5 L104 30 Z" />
            <path d="M60 60 L85.8 11.4 L82 12 Z" />
            <path d="M60 60 L34 75" />
            <path d="M60 60 L45.9 86.5" />
            <circle cx="25.8" cy="79.8" r="9.5" />
            <circle cx="41.5" cy="94.9" r="9.5" />
            <circle cx="60" cy="60" r="2.5" />
    @endswitch
</svg>

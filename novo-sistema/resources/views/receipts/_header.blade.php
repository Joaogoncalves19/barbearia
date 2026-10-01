{{-- Cabecalho comum dos comprovantes: barbearia + titulo + numero/data. --}}
<header class="receipt__header">
    <div>
        <p class="receipt__brand">{{ $business['name'] }}</p>
        @if ($business['address'] || $business['phone'])
            <p class="receipt__meta">{{ collect([$business['address'], $business['phone']])->filter()->implode(' · ') }}</p>
        @endif
    </div>
    <div>
        <p class="receipt__title">{{ $docTitle }}</p>
        <p class="receipt__meta">{{ $docMeta }}</p>
    </div>
</header>

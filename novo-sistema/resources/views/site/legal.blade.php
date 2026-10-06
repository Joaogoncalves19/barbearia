{{-- Pagina legal (politica de privacidade / termos): texto do dono, sempre como texto (nunca HTML). --}}
<x-site.page :title="$heading" :description="$heading.' da '.$cfg->name().'.'">
    <section class="section section--tight">
        <div class="container-narrow stack legal">
            <h1 class="h1">{{ $heading }}</h1>
            @foreach ($blocks as $b)
                @if ($b['type'] === 'h')
                    <h2 class="h3">{{ $b['text'] }}</h2>
                @else
                    <p class="pre-line">{{ $b['text'] }}</p>
                @endif
            @endforeach
        </div>
    </section>
</x-site.page>

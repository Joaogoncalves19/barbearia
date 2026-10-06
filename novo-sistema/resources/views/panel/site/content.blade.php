<x-layouts.staff title="Conteúdo do site">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Conteúdo do site</h1>
            <p class="text-muted">Textos, contatos e páginas legais do site público. O que ficar vazio não aparece no site (nada é inventado). Serviços, preços, equipe e horários vêm dos próprios cadastros.</p>
        </div>
        <div class="cluster">
            <x-ui.button :href="route('panel.site.images')" variant="secondary" icon="image">Imagens do site</x-ui.button>
            <x-ui.button :href="route('home')" variant="secondary" icon="external-link" target="_blank" rel="noopener">Ver o site<span class="visually-hidden"> (abre em outra aba)</span></x-ui.button>
        </div>
    </header>

    <form method="POST" action="{{ route('panel.site.content.update') }}" class="stack stack-lg" novalidate>
        @csrf
        @method('PUT')
        @foreach ([
            'Marca e início' => ['name', 'tagline', 'hero_subtitle', 'neighborhood'],
            'A barbearia' => ['about_title', 'about_text', 'highlights'],
            'Endereço e contato' => ['address', 'address_note', 'maps_url', 'phone', 'whatsapp', 'public_email', 'instagram', 'facebook', 'cnpj'],
            'Páginas legais' => ['privacy_policy', 'terms'],
        ] as $grupo => $campos)
            <x-ui.card :title="$grupo">
                <div class="stack">
                    @foreach ($campos as $campo)
                        @php $def = $fields[$campo]; @endphp
                        @if ($def['type'] === 'textarea')
                            <x-ui.textarea :name="$campo" :label="$def['label']" :value="$settings[$campo] ?? ''" :hint="$def['hint'] ?? 'Até '.$def['max'].' caracteres.'" :rows="$def['max'] > 2000 ? 10 : 4" :maxlength="$def['max']" optional />
                        @else
                            <x-ui.input :name="$campo" :label="$def['label']" :value="$settings[$campo] ?? ''" :hint="$def['hint'] ?? null" :type="match ($def['type']) { 'url' => 'url', 'email' => 'email', 'phone', 'whatsapp' => 'tel', default => 'text' }" :maxlength="$def['max']" optional />
                        @endif
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach
        <div><x-ui.button type="submit" variant="accent">Salvar conteúdo</x-ui.button></div>
    </form>
</x-layouts.staff>

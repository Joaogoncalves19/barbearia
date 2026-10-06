<x-layouts.staff title="Imagens do site">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.site.content') }}">Conteúdo do site</a>
            <h1 class="page-head__title">Imagens do site</h1>
            <p class="text-muted">JPG, PNG ou WebP de até 8 MB. Toda imagem é reprocessada: vira WebP em tamanhos para celular e computador, sem os dados ocultos da foto (como localização). Fotos de profissionais e de serviços ficam nos próprios cadastros.</p>
        </div>
    </header>

    @error('image')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="Enviar imagem">
        <form method="POST" action="{{ route('panel.site.images.store') }}" enctype="multipart/form-data" class="stack" novalidate>
            @csrf
            <x-ui.select name="kind" label="Onde aparece" :options="collect(\App\Modules\SiteContent\Models\SiteImage::KINDS)->map(fn ($d) => $d['label'].' (até '.$d['max'].')')->all()" :value="old('kind', 'hero')" />
            <x-ui.file name="image" label="Imagem" accept="image/jpeg,image/png,image/webp" />
            <x-ui.input name="alt" label="Descrição da imagem" hint="Para quem usa leitor de tela e para buscadores. Ex.: 'Cadeiras de barbeiro em frente ao espelho, com luz quente'." maxlength="160" />
            <x-ui.input name="caption" label="Legenda" maxlength="160" optional />
            <div><x-ui.button type="submit" variant="accent" icon="upload">Enviar</x-ui.button></div>
        </form>
    </x-ui.card>

    @foreach ($groups as $g)
        <x-ui.card :title="$g['def']['label']">
            @if ($g['images']->isEmpty())
                <p class="text-muted text-sm">Nenhuma imagem. @if ($g['kind'] === 'hero')Sem foto, o início usa a versão tipográfica.@elseif ($g['kind'] === 'gallery')A galeria aparece a partir de 3 fotos.@elseif ($g['kind'] === 'logo')Sem logo, aparece o nome da barbearia.@endif</p>
            @else
                <ul class="stack" role="list">
                    @foreach ($g['images'] as $img)
                        <li class="site-image-row" data-site-image="{{ $img->id }}">
                            <img class="site-image-row__thumb" src="{{ $img->url() }}" alt="" width="{{ $img->width }}" height="{{ $img->height }}" loading="lazy">
                            <form method="POST" action="{{ route('panel.site.images.update', $img) }}" class="stack stack-sm">
                                @csrf
                                @method('PUT')
                                <x-ui.input name="alt" :id="'alt-'.$img->id" label="Descrição" :value="$img->alt" maxlength="160" />
                                <x-ui.input name="caption" :id="'legenda-'.$img->id" label="Legenda" :value="$img->caption" maxlength="160" optional />
                                <x-ui.switch name="is_active" :id="'ativa-'.$img->id" label="Aparece no site" :checked="$img->is_active" />
                                <p class="text-sm text-muted">{{ $img->width }} × {{ $img->height }} px</p>
                                <div><x-ui.button type="submit" variant="secondary" size="sm">Salvar<span class="visually-hidden"> imagem {{ $img->id }}</span></x-ui.button></div>
                            </form>
                            <div class="cluster">
                                @foreach (['up' => ['Subir', 'arrow-up'], 'down' => ['Descer', 'arrow-down']] as $dir => [$rotulo, $icone])
                                    <form method="POST" action="{{ route('panel.site.images.move', $img) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="{{ $dir }}">
                                        <x-ui.button type="submit" variant="ghost" size="sm" :icon="$icone">{{ $rotulo }}<span class="visually-hidden"> imagem {{ $img->id }}</span></x-ui.button>
                                    </form>
                                @endforeach
                                <x-ui.button variant="danger" size="sm" icon="trash-2" data-dialog-open="remover-imagem-{{ $img->id }}">Remover<span class="visually-hidden"> imagem {{ $img->id }}</span></x-ui.button>
                            </div>
                            <x-ui.confirm :id="'remover-imagem-'.$img->id" title="Remover esta imagem do site?" :action="route('panel.site.images.destroy', $img)" method="DELETE" confirm-label="Remover">
                                <p>O arquivo é apagado. Para voltar, é preciso enviar de novo.</p>
                            </x-ui.confirm>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endforeach
</x-layouts.staff>

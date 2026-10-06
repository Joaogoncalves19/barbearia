@php
    $novo = ! $professional->exists;
    $podeExibicao = auth('web')->user()->can('professionals.display');
@endphp
<x-layouts.staff :title="$novo ? 'Novo profissional' : 'Editar profissional'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.professionals.index') }}">Voltar para profissionais</a>
            <h1 class="page-head__title">{{ $novo ? 'Novo profissional' : $professional->display_name }}</h1>
        </div>
    </header>

    <form method="POST" action="{{ $novo ? route('panel.professionals.store') : route('panel.professionals.update', $professional) }}" enctype="multipart/form-data" class="stack" novalidate>
        @csrf
        @unless ($novo)
            @method('PUT')
            <input type="hidden" name="version" value="{{ $professional->lock_version }}">
        @endunless
        @error('version')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

        <div class="dashboard-grid">
            <x-ui.card title="Profissional">
                <div class="stack">
                    <x-ui.input name="display_name" label="Nome de exibição" :value="$professional->display_name" hint="Como aparece na agenda, no site e para o cliente." autofocus />
                    <x-ui.select name="user_id" label="Conta de acesso ao sistema" :options="$accounts" :value="$professional->user_id"
                        hint="Opcional. Só para quem precisa entrar no painel. A conta é criada antes, em Usuários." />
                    <input type="hidden" name="is_bookable" value="0">
                    <x-ui.checkbox name="is_bookable" label="Recebe agendamentos" hint="Desmarque para quem está na equipe mas ainda não atende (ex.: em treinamento)." :checked="(bool) $professional->is_bookable" />
                </div>
            </x-ui.card>

            @if ($podeExibicao)
                <x-ui.card title="Apresentação no site">
                    <div class="stack">
                        <x-ui.input name="headline" label="Especialidade" :value="$professional->headline" hint="Uma linha, ex.: Degradê e barba desenhada." optional />
                        <x-ui.textarea name="bio" label="Apresentação" :value="$professional->bio" rows="4" optional />
                        <input type="hidden" name="is_public" value="0">
                        <x-ui.checkbox name="is_public" label="Mostrar no site" :checked="(bool) $professional->is_public" />
                        <input type="hidden" name="is_featured" value="0">
                        <x-ui.checkbox name="is_featured" label="Destacar no site" :checked="(bool) $professional->is_featured" />

                        @if ($professional->photoUrl())
                            <img class="thumb" src="{{ $professional->photoUrl() }}" alt="Foto atual de {{ $professional->display_name }}">
                            <x-ui.checkbox name="remove_photo" label="Remover a foto atual" />
                        @endif
                        <x-ui.file name="photo" :label="$professional->photo_path ? 'Trocar foto' : 'Foto'" accept="image/jpeg,image/png,image/webp" hint="JPG, PNG ou WebP, até 8 MB, mínimo 200 × 200 px. O sistema otimiza a imagem para o site. Prefira foto em retrato." optional />
                    </div>
                </x-ui.card>
            @endif
        </div>

        <div><x-ui.button type="submit">{{ $novo ? 'Cadastrar e escolher serviços' : 'Salvar alterações' }}</x-ui.button></div>
    </form>
</x-layouts.staff>

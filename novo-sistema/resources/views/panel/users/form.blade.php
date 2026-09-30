@php
    $novo = ! $user->exists;
    $podePapel = $novo || ($canChangeRole ?? false);
@endphp
<x-layouts.staff :title="$novo ? 'Novo usuário' : 'Editar usuário'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.users.index') }}">Voltar para usuários</a>
            <h1 class="page-head__title">{{ $novo ? 'Novo usuário' : $user->name }}</h1>
        </div>
    </header>

    <div class="dashboard-grid">
        <x-ui.card :title="$novo ? 'Dados de acesso' : 'Dados e papel'">
            <form method="POST" action="{{ $novo ? route('panel.users.store') : route('panel.users.update', $user) }}" class="stack" novalidate>
                @csrf
                @unless ($novo) @method('PUT') @endunless

                <x-ui.input name="name" label="Nome" autocomplete="off" :value="$user->name" />
                <x-ui.input name="username" label="Usuário (para entrar)" autocomplete="off" autocapitalize="none" spellcheck="false" :value="$user->username" hint="Letras minúsculas, números, ponto, hífen ou sublinhado." />
                <x-ui.input name="email" label="E-mail" type="email" autocomplete="off" :value="$user->email" hint="Também serve para entrar e para recuperar a senha." optional />

                @if ($podePapel)
                    <x-ui.select name="role" label="Papel" :options="$roles" :value="$user->role?->value ?? 'reception'" />
                    @unless ($novo)
                        {{-- Hidden antes do checkbox: desmarcado envia 0 (desativar). --}}
                        <input type="hidden" name="is_active" value="0">
                        <x-ui.checkbox name="is_active" label="Acesso ativo" hint="Desativar derruba as sessões abertas na hora." :checked="(bool) $user->is_active" />
                    @endunless
                @else
                    <x-ui.alert>Você não pode alterar o próprio papel nem desativar a própria conta.</x-ui.alert>
                @endif

                @if ($novo)
                    <x-ui.input name="temporary_password" label="Senha provisória" type="password" autocomplete="new-password" hint="Passe pessoalmente. A pessoa cria a própria senha no primeiro acesso." />
                @endif

                <div><x-ui.button type="submit">{{ $novo ? 'Criar usuário' : 'Salvar' }}</x-ui.button></div>
            </form>
        </x-ui.card>

        @if (! $novo && ($canSetPassword ?? false))
            <x-ui.card title="Senha provisória">
                <form method="POST" action="{{ route('panel.users.temporary-password', $user) }}" class="stack" novalidate>
                    @csrf
                    <p class="text-sm text-muted">Para quem esqueceu a senha e não tem e-mail. As sessões abertas dessa pessoa são encerradas e ela cria uma senha nova no próximo acesso.</p>
                    <x-ui.input name="temporary_password" label="Nova senha provisória" type="password" autocomplete="new-password" id="campo-senha-provisoria" />
                    <div><x-ui.button type="submit" variant="secondary" icon="key-round">Definir senha provisória</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-layouts.staff>

@php
    $novo = ! $service->exists;
    $podePreco = $novo || auth('web')->user()->can('services.price');
    $podeExibicao = auth('web')->user()->can('services.display');
    $precoAtual = $service->price_cents !== null ? \App\Modules\Shared\Support\Money::fromCents($service->price_cents) : null;
@endphp
<x-layouts.staff :title="$novo ? 'Novo serviço' : 'Editar serviço'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.services.index') }}">Voltar para serviços</a>
            <h1 class="page-head__title">{{ $novo ? 'Novo serviço' : $service->name }}</h1>
            @unless ($novo)
                <p class="text-muted">
                    <x-ui.badge :variant="$service->is_active ? 'success' : 'neutral'">{{ $service->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge>
                    Ativar e desativar fica na lista de serviços.
                </p>
            @endunless
        </div>
    </header>

    <form method="POST" action="{{ $novo ? route('panel.services.store') : route('panel.services.update', $service) }}" enctype="multipart/form-data" class="stack" novalidate>
        @csrf
        @unless ($novo)
            @method('PUT')
            <input type="hidden" name="version" value="{{ $service->lock_version }}">
        @endunless
        @error('version')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

        <div class="dashboard-grid">
            <x-ui.card title="Serviço">
                <div class="stack">
                    <x-ui.input name="name" label="Nome" :value="$service->name" autofocus />
                    <x-ui.select name="category_id" label="Categoria" :options="$categories" :value="$service->category_id" placeholder="Escolha a categoria" />
                    <x-ui.select name="duration_minutes" label="Duração" :options="$durations" :value="$service->duration_minutes" hint="Tempo que o serviço ocupa na agenda." />

                    @if ($podePreco)
                        <x-ui.input name="price" :label="$novo ? 'Preço' : 'Preço atual'" inputmode="decimal" :value="$precoAtual?->toInput()"
                            :hint="$novo ? 'Em reais, ex.: 45,00.' : 'Em reais, ex.: 45,00. O novo preço vale para agendamentos novos; os já feitos mantêm o valor registrado.'" />
                    @else
                        <div class="field">
                            <p class="field__label">Preço atual</p>
                            <p>{{ $precoAtual?->format() }} <span class="text-sm text-muted">(alterar o preço exige permissão própria)</span></p>
                        </div>
                    @endif

                    <x-ui.textarea name="description" label="Descrição" :value="$service->description" rows="3" hint="Aparece no site. Diga o que está incluído." optional />
                </div>
            </x-ui.card>

            @if ($podeExibicao)
                <x-ui.card title="Exibição no site">
                    <div class="stack">
                        <input type="hidden" name="is_public" value="0">
                        <x-ui.checkbox name="is_public" label="Mostrar no site" hint="Serviço ativo e fora do site ainda pode ser agendado pela equipe." :checked="(bool) $service->is_public" />
                        <input type="hidden" name="is_featured" value="0">
                        <x-ui.checkbox name="is_featured" label="Destacar no site" :checked="(bool) $service->is_featured" />

                        @if ($service->imageUrl())
                            <img class="thumb" src="{{ $service->imageUrl() }}" alt="Imagem atual de {{ $service->name }}">
                            <x-ui.checkbox name="remove_image" label="Remover a imagem atual" />
                        @endif
                        <x-ui.file name="image" :label="$service->image_path ? 'Trocar imagem' : 'Imagem'" accept="image/jpeg,image/png,image/webp" hint="JPG, PNG ou WebP, até 3 MB, mínimo 200 × 200 px." optional />
                    </div>
                </x-ui.card>
            @endif
        </div>

        <div><x-ui.button type="submit">{{ $novo ? 'Criar serviço' : 'Salvar alterações' }}</x-ui.button></div>
    </form>

    @unless ($novo)
        <x-ui.card title="Histórico de preço">
            @if ($priceHistory->isEmpty())
                <p class="text-sm text-muted">Sem alterações registradas (serviço anterior à trilha de auditoria).</p>
            @else
                <x-ui.table caption="Histórico de preço" caption-hidden stacked>
                    <thead><tr><th scope="col">Quando</th><th scope="col">Quem</th><th scope="col">De</th><th scope="col">Para</th></tr></thead>
                    <tbody>
                        @foreach ($priceHistory as $h)
                            <tr>
                                <td data-label="Quando" class="numeric">{{ $h['at']?->timezone(config('barbearia.display_timezone'))->format('d/m/Y H:i') }}</td>
                                <td data-label="Quem">{{ $h['by'] ?? 'Sistema' }}</td>
                                <td data-label="De" class="numeric">{{ $h['from'] !== null ? \App\Modules\Shared\Support\Money::fromCents($h['from'])->format() : '—' }}</td>
                                <td data-label="Para" class="numeric">{{ \App\Modules\Shared\Support\Money::fromCents($h['to'])->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
            <p class="text-sm text-muted">O valor cobrado em cada agendamento fica gravado nele e não muda com o preço atual.</p>
        </x-ui.card>

        @if ($canDelete ?? false)
            @can('delete', $service)
                <x-ui.card title="Excluir serviço">
                    <p class="text-sm text-muted">Este serviço nunca foi usado em agendamentos, combos ou planos, então pode ser excluído. Serviços já usados só podem ser desativados.</p>
                    <div><x-ui.button variant="danger" icon="trash-2" data-dialog-open="excluir-servico">Excluir serviço</x-ui.button></div>
                    <x-ui.confirm id="excluir-servico" :title="'Excluir '.$service->name.'?'" :action="route('panel.services.destroy', $service)" method="DELETE" confirm-label="Excluir">
                        <p>O serviço sai do catálogo e dos profissionais que o executam. Esta ação não pode ser desfeita pelo painel.</p>
                    </x-ui.confirm>
                </x-ui.card>
            @endcan
        @endif
    @endunless
</x-layouts.staff>

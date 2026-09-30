<x-layouts.staff :title="'Serviços de '.$professional->display_name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.professionals.index') }}">Voltar para profissionais</a>
            <h1 class="page-head__title">Serviços de {{ $professional->display_name }}</h1>
            <p class="text-muted">Marque só o que esta pessoa executa. A agenda vai oferecer cada serviço apenas com quem o faz.</p>
        </div>
    </header>

    @error('services')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
    @unless ($professional->is_active)
        <x-ui.alert variant="warning">{{ $professional->display_name }} está inativo: os serviços ficam guardados, mas só valem para novos agendamentos se for ativado de novo.</x-ui.alert>
    @endunless

    <form method="POST" action="{{ route('panel.professionals.services.update', $professional) }}" class="stack" novalidate>
        @csrf
        @method('PUT')

        @forelse ($groups as $g)
            <x-ui.card>
                <fieldset class="check-group">
                    <legend>{{ $g['category']?->name ?? 'Sem categoria' }}@if ($g['category'] && ! $g['category']->is_active) <span class="text-sm text-muted">(categoria inativa)</span>@endif</legend>
                    <div class="check-grid">
                        @foreach ($g['services'] as $s)
                            <x-ui.checkbox name="services[]" :value="$s->id" :id="'servico-'.$s->id" :label="$s->name"
                                :hint="$s->price()->format().' · '.$s->durationLabel()" :checked="in_array($s->id, old('services', $linked))" />
                        @endforeach
                    </div>
                </fieldset>
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Nenhum serviço ativo" icon="scissors">Cadastre ou ative serviços antes de vinculá-los.</x-ui.empty-state>
        @endforelse

        @if ($inactiveLinked->isNotEmpty())
            <x-ui.alert title="Vínculos guardados com serviços inativos">
                {{ $inactiveLinked->pluck('name')->join(', ', ' e ') }}. Continuam ligados a {{ $professional->display_name }} e voltam a valer se o serviço for reativado.
            </x-ui.alert>
        @endif

        <div><x-ui.button type="submit">Salvar serviços</x-ui.button></div>
    </form>
</x-layouts.staff>

{{--
    Abas acessiveis (setas, Home, End). :tabs = ['id' => 'Rotulo']; conteudo
    em slots nomeados com o mesmo id: <x-slot:hoje>...</x-slot:hoje>
--}}
@props(['tabs' => [], 'label'])
<div class="tabs" x-data="tabs" {{ $attributes }}>
    <div class="tabs__list" role="tablist" aria-label="{{ $label }}">
        @foreach ($tabs as $id => $rotulo)
            <button type="button" class="tabs__tab" role="tab" id="aba-{{ $id }}" aria-controls="painel-{{ $id }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}">{{ $rotulo }}</button>
        @endforeach
    </div>
    @foreach ($tabs as $id => $rotulo)
        <div class="tabs__panel" role="tabpanel" id="painel-{{ $id }}" aria-labelledby="aba-{{ $id }}" tabindex="0" @if (! $loop->first) hidden @endif>
            {{ ${$id} ?? '' }}
        </div>
    @endforeach
</div>

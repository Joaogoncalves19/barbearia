{{--
    Envolve um controle com rotulo, ajuda e erro acessiveis.
    Os componentes input/select/textarea ja usam este campo.
--}}
@props(['id', 'label', 'hint' => null, 'error' => null, 'optional' => false])
<div {{ $attributes->class(['field']) }}>
    <label class="field__label" for="{{ $id }}">
        {{ $label }}
        @if ($optional)<span class="field__optional">(opcional)</span>@endif
    </label>
    {{ $slot }}
    @if ($hint)
        <p class="field__hint" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    @if ($error)
        <p class="field__error" id="{{ $id }}-error"><x-icon name="circle-x" class="icon-sm" /> {{ $error }}</p>
    @endif
</div>

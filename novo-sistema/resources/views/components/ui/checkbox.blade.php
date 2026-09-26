{{-- Checkbox com rotulo clicavel e texto de apoio opcional. --}}
@props(['name', 'label', 'value' => '1', 'checked' => false, 'hint' => null, 'id' => null])
@php $id ??= 'campo-'.str_replace(['[', ']', '.'], '-', $name).'-'.$value; @endphp
<label class="choice" for="{{ $id }}">
    <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes }}>
    <span class="choice__text">
        <span>{{ $label }}</span>
        @if ($hint)<span class="choice__hint">{{ $hint }}</span>@endif
    </span>
</label>

@props(['name', 'label', 'value', 'checked' => false, 'hint' => null, 'id' => null])
@php $id ??= 'campo-'.$name.'-'.$value; @endphp
<label class="choice" for="{{ $id }}">
    <input type="radio" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked(old($name) !== null ? old($name) === (string) $value : $checked) {{ $attributes }}>
    <span class="choice__text">
        <span>{{ $label }}</span>
        @if ($hint)<span class="choice__hint">{{ $hint }}</span>@endif
    </span>
</label>

{{-- Liga/desliga: checkbox nativo com role="switch" (leitores de tela anunciam "ativado/desativado"). --}}
@props(['name', 'label', 'checked' => false, 'id' => null])
@php $id ??= 'campo-'.$name; @endphp
<label class="switch" for="{{ $id }}">
    <input type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="1" @checked(old($name, $checked)) {{ $attributes }}>
    <span>{{ $label }}</span>
</label>

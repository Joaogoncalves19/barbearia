{{-- Selecao simples. :options = [valor => rotulo] --}}
@props(['name', 'label', 'options' => [], 'id' => null, 'value' => null, 'placeholder' => null, 'hint' => null, 'error' => null, 'optional' => false])
@php
    $id ??= 'campo-'.str_replace(['[', ']', '.'], '-', $name);
    $error ??= $errors->first($name) ?: null;
    $selecionado = (string) old($name, $value);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional">
    <select id="{{ $id }}" name="{{ $name }}"
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        @if (! $optional) required @endif
        {{ $attributes->class(['control']) }}>
        @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $valor => $rotulo)
            <option value="{{ $valor }}" @selected($selecionado === (string) $valor)>{{ $rotulo }}</option>
        @endforeach
    </select>
</x-ui.field>

{{--
    Campo de texto. O erro vem do validador (name) ou de :error.
    <x-ui.input name="email" label="E-mail" type="email" autocomplete="email" />
--}}
@props(['name', 'label', 'id' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'error' => null, 'optional' => false])
@php
    $id ??= 'campo-'.str_replace(['[', ']', '.'], '-', $name);
    $error ??= $errors->first($name) ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional">
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ $type === 'password' ? '' : old($name, $value) }}"
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        @if (! $optional) required @endif
        {{ $attributes->class(['control']) }}
    >
</x-ui.field>

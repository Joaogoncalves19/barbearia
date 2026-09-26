@props(['name', 'label', 'id' => null, 'value' => null, 'hint' => null, 'error' => null, 'optional' => false, 'rows' => 4])
@php
    $id ??= 'campo-'.str_replace(['[', ']', '.'], '-', $name);
    $error ??= $errors->first($name) ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        @if (! $optional) required @endif
        {{ $attributes->class(['control']) }}>{{ old($name, $value) }}</textarea>
</x-ui.field>

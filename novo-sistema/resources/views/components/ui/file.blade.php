{{--
    Envio de arquivo (imagem). O formulario precisa de enctype="multipart/form-data".
    <x-ui.file name="photo" label="Foto" accept="image/jpeg,image/png,image/webp" hint="JPG, PNG ou WebP, ate 8 MB." optional />
--}}
@props(['name', 'label', 'id' => null, 'hint' => null, 'error' => null, 'optional' => false, 'accept' => null])
@php
    $id ??= 'campo-'.str_replace(['[', ']', '.'], '-', $name);
    $error ??= $errors->first($name) ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional">
    <input id="{{ $id }}" name="{{ $name }}" type="file"
        @if ($accept) accept="{{ $accept }}" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        {{ $attributes->class(['control', 'control--file']) }}>
</x-ui.field>

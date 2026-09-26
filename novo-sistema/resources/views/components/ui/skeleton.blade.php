{{-- Linhas "fantasma" enquanto o conteudo carrega. --}}
@props(['lines' => 3])
<div {{ $attributes->class(['stack', 'stack-sm']) }} aria-hidden="true">
    @for ($i = 0; $i < $lines; $i++)
        <span class="skeleton"></span>
    @endfor
</div>

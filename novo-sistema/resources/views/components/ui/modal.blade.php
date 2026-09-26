{{--
    Modal com <dialog> nativo. Abra com: <button data-dialog-open="ID">.
    Slot "footer" para as acoes. Fecha com Esc, com o X e clicando fora.
--}}
@props(['id', 'title'])
<dialog id="{{ $id }}" class="dialog" aria-labelledby="{{ $id }}-titulo" {{ $attributes }}>
    <div class="dialog__panel">
        <header class="dialog__header">
            <h2 class="title" id="{{ $id }}-titulo">{{ $title }}</h2>
            <button type="button" class="btn btn--ghost btn--icon btn--sm" data-dialog-close>
                <x-icon name="x" label="Fechar" />
            </button>
        </header>
        <div class="dialog__body">{{ $slot }}</div>
        @isset($footer)
            <footer class="dialog__footer">{{ $footer }}</footer>
        @endisset
    </div>
</dialog>

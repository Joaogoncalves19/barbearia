{{--
    Modal com <dialog> nativo. Abra com: <button data-dialog-open="ID">.
    Slot "footer" para as acoes. Fecha com Esc, com o X e clicando fora.
    Formulario dentro do modal: inclua <input type="hidden" name="_dialog"
    value="ID">; se o envio voltar com erro de validacao, o modal reabre
    sozinho mostrando a mensagem ao lado do campo.
--}}
@props(['id', 'title'])
@php $reabrir = old('_dialog') === $id && $errors->any(); @endphp
<dialog id="{{ $id }}" class="dialog" aria-labelledby="{{ $id }}-titulo" @if ($reabrir) data-dialog-autoopen @endif {{ $attributes }}>
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

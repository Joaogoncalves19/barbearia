{{--
    Confirmacao de acao destrutiva: o formulario so e enviado pelo botao de
    confirmar dentro do modal (POST + CSRF). Nunca uma acao por link GET.
--}}
@props(['id', 'title', 'action' => null, 'method' => 'POST', 'confirmLabel' => 'Confirmar', 'danger' => true, 'fields' => []])
<x-ui.modal :id="$id" :title="$title">
    {{ $slot }}
    <x-slot:footer>
        <button type="button" class="btn btn--secondary" data-dialog-close>Cancelar</button>
        @if ($action)
            <form method="POST" action="{{ $action }}">
                @csrf
                @if (strtoupper($method) !== 'POST') @method($method) @endif
                @foreach ($fields as $campo => $valor)
                    <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
                @endforeach
                <button type="submit" class="btn {{ $danger ? 'btn--danger' : '' }}">{{ $confirmLabel }}</button>
            </form>
        @else
            {{-- Sem action: modo demonstracao (paginas de referencia). --}}
            <button type="button" class="btn {{ $danger ? 'btn--danger' : '' }}" data-dialog-close>{{ $confirmLabel }}</button>
        @endif
    </x-slot:footer>
</x-ui.modal>

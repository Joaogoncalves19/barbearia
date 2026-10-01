{{--
    Pagina de impressao de um comprovante (Fase 8). A barra de acoes (voltar,
    imprimir, enviar por e-mail) some na impressao; a folha e a mesma view
    usada no e-mail ($partial).
--}}
@php use Illuminate\Support\Str; @endphp
<x-layouts.document :title="$type->label()" surface="clara" area="panel" body-class="receipt-page" :noindex="true">
    <main id="conteudo">
        <div class="receipt-toolbar">
            <a class="link-arrow text-sm" href="{{ $back }}">Voltar</a>
            <div class="receipt-toolbar__actions">
                <button type="button" class="btn" data-print>Imprimir</button>
                @if ($emailUrl)
                    <form method="POST" action="{{ $emailUrl }}" class="cluster" novalidate>
                        @csrf
                        <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                        @if ($emailLocked)
                            <input type="hidden" name="email" value="{{ $emailDefault }}">
                            <span class="text-sm">Enviar para {{ $emailDefault }}</span>
                        @else
                            <x-ui.input name="email" type="email" label="Enviar por e-mail para" :value="$emailDefault" autocomplete="off" />
                        @endif
                        <button type="submit" class="btn btn--secondary">Enviar por e-mail</button>
                    </form>
                @endif
            </div>
        </div>
        @if (session('status'))<div class="receipt-toolbar"><x-ui.alert variant="success" role="status">{{ session('status') }}</x-ui.alert></div>@endif
        @error('email')<div class="receipt-toolbar"><x-ui.alert variant="danger">{{ $message }}</x-ui.alert></div>@enderror

        @include($partial)
    </main>
</x-layouts.document>

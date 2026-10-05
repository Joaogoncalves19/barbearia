{{-- Comprovante por e-mail (Fase 8) no layout da Fase 10: a mesma parte da impressao. --}}
@extends('mail.communication.layout')

@section('content')
    <style>
        .receipt__header { border-bottom: 1px solid #ddd5c8; padding-bottom: 10px; margin-bottom: 14px; }
        .receipt__brand { font-size: 20px; margin: 0; font-family: Georgia, serif; }
        .receipt__title { font-weight: bold; margin: 6px 0 0; }
        .receipt__meta, .receipt__foot { color: #6a6258; margin: 0; }
        .receipt__foot { font-size: 12px; margin-top: 14px; }
        .receipt table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .receipt th, .receipt td { text-align: left; padding: 6px 4px; border-bottom: 1px solid #ddd5c8; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .receipt__total td { font-weight: bold; border-bottom: 2px solid #121110; }
        .receipt__code { font-family: Consolas, monospace; font-size: 22px; letter-spacing: 2px; text-align: center; padding: 12px; border: 2px dashed #8c8377; border-radius: 6px; }
        .receipt__sign { display: none; }
    </style>
    @include($partial)
    <p class="muted">Você pode imprimir este e-mail.</p>
@endsection

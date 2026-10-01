{{-- E-mail do comprovante: a mesma view da impressao, com estilo embutido simples. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; padding: 16px; background: #f8f5ef; color: #121110; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; }
        .receipt { max-width: 640px; margin: 0 auto; background: #fffdf9; border: 1px solid #ddd5c8; border-radius: 8px; padding: 20px; }
        .receipt__header { border-bottom: 1px solid #ddd5c8; padding-bottom: 10px; margin-bottom: 14px; }
        .receipt__brand { font-size: 20px; margin: 0; font-family: Georgia, serif; }
        .receipt__title { font-weight: bold; margin: 6px 0 0; }
        .receipt__meta, .receipt__foot { color: #6a6258; margin: 0; }
        .receipt__foot { font-size: 12px; margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { text-align: left; padding: 6px 4px; border-bottom: 1px solid #ddd5c8; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .receipt__total td { font-weight: bold; border-bottom: 2px solid #121110; }
        .receipt__code { font-family: Consolas, monospace; font-size: 22px; letter-spacing: 2px; text-align: center; padding: 12px; border: 2px dashed #8c8377; border-radius: 6px; }
        .receipt__sign { display: none; }
    </style>
</head>
<body>
    @include($partial)
    <p style="text-align:center;color:#6a6258;font-size:12px;">Enviado por {{ $business['name'] }}. Você pode imprimir este e-mail.</p>
</body>
</html>

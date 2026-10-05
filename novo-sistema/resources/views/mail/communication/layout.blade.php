{{--
    Layout dos e-mails do dominio (templates.md): identidade visual (direcao A:
    tinta, papel e cobre), estilo embutido (leitores de e-mail nao carregam CSS),
    largura maxima de 600 px, texto legivel sem imagens. Todo conteudo vem
    escapado ({{ }}); nada de HTML vindo de cliente ou da equipe.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subjectLine }}</title>
    <style>
        body { margin: 0; padding: 0; background: #f8f5ef; color: #121110; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.55; }
        .wrap { max-width: 600px; margin: 0 auto; padding: 16px; }
        .brand { background: #121110; color: #fffdf9; padding: 18px 20px; border-radius: 8px 8px 0 0; font-family: Georgia, 'Times New Roman', serif; font-size: 22px; letter-spacing: .5px; }
        .brand span { color: #e2b37d; }
        .card { background: #fffdf9; border: 1px solid #ddd5c8; border-top: 0; border-radius: 0 0 8px 8px; padding: 22px 20px; }
        h1 { font-family: Georgia, 'Times New Roman', serif; font-size: 22px; line-height: 1.25; margin: 0 0 12px; color: #121110; }
        p { margin: 0 0 12px; }
        .details { width: 100%; border-collapse: collapse; margin: 6px 0 16px; }
        .details th { text-align: left; color: #6a6258; font-weight: normal; padding: 6px 8px 6px 0; vertical-align: top; width: 38%; border-bottom: 1px solid #efe9df; }
        .details td { padding: 6px 0; vertical-align: top; border-bottom: 1px solid #efe9df; }
        .btn { display: inline-block; background: #bd8246; color: #121110 !important; text-decoration: none; font-weight: bold; padding: 12px 20px; border-radius: 6px; margin: 4px 0 14px; }
        .muted { color: #6a6258; font-size: 13px; }
        .foot { color: #6a6258; font-size: 12px; text-align: center; padding: 14px 8px; }
        .foot a { color: #80532a; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">{{ $business['name'] }} <span>·</span></div>
        <div class="card">
            @yield('content')
        </div>
        <div class="foot">
            {{ $business['name'] }}@if (! empty($business['address'])) · {{ $business['address'] }}@endif @if (! empty($business['phone'])) · {{ $business['phone'] }}@endif
            @if ($unsubscribeUrl)
                <br>Você recebe este e-mail porque aceitou receber novidades. <a href="{{ $unsubscribeUrl }}">Não quero mais receber</a>.
            @else
                <br>E-mail sobre o seu atendimento: não precisa responder.
            @endif
        </div>
    </div>
</body>
</html>

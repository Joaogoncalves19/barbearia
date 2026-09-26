{{--
    Tabela responsiva. stacked=true: no celular cada linha vira um cartao
    (as <td> precisam de data-label). caption e obrigatorio (acessibilidade);
    use visually-hidden no caption se o titulo ja estiver visivel.
--}}
@props(['caption', 'stacked' => false, 'captionHidden' => false])
<div class="table-wrap">
    <table {{ $attributes->class(['table', 'table--stacked' => $stacked]) }}>
        <caption @class(['visually-hidden' => $captionHidden])>{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>

<?php
// barbearia/lib/seo_functions.php
// Dados estruturados (JSON-LD schema.org) da landing page.

/**
 * Valores que carregarConfigGeral() usa como placeholder quando a barbearia
 * ainda nao preencheu a configuracao. Nunca devem ir para o JSON-LD: publicar
 * "Rua Exemplo, 123" ou "(00) 00000-0000" como dado estruturado faz o Google
 * indexar endereco e telefone falsos do negocio.
 */
function seoValorEhPlaceholder($campo, $valor) {
    $placeholders = [
        'nome_barbearia'   => 'Sua Barbearia',
        'telefone_contato' => '(00) 00000-0000',
        'endereco'         => 'Rua Exemplo, 123 - Centro, Sua Cidade',
    ];
    $valor = trim((string) $valor);
    if ($valor === '') {
        return true;
    }
    return isset($placeholders[$campo]) && $valor === $placeholders[$campo];
}

/**
 * Converte o dia numerico do banco (0=domingo ... 6=sabado) para o vocabulario
 * do schema.org.
 */
function seoDiaSemanaSchema($dia) {
    $mapa = [
        0 => 'https://schema.org/Sunday',
        1 => 'https://schema.org/Monday',
        2 => 'https://schema.org/Tuesday',
        3 => 'https://schema.org/Wednesday',
        4 => 'https://schema.org/Thursday',
        5 => 'https://schema.org/Friday',
        6 => 'https://schema.org/Saturday',
    ];
    return isset($mapa[(int) $dia]) ? $mapa[(int) $dia] : null;
}

/**
 * Monta o JSON-LD do tipo HairSalon (subtipo de LocalBusiness no schema.org --
 * nao existe "BarberShop" no vocabulario oficial).
 *
 * Regra que guia a funcao inteira: so entra no JSON-LD o que esta realmente
 * configurado e visivel na pagina. Campo com placeholder, lista vazia ou zero
 * avaliacoes e OMITIDO -- dado estruturado que nao corresponde ao conteudo da
 * pagina e motivo de penalizacao, nao de destaque.
 *
 * @return array|null O array pronto para json_encode, ou null se nem o nome do
 *                    negocio estiver configurado (ai nao ha o que declarar).
 */
function seoDadosEstruturados(array $dados) {
    $config    = isset($dados['config']) ? $dados['config'] : [];
    $baseUrl   = rtrim((string) (isset($dados['baseUrl']) ? $dados['baseUrl'] : ''), '/');
    $descricao = trim((string) (isset($dados['descricao']) ? $dados['descricao'] : ''));
    $horarios  = isset($dados['horarios']) ? $dados['horarios'] : [];
    $servicos  = isset($dados['servicos']) ? $dados['servicos'] : [];
    $totalAval = (int) (isset($dados['totalAvaliacoes']) ? $dados['totalAvaliacoes'] : 0);
    $mediaAval = (float) (isset($dados['mediaAvaliacoes']) ? $dados['mediaAvaliacoes'] : 0);

    $nome = trim((string) (isset($config['nome_barbearia']) ? $config['nome_barbearia'] : ''));
    if (seoValorEhPlaceholder('nome_barbearia', $nome)) {
        return null;
    }

    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => 'HairSalon',
        'name'     => $nome,
    ];

    if ($baseUrl !== '') {
        $ld['@id'] = $baseUrl . '/#barbearia';
        $ld['url'] = $baseUrl . '/';
        $logo = ltrim((string) (isset($config['logo_path']) ? $config['logo_path'] : ''), '/');
        if ($logo !== '') {
            $ld['image'] = $baseUrl . '/' . $logo;
            $ld['logo']  = $baseUrl . '/' . $logo;
        }
    }

    if ($descricao !== '') {
        $ld['description'] = $descricao;
    }

    $telefone = (string) (isset($config['telefone_contato']) ? $config['telefone_contato'] : '');
    if (!seoValorEhPlaceholder('telefone_contato', $telefone)) {
        $ld['telephone'] = trim($telefone);
    }

    $endereco = (string) (isset($config['endereco']) ? $config['endereco'] : '');
    if (!seoValorEhPlaceholder('endereco', $endereco)) {
        // O endereco e um campo de texto livre no painel, sem separacao de
        // rua/cidade/CEP. Declaramos como streetAddress em vez de inventar a
        // divisao -- o Google aceita PostalAddress parcial.
        $ld['address'] = [
            '@type'          => 'PostalAddress',
            'streetAddress'  => trim($endereco),
            'addressCountry' => 'BR',
        ];
    }

    $lat = trim((string) (isset($config['geofence_lat']) ? $config['geofence_lat'] : ''));
    $lon = trim((string) (isset($config['geofence_lon']) ? $config['geofence_lon'] : ''));
    if ($lat !== '' && $lon !== '' && is_numeric($lat) && is_numeric($lon)) {
        $ld['geo'] = [
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float) $lat,
            'longitude' => (float) $lon,
        ];
    }

    $redes = [];
    foreach (['link_instagram', 'link_facebook'] as $rede) {
        $url = trim((string) (isset($config[$rede]) ? $config[$rede] : ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $redes[] = $url;
        }
    }
    if ($redes) {
        $ld['sameAs'] = $redes;
    }

    // Horarios: ja vem consolidados por dia (menor inicio / maior fim entre os
    // profissionais ativos), que e exatamente o que a pagina exibe.
    $aberturas = [];
    foreach ($horarios as $dia => $faixa) {
        $diaSchema = seoDiaSemanaSchema($dia);
        $inicio = trim((string) (isset($faixa['inicio']) ? $faixa['inicio'] : ''));
        $fim    = trim((string) (isset($faixa['fim']) ? $faixa['fim'] : ''));
        if ($diaSchema && $inicio !== '' && $fim !== '') {
            $aberturas[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => $diaSchema,
                'opens'     => $inicio,
                'closes'    => $fim,
            ];
        }
    }
    if ($aberturas) {
        $ld['openingHoursSpecification'] = $aberturas;
    }

    // Faixa de preco a partir dos servicos cadastrados.
    $valores = [];
    foreach ($servicos as $servico) {
        $v = (float) (isset($servico['valor']) ? $servico['valor'] : 0);
        if ($v > 0) {
            $valores[] = $v;
        }
    }
    if ($valores) {
        $ld['priceRange'] = 'R$ ' . number_format(min($valores), 2, ',', '.')
                          . ' - R$ ' . number_format(max($valores), 2, ',', '.');
        $ld['currenciesAccepted'] = 'BRL';
    }

    // aggregateRating so quando existem avaliacoes REAIS, e com os mesmos
    // numeros que a faixa de prova social mostra na pagina.
    if ($totalAval > 0 && $mediaAval > 0) {
        $ld['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format($mediaAval, 1, '.', ''),
            'reviewCount' => $totalAval,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
    }

    if ($baseUrl !== '') {
        $ld['potentialAction'] = [
            '@type'  => 'ReserveAction',
            'target' => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => $baseUrl . '/agendamento',
                'inLanguage'  => 'pt-BR',
            ],
            'result' => ['@type' => 'Reservation', 'name' => 'Agendamento'],
        ];
    }

    return $ld;
}

/**
 * Renderiza a tag de JSON-LD pronta para o <head>.
 * JSON_HEX_TAG impede que um fechamento de script vindo de um campo do painel
 * feche a tag e vire injecao de HTML.
 *
 * @return string String vazia quando nao ha dados suficientes.
 */
function seoRenderizarJsonLd(array $dados) {
    $ld = seoDadosEstruturados($dados);
    if ($ld === null) {
        return '';
    }
    $json = json_encode(
        $ld,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT
    );
    if ($json === false) {
        return '';
    }
    return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</' . 'script>';
}

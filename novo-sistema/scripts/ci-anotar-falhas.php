<?php

/*
 * CI: transforma as falhas do relatorio JUnit do PHPUnit em anotacoes do
 * GitHub Actions (::error), visiveis no resumo da execucao. Nao muda o
 * resultado dos testes: so torna a falha legivel.
 *
 *     php scripts/ci-anotar-falhas.php storage/logs/junit.xml
 */

$arquivo = $argv[1] ?? '';
if ($arquivo === '' || ! is_file($arquivo)) {
    fwrite(STDERR, "Relatório JUnit não encontrado: {$arquivo}\n");
    exit(0);
}

$xml = simplexml_load_file($arquivo);
if ($xml === false) {
    exit(0);
}

$escapar = fn (string $s) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);
$propriedade = fn (string $s) => str_replace([':', ','], ['%3A', '%2C'], $escapar($s));
$total = 0;
foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $caso) {
    $problema = $caso->failure ?? $caso->error;
    $titulo = (string) $caso['class'].'::'.(string) $caso['name'];
    $texto = mb_substr(trim((string) $problema), 0, 3000);
    $arquivoTeste = 'novo-sistema/'.ltrim(str_replace(getcwd(), '', (string) $caso['file']), '/\\');
    $linha = (int) $caso['line'];
    echo '::error file='.$propriedade($arquivoTeste).',line='.$linha.',title='.$propriedade($titulo).'::'.$escapar($texto)."\n";
    if (++$total >= 10) {
        break;
    }
}

<?php

namespace Tests\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Roda a suite de regressao das 4 vulnerabilidades criticas corrigidas no
 * SISTEMA ANTIGO (../tests/seguranca_fase1.php) e falha se ela falhar.
 * Suite separada (--testsuite=Legado): sobe servidor e navegador, leva ~10 s.
 */
class LegacySecurityFixesTest extends TestCase
{
    public function test_correcoes_s01_a_s04_do_sistema_atual_continuam_valendo(): void
    {
        $script = dirname(__DIR__, 3).'/tests/seguranca_fase1.php';
        if (! is_file($script)) {
            $this->markTestSkipped('Sistema antigo nao encontrado ao lado de novo-sistema/.');
        }

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $saida, $codigo);
        $texto = implode("\n", $saida);

        if ($codigo === 2) {
            $this->markTestIncomplete("Parte de navegador pulada (sem Node/Playwright):\n".$texto);
        }

        $this->assertSame(0, $codigo, $texto);
    }
}

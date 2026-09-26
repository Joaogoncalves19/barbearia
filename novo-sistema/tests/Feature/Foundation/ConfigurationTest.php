<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;

/**
 * Garante que o ambiente e configurado por variaveis de ambiente e que nenhum
 * segredo foi escrito no codigo.
 */
class ConfigurationTest extends TestCase
{
    public function test_ambiente_de_testes_esta_isolado(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('array', config('mail.default'), 'testes nunca enviam e-mail de verdade');
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('UTC', config('app.timezone'), 'banco grava em UTC');
        $this->assertSame('America/Sao_Paulo', config('barbearia.display_timezone'));
        $this->assertSame('pt_BR', config('app.locale'));
    }

    public function test_env_example_documenta_as_variaveis_e_nao_traz_segredos(): void
    {
        $conteudo = file_get_contents(base_path('.env.example'));
        $variaveis = [];
        foreach (preg_split('/\R/', $conteudo) as $linha) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($linha), $m)) {
                $variaveis[$m[1]] = trim($m[2], '"');
            }
        }

        foreach (['APP_KEY', 'APP_URL', 'DB_CONNECTION', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'BARBEARIA_PROTOTYPES', 'SESSION_SECURE_COOKIE'] as $obrigatoria) {
            $this->assertArrayHasKey($obrigatoria, $variaveis, "{$obrigatoria} ausente no .env.example");
        }

        foreach (['APP_KEY', 'MAIL_PASSWORD', 'MAIL_USERNAME', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_KEY'] as $segredo) {
            $this->assertSame('', $variaveis[$segredo] ?? '', "{$segredo} deve estar vazio no .env.example");
        }
    }

    public function test_arquivos_env_reais_nao_sao_versionados(): void
    {
        $ignorados = file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('.env', $ignorados);
        $this->assertStringContainsString('.env.production', $ignorados);
    }

    public function test_nenhum_segredo_escrito_no_codigo(): void
    {
        $padroes = [
            '/sk_(live|test)_[0-9a-zA-Z]{10,}/' => 'chave secreta do Stripe',
            '/whsec_[0-9a-zA-Z]{10,}/' => 'segredo de webhook do Stripe',
            '/gsk_[0-9a-zA-Z]{20,}/' => 'chave da Groq',
            '/AIza[0-9A-Za-z_\-]{30,}/' => 'chave do Google',
            '/-----BEGIN (RSA |EC )?PRIVATE KEY-----/' => 'chave privada',
            '/base64:[A-Za-z0-9+\/]{40,}={0,2}/' => 'APP_KEY',
            '/(password|senha|secret|token)\s*[=:]>?\s*[\'"][^\'"\s$]{8,}[\'"]/i' => 'credencial literal',
        ];

        $achados = [];
        foreach (['app', 'config', 'routes', 'database', 'resources/views', 'resources/js', 'bootstrap/app.php'] as $alvo) {
            $caminho = base_path($alvo);
            $arquivos = is_dir($caminho)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($caminho, \FilesystemIterator::SKIP_DOTS))
                : [new \SplFileInfo($caminho)];
            foreach ($arquivos as $arquivo) {
                if (! preg_match('/\.(php|js)$/', $arquivo->getFilename())) {
                    continue;
                }
                $texto = file_get_contents($arquivo->getPathname());
                foreach ($padroes as $regex => $tipo) {
                    if (preg_match($regex, $texto, $m)) {
                        $achados[] = "{$tipo} em {$alvo}: ".substr($arquivo->getPathname(), strlen(base_path()) + 1);
                    }
                }
            }
        }

        $this->assertSame([], $achados);
    }

    public function test_arquivos_privados_nao_sao_servidos_por_url(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }
}

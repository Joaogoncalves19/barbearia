// Testes de navegador das telas de referencia, do design system e das telas de
// acesso (Fase 3).
// Local: `npm run test:e2e` (sobe o servidor sozinho se nao houver um rodando).
import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';

const porta = process.env.E2E_PORT || '8125';
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${porta}`;

// Senha das contas ficticias de teste: aleatoria a cada execucao (nunca fixa no
// codigo). Definida uma vez no processo principal; os workers herdam o ambiente.
process.env.E2E_PASSWORD ||= `${randomBytes(18).toString('base64url')}a1`;

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './storage/e2e/resultados',
    globalSetup: './tests/e2e/global-setup.js',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // O servidor embutido do PHP atende uma requisicao por vez (no Windows sempre):
    // com os testes em paralelo as paginas demoram mais que o padrao de 5 s.
    expect: { timeout: 15_000 },
    reporter: [['list']],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
    },
    projects: [
        { name: 'celular', use: { ...devices['Pixel 7'] } },
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } } },
    ],
    webServer: process.env.E2E_BASE_URL
        ? undefined
        : {
              command: `php artisan serve --host=127.0.0.1 --port=${porta}`,
              url: `${baseURL}/up`,
              reuseExistingServer: !process.env.CI,
              // APP_URL igual ao endereco do servidor: as URLs de assets saem com a mesma
              // origem da pagina e a CSP ('self') aceita fontes e scripts.
              env: { BARBEARIA_PROTOTYPES: 'true', APP_URL: baseURL },
          },
});

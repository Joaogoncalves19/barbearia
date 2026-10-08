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
    // O servidor embutido do PHP atende UMA requisicao por vez: mais que 4
    // navegadores em paralelo so formam fila e estouram o tempo dos testes
    // longos (visto na Fase 6, com 6 workers; e na Fase 12, com 4, quando a suite
    // passou de 120 testes: os mais longos de caixa e catalogo estouravam 90 s so na
    // suite inteira e passavam sozinhos). No CI o runner ja usa menos.
    workers: process.env.CI ? undefined : 3,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // O servidor embutido do PHP atende uma requisicao por vez (no Windows sempre):
    // com os testes em paralelo as paginas demoram mais que o padrao de 5 s.
    expect: { timeout: 15_000 },
    // No CI as falhas tambem viram anotacoes da execucao (o log do job exige login).
    reporter: process.env.CI ? [['list'], ['github']] : [['list']],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
    },
    projects: [
        { name: 'celular', use: { ...devices['Pixel 7'] }, testIgnore: /temas\.spec\.js/ },
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } }, testIgnore: /temas\.spec\.js/ },
        // O tema visual e GLOBAL: os testes de tema rodam sozinhos, depois dos outros
        // (trocar o tema no meio de outro teste mudaria a tela que ele esta vendo).
        { name: 'temas', testMatch: /temas\.spec\.js/, dependencies: ['celular', 'desktop'], fullyParallel: false },
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

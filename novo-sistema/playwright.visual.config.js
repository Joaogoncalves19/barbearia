// Capturas de tela para a revisao visual (redesign): antes/depois das telas
// principais, no desktop e no celular, sobre o banco de DEMONSTRACAO
// (php artisan app:demo-data, dados ficticios). Nao faz parte da suite de
// testes: `npx playwright test -c playwright.visual.config.js` com
// CAPTURA=antes|depois, DEMO_PASSWORD e DB_DATABASE apontando para o banco de
// demonstracao. As imagens vao para docs/reconstrucao/img/redesign/.
import { defineConfig, devices } from '@playwright/test';

const porta = process.env.E2E_PORT || '8126';
const baseURL = `http://127.0.0.1:${porta}`;

export default defineConfig({
    testDir: './tests/visual',
    outputDir: './storage/e2e/visual',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 600_000,
    expect: { timeout: 15_000 },
    reporter: [['list']],
    use: { baseURL, launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {} },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
        { name: 'celular', use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${porta}`,
        url: `${baseURL}/up`,
        reuseExistingServer: false,
        env: { APP_URL: baseURL },
    },
});

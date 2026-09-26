// Testes de navegador das telas de referencia e do design system.
// Local: `npm run test:e2e` (sobe o servidor sozinho se nao houver um rodando).
import { defineConfig, devices } from '@playwright/test';

const porta = process.env.E2E_PORT || '8125';
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${porta}`;

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './storage/e2e/resultados',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: 0,
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
              env: { BARBEARIA_PROTOTYPES: 'true' },
          },
});

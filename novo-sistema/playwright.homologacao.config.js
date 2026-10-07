// Fase 13 — roteiro de HOMOLOGACAO no navegador (tests/homologacao). Roda
// contra uma instalacao ja no ar (feita pelo pacote, dados migrados); nao sobe
// servidor. Senhas so por variavel de ambiente (nunca no codigo):
//   HOMOLOG_URL=http://127.0.0.1:8302 HOMOLOG_SENHA_PROVISORIA=... \
//   HOMOLOG_SENHA_ADMIN_ANTIGA=... HOMOLOG_SENHA_CONTAS_ANTIGAS=... \
//   npx playwright test -c playwright.homologacao.config.js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/homologacao',
    outputDir: './storage/e2e/resultados-homologacao',
    workers: 1,
    retries: 0,
    timeout: 120_000,
    expect: { timeout: 15_000 },
    reporter: [['list']],
    use: { baseURL: process.env.HOMOLOG_URL || 'http://127.0.0.1:8302', trace: 'retain-on-failure' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } } },
        { name: 'celular', use: { ...devices['Pixel 7'] }, dependencies: ['desktop'] },
    ],
});

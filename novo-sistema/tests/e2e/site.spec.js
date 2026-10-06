// Fase 11 — site público no navegador (celular e desktop). Só dados fictícios
// do ambiente de teste. Início, serviços, equipe, página do profissional e o
// caminho real até os horários (o mesmo agendamento da Fase 5). Em cada tela:
// axe, sem erro de console/CSP, sem rolagem lateral; teclado e SEO básicos.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

function observarErros(page) {
    const erros = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error' || /Content Security Policy/i.test(msg.text())) erros.push(msg.text());
    });
    page.on('pageerror', (e) => erros.push(String(e)));
    return erros;
}

async function verificarTela(page, info, nome) {
    const larguraExtra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(larguraExtra, `${nome}: página não deve rolar na horizontal`).toBeLessThanOrEqual(1);

    const axe = await new AxeBuilder({ page }).analyze();
    const graves = axe.violations
        .filter((v) => ['serious', 'critical'].includes(v.impact))
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length}x) ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(graves, `${nome}: violações de acessibilidade graves`).toEqual([]);

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase11-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

const barbeiro = (info) => (info.project.name === 'celular' ? 'Barbeiro A celular' : 'Barbeiro B desktop');

test('início: marca, serviços, equipe, horários e agendar sempre à mão', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /.+/);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /^https?:\/\/[^/]+\/?$/); // raiz do site
    const ld = JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());
    expect(ld['@type']).toBe('BarberShop');
    expect(ld.aggregateRating).toBeUndefined();

    await expect(page.locator('[data-service="corte-e2e"]').first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Horário de funcionamento' })).toBeVisible();
    await expect(page.getByText('Clientes Satisfeitos')).toHaveCount(0); // nada de número inventado

    if (info.project.name === 'celular') {
        await expect(page.locator('.bottom-bar').getByRole('link', { name: 'Agendar horário' })).toBeVisible();
    } else {
        await expect(page.locator('.site-header').getByRole('link', { name: 'Agendar horário' })).toBeVisible();
    }
    await verificarTela(page, info, 'Início');

    // Teclado: o primeiro Tab leva ao "Pular para o conteúdo", com foco visível.
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Pular para o conteúdo' })).toBeFocused();

    await page.getByRole('link', { name: 'Ver serviços e preços' }).click();
    await expect(page).toHaveURL(/\/servicos$/);
    await expect(page.getByRole('heading', { name: 'Serviços e preços', level: 1 })).toBeVisible();
    await expect(page.locator('[data-service="corte-e2e"]')).toContainText('R$ 50,00');
    await verificarTela(page, info, 'Serviços');

    await page.locator('[data-service="corte-e2e"] a').click();
    await expect(page).toHaveURL(/\/agendar\/corte-e2e$/);
    await expect(page.getByRole('link', { name: barbeiro(info) })).toBeVisible();

    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('equipe: página do profissional leva direto aos horários dele', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    await page.goto('/equipe');
    await expect(page.getByRole('heading', { name: 'Quem cuida de você', level: 1 })).toBeVisible();
    await verificarTela(page, info, 'Equipe');

    await page.getByRole('heading', { name: barbeiro(info) }).getByRole('link').click();
    await expect(page.getByRole('heading', { name: barbeiro(info), level: 1 })).toBeVisible();
    await verificarTela(page, info, 'Profissional');

    await page.locator('[data-service="corte-e2e"] a').click();
    await expect(page).toHaveURL(/\/agendar\/corte-e2e\/horarios\?profissional=/);
    await expect(page.getByRole('heading', { name: /horário/i }).first()).toBeVisible();

    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('sitemap e robots', async ({ request }) => {
    const mapa = await request.get('/sitemap.xml');
    expect(mapa.ok()).toBeTruthy();
    const xml = await mapa.text();
    expect(xml).toContain('<urlset');
    expect(xml).toContain('/servicos</loc>');
    expect(xml).not.toContain('/painel');

    const robots = await request.get('/robots.txt');
    expect(robots.ok()).toBeTruthy();
    expect(await robots.text()).toContain('User-agent: *');
});

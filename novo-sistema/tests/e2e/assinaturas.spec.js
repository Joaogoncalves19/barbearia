// Fase 9 — assinaturas no navegador (celular e desktop). Sem Stripe neste
// ambiente (nenhuma chave): a adesão online não aparece e nada sai da máquina.
// Dono: cria um plano (com erro de validação) e consulta assinaturas e
// eventos. Cliente assinante (assinatura manual fictícia ativa): agenda o
// serviço incluído e vê R$ 0,00 na confirmação e no agendamento; vê a própria
// assinatura. Em cada tela: axe, sem erro de console/CSP e sem rolagem lateral.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SENHA = process.env.E2E_PASSWORD;

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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase9-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

const barbeiro = (info) => (info.project.name === 'celular' ? 'Barbeiro A celular' : 'Barbeiro B desktop');

test('dono: cria plano e consulta assinaturas', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    const nome = `Plano E2E ${s} ${Date.now().toString(36)}`;
    await entrarNoPainel(page, `e2e-assina-${s}`);

    await page.goto('/painel/planos/novo');
    await page.getByLabel('Nome').fill(nome);
    await page.getByLabel('Preço mensal').fill('89,90');
    await page.getByRole('button', { name: 'Criar plano' }).click();
    await expect(page.getByText('Escolha ao menos um serviço incluído.')).toBeVisible();
    await page.getByLabel(/Corte E2E/).check();
    await page.getByRole('button', { name: 'Criar plano' }).click();
    await expect(page.getByRole('status').filter({ hasText: `Plano ${nome} criado.` })).toBeVisible();
    await expect(page.getByRole('heading', { name: nome })).toBeVisible();
    await verificarTela(page, info, 'Plano');

    await page.goto('/painel/assinaturas');
    await expect(page.getByRole('heading', { name: 'Assinaturas', exact: true })).toBeVisible();
    await expect(page.getByText('Pagamento online não configurado')).toBeVisible();
    await page.getByLabel('Cliente (nome ou e-mail)').fill('Cliente Assinante E2E');
    await page.getByRole('button', { name: 'Buscar' }).click();
    await page.getByRole('link', { name: 'Cliente Assinante E2E' }).first().click();
    await expect(page.getByRole('heading', { name: 'Cliente Assinante E2E' })).toBeVisible();
    await expect(page.getByText('Clube E2E').first()).toBeVisible();
    await verificarTela(page, info, 'Assinatura');

    await page.goto('/painel/assinaturas/eventos');
    await expect(page.getByRole('heading', { name: 'Eventos do Stripe' })).toBeVisible();
    await verificarTela(page, info, 'Eventos do Stripe');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('assinante: serviço incluído sai por R$ 0,00 e vê a assinatura', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;

    // 6o dia disponível: longe dos outros testes de agenda.
    await page.goto('/agendar');
    await page.getByRole('link', { name: /Corte E2E/ }).click();
    await page.getByRole('link', { name: barbeiro(info) }).click();
    await page.getByRole('navigation', { name: 'Dias disponíveis' }).getByRole('link').nth(5).click();
    await page.locator('a.slot').first().click();
    await expect(page).toHaveURL(/\/entrar$/);
    await page.getByLabel('E-mail').fill(`e2e-assina-cliente-${s}@exemplo.test`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Confirme seu horário' })).toBeVisible();
    await expect(page.locator('[data-discount]')).toHaveText('−R$ 50,00');
    await expect(page.locator('[data-total]')).toHaveText('R$ 0,00');
    await expect(page.getByText('Quer assinar um plano?')).toHaveCount(0); // já assina (e sem Stripe aqui)
    await verificarTela(page, info, 'Confirmação de assinante');
    await page.getByRole('button', { name: 'Confirmar agendamento' }).click();

    await expect(page.getByText(/Agendamento feito! Código AG-/)).toBeVisible();
    await expect(page.locator('[data-total]')).toHaveText('R$ 0,00');

    await page.goto('/minha-conta/assinatura');
    await expect(page.getByRole('heading', { name: 'Assinatura' })).toBeVisible();
    await expect(page.getByText('Clube E2E')).toBeVisible();
    await expect(page.getByText('Benefício hoje')).toBeVisible();
    await verificarTela(page, info, 'Minha assinatura');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

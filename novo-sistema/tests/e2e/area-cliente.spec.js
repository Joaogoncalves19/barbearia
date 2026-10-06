// Fase 12 — área do cliente no navegador (celular e desktop), com contas
// fictícias. Cliente: início, agendamentos (remarca e cancela pelas regras de
// sempre), comprovante, benefícios, assinatura, avisos, dados e privacidade.
// Outra cliente: abrir o horário alheio pela URL dá 404. Conta descartável:
// baixa os dados (pede a senha de novo) e exclui a conta.
// Em cada tela: axe, sem erro de console/CSP e sem rolagem lateral.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase12-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNaConta(page, email) {
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/minha-conta/);
}

function menu(page) {
    return page.getByRole('navigation', { name: 'Minha conta' });
}

test('cliente vê os próximos horários, remarca e cancela pelas regras da agenda', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNaConta(page, `e2e-area-${s}@exemplo.test`);

    await expect(page.getByRole('heading', { name: /Olá, Cliente/ })).toBeVisible();
    await expect(page.locator('[data-upcoming]').first()).toBeVisible();
    await verificarTela(page, info, 'Início da conta');

    await menu(page).getByRole('link', { name: 'Agendamentos' }).click();
    await expect(page.getByRole('heading', { name: 'Agendamentos', exact: true })).toBeVisible();
    await verificarTela(page, info, 'Agendamentos');
    await page.locator('[data-upcoming^="AG-E2E-AR"]').first().click();
    await expect(page.getByRole('heading', { name: /^Horário AG-E2E-AR/ })).toBeVisible();
    await expect(page.locator('[data-change-rules]')).toContainText('no máximo 2 vezes');
    await verificarTela(page, info, 'Detalhe do horário');

    await page.getByRole('link', { name: 'Remarcar' }).click();
    await expect(page.getByRole('heading', { name: 'Remarcar' })).toBeVisible();
    await verificarTela(page, info, 'Remarcar');
    await page.locator('label.slot').first().click();
    await page.getByRole('button', { name: 'Remarcar para o horário escolhido' }).click();
    await expect(page.getByText('Agendamento remarcado.')).toBeVisible();
    await expect(page.locator('[data-change-rules]')).toContainText('usadas: 1');

    await page.getByRole('button', { name: 'Cancelar agendamento' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Cancelar agendamento' }).click();
    await expect(page.getByText('Agendamento cancelado.')).toBeVisible();

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('cliente abre o comprovante do atendimento', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNaConta(page, `e2e-area-${s}@exemplo.test`);

    await menu(page).getByRole('link', { name: 'Comprovantes' }).click();
    await expect(page.getByRole('heading', { name: 'Comprovantes' })).toBeVisible();
    await verificarTela(page, info, 'Comprovantes');
    await page.getByRole('link', { name: /Ver comprovante/ }).first().click();
    await expect(page.getByRole('heading', { name: /^Comprovante AT-/ })).toBeVisible();
    await verificarTela(page, info, 'Comprovante');

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('cliente navega pelas outras telas da conta (menu no celular rola só dentro dele)', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNaConta(page, `e2e-area-${s}@exemplo.test`);

    for (const [link, titulo] of [
        ['Benefícios', 'Benefícios e fidelidade'],
        ['Assinatura', 'Assinatura'],
        ['Avaliações', 'Avaliações'],
        ['Avisos', 'Avisos'],
        ['Meus dados', 'Meus dados'],
        ['Privacidade', 'Privacidade e seus dados'],
    ]) {
        await menu(page).getByRole('link', { name: new RegExp(`^${link}`) }).click();
        await expect(page.getByRole('heading', { name: titulo, exact: true })).toBeVisible();
        await expect(menu(page).locator('[aria-current="page"]')).toBeInViewport();
        await verificarTela(page, info, titulo);
    }
    await menu(page).getByRole('link', { name: 'Meus dados' }).click();
    await expect(page.locator('[data-cpf]')).toHaveText(/^\d{3}\.\*{3}\.\*{3}-\d{2}$/);

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('outra cliente não abre o horário de ninguém pela URL', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    await entrarNaConta(page, `e2e-area-outra-${s}@exemplo.test`);

    for (const codigo of [`AG-E2E-A-${s}`, `AG-E2E-B-${s}`]) {
        const resposta = await page.goto(`/minha-conta/agendamentos/${codigo}`);
        expect(resposta.status()).toBe(404);
        await expect(page.getByRole('heading', { name: 'Página não encontrada' })).toBeVisible();
        const remarcar = await page.goto(`/minha-conta/agendamentos/${codigo}/remarcar`);
        expect(remarcar.status()).toBe(404);
    }
});

test('cliente baixa os dados e exclui a conta (pede a senha de novo)', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const email = `e2e-excluir-${s}@exemplo.test`;
    const erros = observarErros(page);
    await entrarNaConta(page, email);

    await menu(page).getByRole('link', { name: 'Privacidade' }).click();
    await page.getByRole('button', { name: 'Baixar meus dados' }).click();
    await expect(page.getByRole('heading', { name: 'Confirme sua senha' })).toBeVisible();
    await verificarTela(page, info, 'Confirmar senha');
    await page.getByLabel('Sua senha').fill(SENHA);
    await page.getByRole('button', { name: 'Confirmar' }).click();
    await expect(page.getByRole('heading', { name: 'Privacidade e seus dados' })).toBeVisible();

    const [arquivo] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Baixar meus dados' }).click(),
    ]);
    expect(arquivo.suggestedFilename()).toMatch(/^meus-dados-\d{4}-\d{2}-\d{2}\.json$/);

    await page.getByRole('link', { name: 'Quero excluir minha conta' }).click();
    await expect(page.getByRole('heading', { name: 'Excluir minha conta' })).toBeVisible();
    await verificarTela(page, info, 'Excluir conta');
    await page.getByRole('button', { name: 'Excluir minha conta para sempre' }).click();
    await expect(page.getByText('Digite EXCLUIR, em maiúsculas, para confirmar.')).toBeVisible();
    await page.getByLabel('Para confirmar, digite EXCLUIR').fill('EXCLUIR');
    await page.getByRole('button', { name: 'Excluir minha conta para sempre' }).click();
    await expect(page.getByText('Sua conta foi excluída')).toBeVisible();

    // Os dados antigos não entram mais.
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.getByText('E-mail ou senha incorretos.')).toBeVisible();

    expect(erros, 'erros de console / CSP').toEqual([]);
});

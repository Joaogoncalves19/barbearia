// Fase 13 (P13-01) — tela Clientes do painel, no celular e no desktop.
// Recepção: acha o cliente pela busca, vê a ficha (CPF mascarado, anotação,
// atendimento) e edita o celular. Financeiro: sem acesso (matriz). Dono:
// anonimiza um cliente com senha reconfirmada e a palavra de confirmação.
// Em cada tela: axe sem violação grave, sem erro de console/CSP e sem rolagem
// lateral.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-clientes-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

test('recepção busca, abre a ficha e edita o celular', async ({ page }, info) => {
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNoPainel(page, `e2e-clientes-rec-${s}`);

    // O menu leva à tela (no celular o menu fica recolhido).
    await expect(page.locator('a[href$="/painel/clientes"]').first()).toBeAttached();
    await page.goto('/painel/clientes');
    await expect(page.getByRole('heading', { name: 'Clientes', exact: true })).toBeVisible();
    await verificarTela(page, info, 'lista');

    await page.getByLabel('Nome, e-mail ou celular').fill(`Cliente Ficha ${s}`);
    await page.getByRole('button', { name: 'Buscar' }).click();
    await expect(page.locator('[data-customer-row]')).toHaveCount(1);
    await page.getByRole('link', { name: `Cliente Ficha ${s}` }).click();

    await expect(page.getByRole('heading', { name: `Cliente Ficha ${s}` })).toBeVisible();
    await expect(page.locator('[data-cpf]')).toHaveText(/\d{3}\.\*\*\*\.\*\*\*-\d{2}/);
    await expect(page.getByText('Pele sensível: usar navalha nova')).toBeVisible();
    await expect(page.locator('[data-attendance-row]').first()).toBeVisible();
    await expect(page.getByText('Anonimizar cadastro')).toHaveCount(0);
    await verificarTela(page, info, 'ficha');

    await page.getByRole('link', { name: 'Editar' }).click();
    await expect(page.getByLabel('CPF')).toHaveCount(0);
    await verificarTela(page, info, 'editar');
    const celular = s === 'desktop' ? '(11) 97000-1001' : '(11) 97000-1002';
    await page.getByLabel('Celular').fill(celular);
    await page.getByRole('button', { name: 'Salvar' }).click();
    await expect(page.getByText('Cadastro atualizado.')).toBeVisible();
    await expect(page.locator('[data-customer-phone]')).toHaveText(s === 'desktop' ? '+5511970001001' : '+5511970001002');

    expect(erros, 'sem erro de console ou CSP').toEqual([]);
});

test('financeiro não vê clientes', async ({ page }, info) => {
    await entrarNoPainel(page, `e2e-clientes-fin-${info.project.name}`);
    await expect(page.locator('a[href$="/painel/clientes"]')).toHaveCount(0);
    const r = await page.goto('/painel/clientes');
    expect(r.status()).toBe(403);
});

test('dono anonimiza com senha reconfirmada', async ({ page }, info) => {
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNoPainel(page, `e2e-clientes-dono-${s}`);

    await page.goto(`/painel/clientes?busca=${encodeURIComponent(`Cliente Anonimizar ${s}`)}`);
    await page.getByRole('link', { name: `Cliente Anonimizar ${s}` }).click();
    await expect(page.locator('[data-cpf]')).toHaveText(/\d{3}\.\d{3}\.\d{3}-\d{2}/);
    await page.getByRole('link', { name: 'Anonimizar cadastro' }).click();

    await expect(page).toHaveURL(/\/painel\/confirmar-senha$/);
    await page.getByLabel('Sua senha').fill(SENHA);
    await page.getByRole('button', { name: 'Confirmar' }).click();

    await expect(page.getByRole('heading', { name: `Anonimizar Cliente Anonimizar ${s}` })).toBeVisible();
    await verificarTela(page, info, 'anonimizar');
    await page.getByLabel('Digite ANONIMIZAR para confirmar').fill('ANONIMIZAR');
    await page.getByRole('button', { name: 'Anonimizar cadastro' }).click();

    await expect(page.getByText('Cadastro anonimizado.', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Cliente removido' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Editar' })).toHaveCount(0);
    await verificarTela(page, info, 'anonimizado');

    expect(erros, 'sem erro de console ou CSP').toEqual([]);
});

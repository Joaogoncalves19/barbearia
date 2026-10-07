// Fase 7 — comissão, vale e repasse no navegador (celular e desktop).
// Dono: regra de comissão (com erro de validação), ajuste, vale, repasse,
// estorno do repasse e novo repasse (o saldo termina zerado). Profissional:
// vê só o próprio extrato, sem as ações de gestão. Em cada tela: axe, sem
// erro de console/CSP e sem rolagem lateral. O e2e-accounts encerra as regras
// e quita o saldo do profissional de teste antes de cada execução.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase7-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    // Fase 12.5: o profissional entra na area dele (/profissional).
    await expect(page).toHaveURL(/\/(painel|profissional)$/);
}

async function abrirModal(page, botao, titulo) {
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: botao }).first().click();
    const modal = page.getByRole('dialog', { name: titulo });
    await expect(modal).toBeVisible();
    return modal;
}

test('comissão: regra, ajuste, vale, repasse e estorno do repasse', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    const pro = `Comissão E2E ${s}`;
    await entrarNoPainel(page, `e2e-comissao-${s}`);

    // 1. Regra: valor inválido é recusado no servidor; depois 40%.
    await page.goto('/painel/comissoes/regras');
    await page.getByLabel('Profissional').selectOption({ label: pro });
    await page.getByLabel('Percentual ou valor').fill('150');
    await page.getByRole('button', { name: 'Salvar regra' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Regra inválida' })).toBeVisible();
    await page.getByLabel('Profissional').selectOption({ label: pro });
    await page.getByLabel('Percentual ou valor').fill('40');
    await page.getByLabel('Motivo da mudança').fill(`Regra E2E ${s}`);
    await page.getByRole('button', { name: 'Salvar regra' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Regra definida' })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: `${pro} · todos os serviços` }).filter({ hasText: '40%' })).toBeVisible();
    await verificarTela(page, info, 'Regras de comissão');

    // 2. Extrato: ajuste (crédito) e vale por Pix.
    await page.goto('/painel/comissoes');
    await page.getByRole('link', { name: pro, exact: true }).click();
    await expect(page.getByRole('heading', { name: pro })).toBeVisible();
    await expect(page.locator('[data-open-net]')).toHaveText('R$ 0,00');

    let modal = await abrirModal(page, 'Ajuste', 'Ajuste de comissão ou gorjeta');
    await modal.getByLabel('Valor').fill('25,00');
    await modal.getByLabel('Motivo').fill(`Bônus E2E ${s}`);
    await modal.getByRole('button', { name: 'Registrar ajuste' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Ajuste de comissão registrado' })).toBeVisible();
    await expect(page.locator('[data-open-net]')).toHaveText('R$ 25,00');

    modal = await abrirModal(page, 'Lançar vale', 'Lançar vale');
    await modal.getByLabel('Valor').fill('5,00');
    await modal.getByLabel('Forma').selectOption('pix');
    await modal.getByLabel('Motivo').fill(`Vale E2E ${s}`);
    await modal.getByRole('button', { name: 'Lançar vale' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Vale de R$ 5,00 registrado' })).toBeVisible();
    await expect(page.locator('[data-open-net]')).toHaveText('R$ 20,00');
    await verificarTela(page, info, 'Extrato do profissional');

    // 3. Repasse: confere e paga por Pix.
    await page.getByRole('link', { name: 'Repassar' }).first().click();
    await expect(page.locator('[data-payout-net]')).toHaveText('R$ 20,00');
    await verificarTela(page, info, 'Conferir repasse');
    await page.getByLabel('Forma de pagamento').selectOption('pix');
    await page.getByRole('button', { name: 'Registrar repasse de R$ 20,00' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Repasse de R$ 20,00 registrado' })).toBeVisible();
    await expect(page.locator('[data-payout-amount]')).toHaveText('R$ 20,00');
    await verificarTela(page, info, 'Repasse registrado');

    // 4. Estorno do repasse: motivo obrigatório; os valores voltam ao saldo.
    modal = await abrirModal(page, 'Estornar repasse', 'Estornar este repasse?');
    await modal.getByLabel('Motivo').fill(`Estorno E2E ${s}`);
    await modal.getByRole('button', { name: 'Confirmar estorno' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Repasse estornado' })).toBeVisible();
    await expect(page.getByText(`Estorno E2E ${s}`)).toBeVisible();

    // 5. Novo repasse do mesmo saldo (termina zerado).
    await page.getByRole('link', { name: 'Voltar para o extrato' }).click();
    await expect(page.locator('[data-open-net]')).toHaveText('R$ 20,00');
    await page.getByRole('link', { name: 'Repassar' }).first().click();
    await page.getByRole('button', { name: 'Registrar repasse de R$ 20,00' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Repasse de R$ 20,00 registrado' })).toBeVisible();

    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('profissional: vê só o próprio extrato, sem gestão', async ({ page }, info) => {
    // Login, extrato com axe e três telas negadas: com a fila do servidor embutido
    // (uma requisição por vez) passava perto dos 30 s padrão (visto na Fase 8).
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    await entrarNoPainel(page, `e2e-comissao-pro-${s}`);

    // Fase 12.5: o proprio extrato abre em "Ganhos", na area do profissional.
    await page.goto('/painel/minhas-comissoes');
    await expect(page).toHaveURL(/\/profissional\/ganhos$/);
    await expect(page.getByText(`Comissão E2E ${s}`).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Lançar vale' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Ajuste' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Repassar' })).toHaveCount(0);
    await verificarTela(page, info, 'Extrato do próprio profissional');

    for (const url of ['/painel/comissoes', '/painel/repasses', '/painel/comissoes/regras']) {
        const r = await page.goto(url);
        expect(r?.status(), url).toBe(403);
    }

    expect(erros.filter((e) => !/403/.test(e)), 'erros de console/CSP').toEqual([]);
});

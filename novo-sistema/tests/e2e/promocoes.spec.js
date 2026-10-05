// Fase 8 — promoções, vale-presente e comprovantes no navegador (celular e
// desktop). Dono: cria um cupom (com erro de validação), vende um vale-presente
// e abre o comprovante para imprimir (a barra de ações some na impressão).
// Cliente: agenda pelo site com o cupom; o total visto na confirmação é o
// mesmo gravado no agendamento. Em cada tela: axe, sem erro de console/CSP e
// sem rolagem lateral.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase8-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

/** Abre o caixa se estiver fechado (o caixa é um só; o teste do caixa fecha e reabre). */
async function garantirCaixaAberto(page) {
    await page.goto('/painel/caixa');
    const abrir = page.getByRole('button', { name: 'Abrir caixa' });
    if (await abrir.isVisible()) {
        await page.getByLabel('Dinheiro na gaveta (valor inicial)').fill('0,00');
        await abrir.click();
        await expect(page.getByRole('heading', { name: 'Caixa aberto' })).toBeVisible();
    }
}

const barbeiro = (info) => (info.project.name === 'celular' ? 'Barbeiro A celular' : 'Barbeiro B desktop');

test('cupom: dono cria, cliente agenda e o total visto é o gravado', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    const codigo = `E2E${s.slice(0, 3)}${Date.now().toString(36)}`.toUpperCase();

    // 1. Dono cria o cupom de 20% (percentual inválido é recusado no servidor).
    await entrarNoPainel(page, `e2e-promo-${s}`);
    await page.goto('/painel/cupons/novo');
    await page.getByLabel('Código').fill(codigo);
    await page.getByLabel('Tipo').selectOption('percent');
    await page.getByLabel('Percentual ou valor').fill('150');
    await page.getByRole('button', { name: 'Criar cupom' }).click();
    await expect(page.getByText('Percentual entre 0,01% e 100%')).toBeVisible();
    await page.getByLabel('Percentual ou valor').fill('20');
    await page.getByRole('button', { name: 'Criar cupom' }).click();
    await expect(page.getByRole('status').filter({ hasText: `Cupom ${codigo} criado` })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: codigo })).toBeVisible();
    await verificarTela(page, info, 'Cupons');

    // 2. Cliente agenda com o cupom (5o dia disponível: longe dos outros testes).
    await page.goto('/agendar');
    await page.getByRole('link', { name: /Corte E2E/ }).click();
    await page.getByRole('link', { name: barbeiro(info) }).click();
    await page.getByRole('navigation', { name: 'Dias disponíveis' }).getByRole('link').nth(4).click();
    await page.locator('a.slot').first().click();
    await expect(page).toHaveURL(/\/entrar$/);
    await page.getByLabel('E-mail').fill(`e2e-promo-cliente-${s}@exemplo.test`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Confirme seu horário' })).toBeVisible();
    await expect(page.locator('[data-total]')).toHaveText('R$ 50,00');
    await page.getByLabel('Cupom').fill(codigo.toLowerCase());
    await page.getByRole('button', { name: 'Atualizar valor' }).click();
    await expect(page.locator('[data-discount]')).toHaveText('−R$ 10,00');
    await expect(page.locator('[data-total]')).toHaveText('R$ 40,00');
    await verificarTela(page, info, 'Confirmação com cupom');
    await page.getByRole('button', { name: 'Confirmar agendamento' }).click();

    await expect(page.getByText(/Agendamento feito! Código AG-/)).toBeVisible();
    await expect(page.locator('[data-total]')).toHaveText('R$ 40,00');
    await verificarTela(page, info, 'Agendamento com desconto');

    // 3. Fidelidade na conta do cliente.
    await page.goto('/minha-conta/fidelidade');
    await expect(page.getByRole('heading', { name: 'Fidelidade' })).toBeVisible();
    await verificarTela(page, info, 'Fidelidade do cliente');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('vale-presente: venda e comprovante para imprimir', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    await entrarNoPainel(page, `e2e-promo-${s}`);

    const vender = async () => {
        await page.goto('/painel/vales-presente/novo');
        await page.getByLabel('Valor do vale').fill('80,00');
        await page.getByLabel('Forma de pagamento').selectOption('pix');
        await page.getByRole('textbox', { name: 'Presenteado (opcional)', exact: true }).fill(`Presenteado E2E ${s}`);
        await page.getByLabel('Mensagem').fill('Feliz aniversário!');
        await page.getByRole('button', { name: 'Registrar venda' }).click();
        await page.waitForLoadState('networkidle');
    };

    await garantirCaixaAberto(page);
    await vender();
    if (await page.getByText('Não há caixa aberto').isVisible()) {
        // O teste do caixa fechou o caixa neste instante: abre e tenta de novo.
        await garantirCaixaAberto(page);
        await vender();
    }
    await expect(page.getByRole('status').filter({ hasText: /Vale-presente PRESENTE-\w+ vendido: R\$ 80,00/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /^PRESENTE-/ })).toBeVisible();
    await verificarTela(page, info, 'Vale-presente vendido');

    await page.getByRole('link', { name: 'Imprimir ou enviar' }).click();
    await expect(page.getByText(`Presenteado E2E ${s}`)).toBeVisible();
    await expect(page.getByText('R$ 80,00').first()).toBeVisible();
    const imprimir = page.getByRole('button', { name: 'Imprimir' });
    await expect(imprimir).toBeVisible();
    await verificarTela(page, info, 'Comprovante do vale');

    // Na impressão só o comprovante aparece (sem botões nem formulário de e-mail).
    await page.emulateMedia({ media: 'print' });
    await expect(imprimir).toBeHidden();
    await page.emulateMedia({ media: 'screen' });

    await page.goto('/painel/fidelidade');
    await expect(page.getByRole('heading', { name: /Fidelidade/ }).first()).toBeVisible();
    await verificarTela(page, info, 'Configuração de promoções');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

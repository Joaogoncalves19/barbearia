// Fase 6 — atendimento, caixa, produtos e estoque no navegador (celular e
// desktop). Atendimento: agendamento de hoje -> abrir -> iniciar -> produto
// -> material -> desconto -> concluir com pagamento dividido -> historico.
// Caixa: abrir, suprimento, sangria, fechar (com justificativa). Estoque:
// cadastrar produto, entrada, saida, ajuste, estorno, saldo. Em cada tela:
// axe, sem erro de console/CSP e sem rolagem lateral.
//
// O caixa e UM so para a barbearia (decisao do dono) e os dois projetos rodam
// em paralelo: so o desktop fecha (e reabre) o caixa; quem conclui um
// atendimento e encontra o caixa fechado abre e tenta de novo.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase6-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

/** Abre o caixa se estiver fechado (valor inicial em reais). */
async function garantirCaixaAberto(page, valor = '0,00') {
    await page.goto('/painel/caixa');
    const abrir = page.getByRole('button', { name: 'Abrir caixa' });
    if (await abrir.isVisible()) {
        await page.getByLabel('Dinheiro na gaveta (valor inicial)').fill(valor);
        await abrir.click();
        await expect(page.getByRole('heading', { name: 'Caixa aberto' })).toBeVisible();
    }
}

async function abrirModal(page, botao, titulo) {
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: botao }).click();
    const modal = page.getByRole('dialog', { name: titulo });
    await expect(modal).toBeVisible();
    return modal;
}

test('atendimento: do agendamento de hoje à conclusão com desconto e pagamento dividido', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    await entrarNoPainel(page, `e2e-operacao-${s}`);
    await garantirCaixaAberto(page);

    // 1. Agenda de hoje: o cliente chegou.
    await page.goto('/painel/agenda');
    await page.getByRole('link', { name: new RegExp(`Chegada E2E ${s}.*Confirmado`) }).first().click();
    await expect(page.getByRole('heading', { name: `Chegada E2E ${s}` })).toBeVisible();
    await page.getByRole('button', { name: 'Cliente chegou: abrir atendimento' }).click();
    await expect(page).toHaveURL(/\/painel\/atendimentos\/AT-/);
    await expect(page.getByRole('status').filter({ hasText: 'aberto' })).toBeVisible();
    await verificarTela(page, info, 'Atendimento aberto');

    // 2. Iniciar, vender produto, registrar material, aplicar desconto.
    await page.getByRole('button', { name: 'Iniciar atendimento' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Atendimento iniciado' })).toBeVisible();
    await page.getByLabel('Vender produto').selectOption({ label: `Pomada E2E ${s} — R$ 35,00` });
    await page.getByRole('button', { name: 'Incluir produto' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'incluído' })).toBeVisible();
    await page.getByLabel('Registrar material').selectOption({ label: `Pomada E2E ${s}` });
    await page.getByRole('button', { name: 'Registrar material' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Consumo registrado' })).toBeVisible();

    await page.getByLabel('Tipo de desconto').selectOption('percent');
    await page.locator('#campo-discount_value').fill('10');
    await page.getByRole('button', { name: 'Aplicar desconto' }).click();
    await expect(page.getByText('O campo motivo é obrigatório.')).toBeVisible(); // erro ligado ao campo, sem perder o resto
    await page.locator('#campo-discount_reason').fill('Cliente fiel E2E');
    await page.getByRole('button', { name: 'Aplicar desconto' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Desconto aplicado' })).toBeVisible();
    // Corte 50,00 - 10% (5,00) + pomada 35,00 = 80,00
    await expect(page.locator('[data-total]')).toHaveText('R$ 80,00');
    await verificarTela(page, info, 'Atendimento em andamento');

    // 3. Concluir: o modal fecha com Esc e devolve o foco; valor errado mostra erro.
    let modal = await abrirModal(page, 'Concluir e receber', 'Concluir e receber');
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(page.getByRole('button', { name: 'Concluir e receber' })).toBeFocused();

    modal = await abrirModal(page, 'Concluir e receber', 'Concluir e receber');
    await modal.locator('#pagamento-0-forma').selectOption('pix');
    await modal.locator('#pagamento-0-valor').fill('10,00');
    await modal.getByRole('button', { name: 'Confirmar conclusão' }).click();
    // Volta com o modal aberto e a mensagem dentro dele; nada foi gravado.
    await expect(modal.getByRole('alert')).toContainText('A soma dos pagamentos precisa ser igual ao total');

    const concluir = async (jaAberto = false) => {
        const m = jaAberto ? modal : await abrirModal(page, 'Concluir e receber', 'Concluir e receber');
        await m.locator('#pagamento-0-forma').selectOption('pix');
        await m.locator('#pagamento-0-valor').fill('50,00');
        await m.locator('#pagamento-1-forma').selectOption('cash');
        await m.locator('#pagamento-1-valor').fill('30,00');
        await m.locator('#pagamento-1-gorjeta').fill('5,00');
        await verificarTela(page, info, 'Concluir - pagamento dividido');
        await m.getByRole('button', { name: 'Confirmar conclusão' }).click();
    };
    await concluir(true);
    await page.waitForLoadState('networkidle');
    if (await modal.getByRole('alert').filter({ hasText: 'Não há caixa aberto' }).isVisible()) {
        // O outro projeto fechou o caixa neste instante: abre e tenta de novo.
        const url = page.url();
        await garantirCaixaAberto(page);
        await page.goto(url);
        await concluir();
    }

    await expect(page.getByRole('status').filter({ hasText: 'Atendimento concluído. Total: R$ 80,00' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Pagamentos' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Pix' })).toBeVisible();
    await expect(page.getByText('Atendimento concluído.', { exact: true })).toBeVisible(); // historico
    await expect(page.getByText(/Desconto de 10% aplicado/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Concluir e receber' })).toHaveCount(0);
    await verificarTela(page, info, 'Atendimento concluído');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('caixa: abrir, suprimento, sangria e fechar', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    await entrarNoPainel(page, `e2e-recepcao-${s}`);
    await garantirCaixaAberto(page, '100,00');
    await verificarTela(page, info, 'Caixa aberto');

    let modal = await abrirModal(page, 'Suprimento', 'Suprimento (entrada de dinheiro)');
    await modal.getByLabel('Valor').fill('20,00');
    await modal.getByLabel('Motivo').fill(`Reforço de troco E2E ${s}`);
    await modal.getByRole('button', { name: 'Registrar' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Suprimento de R$ 20,00 registrado' })).toBeVisible();

    modal = await abrirModal(page, 'Sangria', 'Sangria (retirada de dinheiro)');
    await modal.getByLabel('Valor').fill('0');
    await modal.getByLabel('Motivo').fill('Valor inválido');
    await modal.getByRole('button', { name: 'Registrar' }).click();
    // O modal reabre com a mensagem ligada ao campo.
    await expect(modal.getByText('Informe um valor maior que zero')).toBeVisible();
    await expect(modal.getByLabel('Motivo')).toHaveValue('Valor inválido');

    await modal.getByLabel('Valor').fill('5,00');
    await modal.getByLabel('Motivo').fill(`Depósito E2E ${s}`);
    await modal.getByRole('button', { name: 'Registrar' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Sangria de R$ 5,00 registrado' })).toBeVisible();
    await expect(page.getByRole('cell', { name: `Depósito E2E ${s}` })).toBeVisible();
    await verificarTela(page, info, 'Caixa com movimentações');

    modal = await abrirModal(page, 'Fechar caixa', 'Fechar o caixa?');
    if (s !== 'desktop') {
        // Celular: confere o modal e cancela pelo teclado (o caixa e compartilhado).
        await page.keyboard.press('Escape');
        await expect(modal).toBeHidden();
        await expect(page.getByRole('button', { name: 'Fechar caixa' })).toBeFocused();
        expect(erros, 'erros de console/CSP').toEqual([]);
        return;
    }

    // Desktop: fecha com contagem e justificativa (pode haver diferenca), depois reabre.
    await modal.getByLabel('Dinheiro contado').fill('1,00');
    await modal.getByRole('button', { name: 'Fechar caixa' }).click();
    await expect(modal.getByRole('alert')).toContainText('explique o motivo');
    await expect(modal.getByLabel('Dinheiro contado')).toHaveValue('1,00');
    await modal.getByLabel('Justificativa (se houver diferença)').fill('Conferência do teste E2E');
    await modal.getByRole('button', { name: 'Fechar caixa' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Caixa fechado' })).toBeVisible();
    await expect(page.getByText('Conferência do teste E2E')).toBeVisible();
    await verificarTela(page, info, 'Caixa fechado');

    await garantirCaixaAberto(page, '100,00');
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('estoque: cadastrar produto, entrada, saída, ajuste e estorno', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    const nome = `Cera E2E ${s} ${Date.now()}`;
    await entrarNoPainel(page, `e2e-operacao-${s}`);

    await page.goto('/painel/produtos');
    await verificarTela(page, info, 'Produtos');
    await page.getByRole('link', { name: 'Novo produto' }).click();
    await page.getByLabel('Nome').fill(nome);
    await page.getByLabel('Unidade').selectOption('un');
    await page.getByLabel('Preço de venda').fill('29,90');
    await page.getByLabel('Custo unitário').fill('12,00');
    await page.getByLabel('Estoque mínimo').fill('3');
    await verificarTela(page, info, 'Novo produto');
    await page.getByRole('button', { name: 'Cadastrar produto' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'cadastrado' })).toBeVisible();
    await expect(page.getByRole('heading', { name: nome })).toBeVisible();
    await expect(page.locator('[data-balance]')).toHaveText('0');

    const entrada = page.locator('form', { has: page.getByRole('button', { name: 'Registrar entrada' }) });
    await entrada.getByLabel('Quantidade').fill('10');
    await entrada.getByLabel('Custo unitário').fill('12,00');
    await page.getByRole('button', { name: 'Registrar entrada' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Entrada de 10 registrada' })).toBeVisible();

    const saida = page.locator('form', { has: page.getByRole('button', { name: 'Registrar saída' }) });
    await saida.getByLabel('Tipo').selectOption('loss');
    await saida.getByLabel('Quantidade').fill('99');
    await saida.getByLabel('Motivo').fill('Tentativa acima do saldo');
    await page.getByRole('button', { name: 'Registrar saída' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Estoque insuficiente' })).toBeVisible();
    await expect(page.locator('[data-balance]')).toHaveText('10');

    await saida.getByLabel('Tipo').selectOption('loss');
    await saida.getByLabel('Quantidade').fill('2');
    await saida.getByLabel('Motivo').fill('Pote quebrado E2E');
    await page.getByRole('button', { name: 'Registrar saída' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Perda de 2 registrada' })).toBeVisible();

    const ajuste = page.locator('form', { has: page.getByRole('button', { name: 'Ajustar' }) });
    await ajuste.getByLabel('Contagem física').fill('7');
    await ajuste.getByLabel('Motivo').fill('Inventário E2E');
    await page.getByRole('button', { name: 'Ajustar' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Inventário ajustado para 7' })).toBeVisible();
    await expect(page.locator('[data-balance]')).toHaveText('7');

    // Estorno da perda: modal com confirmacao.
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Estornar Perda de 2' }).click();
    const modal = page.getByRole('dialog', { name: 'Estornar movimentação' });
    await expect(modal).toBeVisible();
    await modal.getByLabel('Motivo').fill('Perda lançada por engano');
    await modal.getByRole('button', { name: 'Confirmar estorno' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Movimentação estornada' })).toBeVisible();
    await expect(page.locator('[data-balance]')).toHaveText('9');
    await expect(page.getByText('Estornada', { exact: true })).toBeVisible();
    await verificarTela(page, info, 'Estoque do produto');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

// Redesign visual (fase extraordinária): varredura das telas principais de
// cada área, no celular e no desktop. Em cada tela: sem erro de console/CSP,
// sem rolagem lateral, sem violação grave de acessibilidade (axe) e os
// elementos-chave da nova linguagem no lugar (agenda em linha do tempo, conta
// da comanda, faixa de números, capítulos do site, página de erro na marca).
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

async function verificarTela(page, nome) {
    const larguraExtra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(larguraExtra, `${nome}: página não deve rolar na horizontal`).toBeLessThanOrEqual(1);
    const axe = await new AxeBuilder({ page }).analyze();
    const graves = axe.violations
        .filter((v) => ['serious', 'critical'].includes(v.impact))
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length}x) ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(graves, `${nome}: violações de acessibilidade graves`).toEqual([]);
}

test('site público: capítulos, letreiro ou foto, agendamento e erro na marca', async ({ page }) => {
    test.slow();
    const erros = observarErros(page);

    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('.chapter__index').first()).toBeVisible();
    await expect(page.locator('.barber-stripe:visible')).toHaveCount(0); // sem faixa de barber pole
    await verificarTela(page, 'Início');

    for (const [url, titulo] of [['/servicos', 'Serviços e preços'], ['/equipe', 'Quem cuida de você'], ['/agendar', 'Escolha o serviço']]) {
        await page.goto(url);
        await expect(page.getByRole('heading', { name: titulo, level: 1 })).toBeVisible();
        await verificarTela(page, titulo);
    }
    await page.locator('[data-service="corte-e2e"] a').first().click();
    await expect(page.getByRole('heading', { name: 'Com quem?' })).toBeVisible();
    await verificarTela(page, 'Agendamento: profissional');

    expect(erros, 'erros de console / CSP').toEqual([]);

    const r = await page.goto('/pagina-que-nao-existe');
    expect(r.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Página não encontrada' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ir para o início' })).toBeVisible();
    await verificarTela(page, 'Erro 404');
});

test('painel: hoje, agenda em linha do tempo, comanda, caixa e cadastros', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const erros = observarErros(page);

    await page.goto('/painel/entrar');
    await verificarTela(page, 'Acesso da equipe');
    await page.getByLabel('Usuário ou e-mail').fill(`e2e-visual-${s}`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);

    // Hoje: o dia em números e o caixa (nada de texto de espera da Fase 1).
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Bom dia|Boa tarde|Boa noite/);
    await expect(page.getByLabel('O dia em números')).toBeVisible();
    await expect(page.getByText('serão construídos a partir da Fase 4')).toHaveCount(0);
    await verificarTela(page, 'Hoje');

    // Agenda: quadro com régua de horas e uma coluna por profissional.
    await page.goto('/painel/agenda');
    await expect(page.getByRole('heading', { name: 'Agenda', exact: true })).toBeVisible();
    await expect(page.locator('.day-board')).toBeVisible();
    await expect(page.locator('.day-board__name').first()).toBeVisible();
    await verificarTela(page, 'Agenda');

    for (const [url, titulo] of [
        ['/painel/atendimentos', 'Atendimentos'],
        ['/painel/caixa', 'Caixa'],
        ['/painel/servicos', 'Serviços'],
        ['/painel/profissionais', 'Profissionais'],
        ['/painel/produtos', 'Produtos e estoque'],
        ['/painel/comissoes', 'Comissões e repasses'],
        ['/painel/cupons', 'Cupons'],
        ['/painel/assinaturas', 'Assinaturas'],
        ['/painel/campanhas', 'Campanhas'],
        ['/painel/avaliacoes', 'Avaliações'],
        ['/painel/agenda/configuracoes', null],
        ['/painel/site', null],
        ['/painel/auditoria', null],
    ]) {
        await page.goto(url);
        if (titulo) await expect(page.getByRole('heading', { name: titulo, level: 1 })).toBeVisible();
        await verificarTela(page, url);
    }

    // Menu por tarefa (não por fase do projeto) e sem controles que não fazem nada.
    await expect(page.locator('#busca-painel')).toHaveCount(0);
    await expect(page.locator('.nav-group__title', { hasText: 'Clientes e vendas' })).toBeAttached();

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('conta do cliente: próximo horário, benefícios e menu no celular', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const erros = observarErros(page);

    await page.goto('/entrar');
    await verificarTela(page, 'Acesso do cliente');
    await page.getByLabel('E-mail').fill(`e2e-area-${s}@exemplo.test`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/minha-conta/);

    await expect(page.getByRole('heading', { name: /Olá/ })).toBeVisible();
    await verificarTela(page, 'Início da conta');
    for (const url of ['/minha-conta/agendamentos', '/minha-conta/comprovantes', '/minha-conta/fidelidade', '/minha-conta/dados']) {
        await page.goto(url);
        await verificarTela(page, url);
    }

    expect(erros, 'erros de console / CSP').toEqual([]);
});

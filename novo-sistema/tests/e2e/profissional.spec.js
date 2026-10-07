// Fase 12.5 — area do profissional no navegador (celular e desktop; tablet
// na verificacao de responsividade). O profissional A entra, cai em "Hoje",
// ve so a propria agenda, registra uma anotacao do cliente, abre, inicia e
// finaliza o atendimento pelas regras atuais, consulta ganhos e perfil, e
// nao abre nada do profissional B nem do painel administrativo, nem trocando
// codigos e ids na URL. Em cada tela: axe, sem erro de console/CSP e sem
// rolagem lateral. Os dados sao ficticios (app:e2e-accounts).
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SENHA = process.env.E2E_PASSWORD;

test.describe.configure({ mode: 'serial' });

/** @type {import('@playwright/test').Page} */
let page;
let erros = [];
let s = '';

async function verificarTela(nome, info) {
    const larguraExtra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(larguraExtra, `${nome}: página não deve rolar na horizontal`).toBeLessThanOrEqual(1);

    const axe = await new AxeBuilder({ page }).analyze();
    const graves = axe.violations
        .filter((v) => ['serious', 'critical'].includes(v.impact))
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length}x) ${v.nodes.slice(0, 2).map((n) => `${n.target.join(' ')} ${JSON.stringify(n.any[0]?.data ?? '')}`).join(' | ')}`);
    expect(graves, `${nome}: violações de acessibilidade graves`).toEqual([]);

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase12-5-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrar(p, usuario, senha = SENHA) {
    await p.goto('/painel/entrar');
    await p.getByLabel('Usuário ou e-mail').fill(usuario);
    await p.getByLabel('Senha', { exact: true }).fill(senha);
    await p.getByRole('button', { name: 'Entrar' }).click();
}

test.beforeAll(async ({ browser }, info) => {
    s = info.project.name;
    page = await (await browser.newContext({ ...info.project.use })).newPage();
    page.on('console', (msg) => {
        if (msg.type() === 'error' || /Content Security Policy/i.test(msg.text())) erros.push(msg.text());
    });
    page.on('pageerror', (e) => erros.push(String(e)));
});

test.afterAll(async () => {
    await page?.context().close();
});

test('profissional: entra e cai em Hoje, sem o menu administrativo', async ({}, info) => {
    await entrar(page, `e2e-pro-a-${s}`);
    await expect(page).toHaveURL(/\/profissional$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Profissional');
    await expect(page.getByRole('navigation', { name: 'Área do profissional' })).toBeVisible();
    await expect(page.locator('#menu-painel')).toHaveCount(0);
    for (const destino of ['Hoje', 'Agenda', 'Atendimentos', 'Ganhos', 'Perfil']) {
        await expect(page.getByRole('navigation', { name: 'Área do profissional' }).getByRole('link', { name: destino })).toBeVisible();
    }
    await expect(page.getByRole('navigation', { name: 'Área do profissional' }).getByRole('link', { name: 'Hoje' })).toHaveAttribute('aria-current', 'page');

    // Teclado: o primeiro Tab leva ao "Pular para o conteúdo".
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Pular para o conteúdo' })).toBeFocused();
    await verificarTela('Hoje', info);
});

test('profissional: agenda só dele, anotação do cliente e horário de outro pela URL', async ({}, info) => {
    await page.getByRole('navigation', { name: 'Área do profissional' }).getByRole('link', { name: 'Agenda' }).click();
    await expect(page).toHaveURL(/\/profissional\/agenda/);
    const linha = page.locator('.line', { hasText: `Cliente do A ${s}` }).filter({ hasText: 'Confirmado' }).first();
    await expect(linha).toBeVisible();
    await expect(page.getByText(`Cliente do B ${s}`)).toHaveCount(0);
    await verificarTela('Agenda', info);

    await linha.getByRole('link').click();
    await expect(page.getByRole('heading', { level: 1, name: `Cliente do A ${s}` })).toBeVisible();
    await expect(page.getByText('Prefere máquina 2 nas laterais')).toBeVisible();
    const nota = `Anotação E2E ${s} ${Date.now()}`;
    await page.getByLabel('Nova anotação').fill(nota);
    await page.getByRole('button', { name: 'Registrar anotação' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Anotação registrada.' })).toBeVisible();
    await expect(page.getByText(nota)).toBeVisible();
    await verificarTela('Agendamento', info);
    await page.locator('.note', { hasText: nota }).getByRole('button', { name: /Remover/ }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Anotação removida.' })).toBeVisible();
    await expect(page.getByText(nota)).toHaveCount(0);
    const voltar = page.url();

    // Horario do profissional B pelo codigo: nao existe para o A.
    for (const url of [`/profissional/agendamentos/AG-E2E-PROB-${s}`, `/painel/agendamentos/AG-E2E-PROB-${s}`]) {
        const r = await page.goto(url);
        expect(r?.status(), url).toBe(404);
    }
    await page.goto(voltar);
});

test('profissional: do cliente que chegou à finalização, pelas regras atuais', async ({ browser }, info) => {
    test.slow();
    await page.getByRole('button', { name: 'Cliente chegou: abrir atendimento' }).click();
    await expect(page).toHaveURL(/\/profissional\/atendimentos\/AT-[A-Z0-9-]+$/);
    const atendimento = page.url();
    await page.getByRole('button', { name: 'Iniciar atendimento' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Atendimento iniciado.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Finalizar e receber' })).toBeVisible();
    await verificarTela('Atendimento em andamento', info);

    // Hoje mostra quem esta na cadeira.
    await page.getByRole('navigation', { name: 'Área do profissional' }).getByRole('link', { name: 'Hoje' }).click();
    await expect(page.getByText('Atendendo agora')).toBeVisible();
    await expect(page.locator('.chair', { hasText: `Cliente do A ${s}` })).toBeVisible();
    await verificarTela('Hoje com atendimento', info);

    // Caixa aberto (o dono abre; o profissional nao ve o caixa).
    const dono = await (await browser.newContext({ ...info.project.use })).newPage();
    const garantirCaixa = async () => {
        if (!(await dono.url().includes('/painel'))) await entrar(dono, `e2e-pro-dono-${s}`);
        await dono.goto('/painel/caixa');
        const abrir = dono.getByRole('button', { name: 'Abrir caixa' });
        if (await abrir.isVisible()) {
            await dono.getByLabel('Dinheiro na gaveta (valor inicial)').fill('0,00');
            await abrir.click();
            await expect(dono.getByRole('heading', { name: 'Caixa aberto' })).toBeVisible();
        }
    };
    await garantirCaixa();

    await page.locator('.chair', { hasText: `Cliente do A ${s}` }).getByRole('link', { name: 'Finalizar' }).click();
    const modal = page.getByRole('dialog', { name: 'Concluir e receber' });
    await expect(modal).toBeVisible();
    const finalizar = async () => {
        await modal.locator('#pagamento-0-forma').selectOption('pix');
        await modal.locator('#pagamento-0-gorjeta').fill('5,00');
        await modal.getByRole('button', { name: 'Confirmar conclusão' }).click();
        await page.waitForLoadState('networkidle');
    };
    await finalizar();
    if (await page.getByText('Não há caixa aberto').first().isVisible()) {
        // Outro teste fechou o caixa neste instante: abre e tenta de novo.
        await garantirCaixa();
        await page.goto(`${atendimento}?finalizar=1`);
        await finalizar();
    }
    await expect(page.getByRole('status').filter({ hasText: 'Atendimento concluído.' })).toBeVisible();
    await expect(page.getByText('Total pago')).toBeVisible();
    await expect(page.locator('[data-tip]')).toHaveText('R$ 5,00');
    await expect(page.getByRole('link', { name: 'Comprovante' })).toBeVisible();
    await verificarTela('Atendimento concluído', info);
    await dono.context().close();
});

test('profissional: ganhos e perfil, só os próprios e sem gestão', async ({}, info) => {
    test.slow(); // axe em duas telas e 28 enderecos trocados na URL
    await page.getByRole('navigation', { name: 'Área do profissional' }).getByRole('link', { name: 'Ganhos' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('A receber');
    await expect(page.getByText(`Cliente do A ${s}`).first()).toBeVisible();
    await expect(page.getByText(`Cliente do B ${s}`)).toHaveCount(0);
    for (const nome of ['Lançar vale', 'Ajuste', 'Repassar', 'Estornar']) {
        await expect(page.getByRole('button', { name: nome })).toHaveCount(0);
    }
    await verificarTela('Ganhos', info);

    await page.getByRole('navigation', { name: 'Área do profissional' }).getByRole('link', { name: 'Perfil' }).click();
    await expect(page.getByText(`e2e-pro-a-${s}`, { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Salvar nome' })).toBeVisible();
    await verificarTela('Perfil', info);

    // Extrato de outro profissional trocando o id: so o proprio abre (e leva a "Ganhos").
    for (let id = 1; id <= 25; id++) {
        const r = await page.goto(`/painel/comissoes/profissionais/${id}`);
        if (r?.status() !== 404) {
            expect(page.url(), `id ${id}`).toMatch(/\/profissional\/ganhos$/);
        }
    }
    // Atendimento do profissional B pelo codigo (area, painel e comprovante): 404.
    const doB = `AT-E2E-PB-${s.slice(0, 3).toUpperCase()}`;
    for (const url of [`/profissional/atendimentos/${doB}`, `/painel/atendimentos/${doB}`, `/painel/atendimentos/${doB}/comprovante`]) {
        const r = await page.goto(url);
        expect(r?.status(), url).toBe(404);
    }
});

test('profissional: painel administrativo negado', async () => {
    await page.goto('/painel');
    await expect(page).toHaveURL(/\/profissional$/);
    for (const url of ['/painel/caixa', '/painel/usuarios', '/painel/servicos', '/painel/comissoes', '/painel/repasses', '/painel/aparencia', '/painel/agenda/configuracoes']) {
        const r = await page.goto(url);
        expect(r?.status(), url).toBe(403);
    }
});

test('profissional: responsivo também no tablet, sem erro de console', async ({}, info) => {
    test.skip(info.project.name !== 'desktop', 'tablet uma vez so (projeto desktop)');
    await page.setViewportSize({ width: 768, height: 1024 });
    for (const url of ['/profissional', '/profissional/agenda', '/profissional/atendimentos', '/profissional/ganhos', '/profissional/perfil']) {
        await page.goto(url);
        const extra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(extra, `${url} no tablet`).toBeLessThanOrEqual(1);
        await expect(page.getByRole('navigation', { name: 'Área do profissional' })).toBeVisible();
    }
    await page.setViewportSize({ width: 1366, height: 900 });
});

test('proprietário: cadastra o barbeiro já com usuário e senha, e ele entra na área dele', async ({ browser }, info) => {
    test.slow();
    const rodada = Date.now().toString(36);
    const usuario = `barb-${s.slice(0, 3)}-${rodada}`;
    const provisoria = `Prov${rodada}9x`;
    const dono = await (await browser.newContext({ ...info.project.use })).newPage();
    await entrar(dono, `e2e-pro-dono-${s}`);

    // Como na tela Usuarios: reconfirma a senha antes de abrir o cadastro.
    await dono.goto('/painel/profissionais/novo');
    await expect(dono).toHaveURL(/\/painel\/confirmar-senha$/);
    await dono.getByLabel('Sua senha').fill(SENHA);
    await dono.getByRole('button', { name: 'Confirmar' }).click();
    await expect(dono).toHaveURL(/\/painel\/profissionais\/novo$/);

    await dono.getByLabel('Nome de exibição').fill(`Barbeiro Novo ${s} ${rodada}`);
    await dono.getByLabel('Usuário (para entrar)').fill(usuario);
    await dono.getByLabel('Senha provisória').fill(provisoria);
    await dono.getByRole('button', { name: 'Cadastrar e escolher serviços' }).click();
    await expect(dono.getByRole('status')).toContainText(`usuário "${usuario}"`);
    await dono.context().close();

    // O barbeiro entra, troca a senha provisoria e cai na area dele.
    const novo = await (await browser.newContext({ ...info.project.use })).newPage();
    await entrar(novo, usuario, provisoria);
    await novo.waitForLoadState('networkidle');
    const avisos = (await novo.locator('.alert, .field__error').allTextContents()).join(' | ');
    await expect(novo, `login do barbeiro novo: ${avisos}`).toHaveURL(/\/painel\/minha-conta\/senha$/);
    const pessoal = `Nova${rodada}8y`;
    await novo.getByLabel('Senha provisória').fill(provisoria);
    await novo.getByLabel('Nova senha', { exact: true }).fill(pessoal);
    await novo.getByLabel('Repita a nova senha').fill(pessoal);
    await novo.getByRole('button', { name: 'Salvar senha' }).click();
    await expect(novo).toHaveURL(/\/profissional$/);
    await expect(novo.getByRole('navigation', { name: 'Área do profissional' })).toBeVisible();
    await novo.context().close();
});

test('profissional: sem erros de console ou CSP', async () => {
    expect(erros.filter((e) => !/40[34]/.test(e)), 'erros de console/CSP').toEqual([]);
});

// Fase 13 — HOMOLOGACAO: roda contra uma instalacao feita pelo PACOTE
// (empacotar.sh), em modo producao (APP_DEBUG=false, caches), com os dados
// MIGRADOS do sistema antigo (copia de ensaio, dados ficticios). Nao faz parte
// da suite normal (playwright.config.js): use playwright.homologacao.config.js.
//
// Cobre, por perfil: primeiro acesso, menu e barreiras (URL proibida), os
// fluxos do dia (caixa, encaixe, atendimento, comprovante por e-mail), gerente
// (avaliacao, estoque), financeiro (repasse), profissional (area propria) e o
// cliente migrado (senha antiga, conta, agendar/remarcar/cancelar pelo site).
// Em cada tela: sem rolagem lateral, sem violacao grave de acessibilidade e
// sem erro de console/CSP. Desktop primeiro; o celular repete o que nao muda
// dado compartilhado.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PROVISORIA = process.env.HOMOLOG_SENHA_PROVISORIA;
const PESSOAL = `${PROVISORIA}N`;
const ADMIN_ANTIGA = process.env.HOMOLOG_SENHA_ADMIN_ANTIGA;
const CONTAS_ANTIGAS = process.env.HOMOLOG_SENHA_CONTAS_ANTIGAS;

test.describe.configure({ mode: 'serial' });

function observarErros(page) {
    const erros = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error' || /Content Security Policy/i.test(msg.text())) erros.push(msg.text());
    });
    page.on('pageerror', (e) => erros.push(String(e)));
    return erros;
}

async function verificarTela(page, info, nome) {
    const extra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(extra, `${nome}: sem rolagem lateral`).toBeLessThanOrEqual(1);
    const axe = await new AxeBuilder({ page }).analyze();
    const graves = axe.violations.filter((v) => ['serious', 'critical'].includes(v.impact)).map((v) => `${v.id} (${v.nodes.length}x)`);
    expect(graves, `${nome}: acessibilidade`).toEqual([]);
    await page.screenshot({ path: `storage/e2e/homologacao/${info.project.name}-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function sair(page) {
    await page.context().clearCookies();
}

/** Entra no painel; na primeira vez troca a senha provisoria pela pessoal. */
async function entrarEquipe(page, usuario, senha = PESSOAL) {
    await sair(page);
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(senha);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/(painel|profissional)(\/minha-conta\/senha)?$/);
}

const PERFIS = {
    'homolog.gerente': {
        ve: ['Agenda', 'Atendimentos', 'Caixa', 'Serviços', 'Profissionais', 'Produtos e estoque', 'Cupons', 'Avaliações', 'Campanhas', 'Comissões'],
        naoVe: ['Usuários', 'Auditoria', 'Regras de comissão'],
        proibidas: ['/painel/usuarios', '/painel/auditoria', '/painel/comissoes/regras'],
    },
    'homolog.recepcao': {
        ve: ['Agenda', 'Atendimentos', 'Caixa', 'Vales-presente', 'Pontos de clientes'],
        naoVe: ['Usuários', 'Auditoria', 'Comissões', 'Repasses', 'Regras de comissão', 'Campanhas'],
        proibidas: ['/painel/usuarios', '/painel/comissoes', '/painel/repasses', '/painel/campanhas', '/painel/servicos/novo'],
    },
    'homolog.financeiro': {
        ve: ['Atendimentos', 'Caixa', 'Comissões', 'Repasses', 'Histórico financeiro'],
        naoVe: ['Agenda', 'Usuários', 'Serviços', 'Campanhas'],
        proibidas: ['/painel/agenda', '/painel/usuarios', '/painel/agenda/novo', '/painel/atendimentos/novo'],
    },
};

test('proprietário migrado cria os usuários da equipe pela tela', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'cria uma vez só (desktop)');
    await entrarEquipe(page, 'ensaio-admin', ADMIN_ANTIGA);
    const papeis = { 'homolog.gerente': ['Gerente Homologação', 'manager'], 'homolog.recepcao': ['Recepção Homologação', 'reception'], 'homolog.financeiro': ['Financeiro Homologação', 'finance'] };
    for (const [usuario, [nome, papel]] of Object.entries(papeis)) {
        await page.goto('/painel/usuarios');
        if (/confirmar-senha/.test(page.url())) {
            await page.getByLabel('Sua senha').fill(ADMIN_ANTIGA);
            await page.getByRole('button', { name: /Confirmar/ }).click();
            await page.goto('/painel/usuarios');
        }
        if (await page.getByRole('cell', { name: usuario, exact: true }).count()) continue;
        await page.goto('/painel/usuarios/novo');
        if (/confirmar-senha/.test(page.url())) {
            await page.getByLabel('Sua senha').fill(ADMIN_ANTIGA);
            await page.getByRole('button', { name: /Confirmar/ }).click();
            await page.goto('/painel/usuarios/novo');
        }
        await page.getByLabel('Nome', { exact: true }).fill(nome);
        await page.getByLabel('Usuário (para entrar)').fill(usuario);
        await page.locator('select[name="role"]').selectOption(papel);
        await page.getByLabel('Senha provisória').fill(PROVISORIA);
        await verificarTela(page, info, 'usuario-novo');
        await page.getByRole('button', { name: 'Criar usuário' }).click();
        await expect(page.getByText(`Conta de ${nome} criada.`, { exact: false })).toBeVisible();
    }
});

test('equipe: primeiro acesso troca a senha provisória', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'troca de senha uma vez só (desktop)');
    for (const usuario of Object.keys(PERFIS)) {
        // Rodada repetida: a senha pessoal ja vale (a provisoria nao mais).
        await entrarEquipe(page, usuario, PESSOAL).catch(() => null);
        if (/\/painel$/.test(page.url())) continue;
        await sair(page);
        await page.goto('/painel/entrar');
        await page.getByLabel('Usuário ou e-mail').fill(usuario);
        await page.getByLabel('Senha', { exact: true }).fill(PROVISORIA);
        await page.getByRole('button', { name: 'Entrar' }).click();
        await expect(page).toHaveURL(/\/painel\/minha-conta\/senha$/);
        await expect(page.getByRole('heading', { name: 'Crie sua senha pessoal' })).toBeVisible();
        await page.getByLabel('Senha provisória').fill(PROVISORIA);
        await page.getByLabel('Nova senha', { exact: true }).fill(PESSOAL);
        await page.getByLabel('Repita a nova senha').fill(PESSOAL);
        await page.getByRole('button', { name: 'Salvar senha' }).click();
        await expect(page).toHaveURL(/\/painel$/);
    }
});

test('perfis: cada um vê só o seu menu e é barrado fora dele', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    for (const [usuario, perfil] of Object.entries(PERFIS)) {
        await entrarEquipe(page, usuario);
        await expect(page).toHaveURL(/\/painel$/);
        const nav = page.locator('nav').filter({ has: page.getByRole('link', { name: 'Início', includeHidden: true }) }).first();
        for (const item of perfil.ve) await expect(nav.getByRole('link', { name: item, exact: true, includeHidden: true }), `${usuario} vê ${item}`).toHaveCount(1);
        for (const item of perfil.naoVe) await expect(nav.getByRole('link', { name: item, exact: true, includeHidden: true }), `${usuario} não vê ${item}`).toHaveCount(0);
        await verificarTela(page, info, `inicio-${usuario}`);
        for (const url of perfil.proibidas) {
            const r = await page.request.get(url, { maxRedirects: 0 });
            expect(r.status(), `${usuario} em ${url}`).toBe(403);
        }
    }
    // Proprietario migrado: senha do sistema antigo continua valendo.
    await entrarEquipe(page, 'ensaio-admin', ADMIN_ANTIGA);
    const nav = page.locator('nav').filter({ has: page.getByRole('link', { name: 'Início', includeHidden: true }) }).first();
    for (const item of ['Usuários', 'Auditoria', 'Regras de comissão', 'Aparência']) await expect(nav.getByRole('link', { name: item, exact: true, includeHidden: true })).toHaveCount(1);
    await verificarTela(page, info, 'inicio-proprietario');
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('profissional migrado: senha antiga, área própria, sem painel administrativo', async ({ page }, info) => {
    const erros = observarErros(page);
    await entrarEquipe(page, 'barbeiro.ensaio', CONTAS_ANTIGAS);
    await expect(page).toHaveURL(/\/profissional$/);
    await expect(page.getByRole('navigation', { name: 'Área do profissional' })).toBeVisible();
    await verificarTela(page, info, 'profissional-hoje');
    for (const tela of ['agenda', 'atendimentos', 'ganhos', 'perfil']) {
        await page.goto(`/profissional/${tela}`);
        await expect(page.locator('main')).toBeVisible();
        await verificarTela(page, info, `profissional-${tela}`);
    }
    await page.goto('/profissional/ganhos');
    await expect(page.getByText('R$ 32,20').first(), 'repasse migrado aparece nos ganhos').toBeVisible();
    for (const url of ['/painel/usuarios', '/painel/caixa', '/painel/comissoes', '/painel/servicos/novo']) {
        const r = await page.request.get(url, { maxRedirects: 0 });
        expect(r.status(), `profissional em ${url}`).toBe(403);
    }
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('cliente migrado: senha antiga, conta, agenda/remarca/cancela pelo site', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    await sair(page);
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill('cliente.um@ensaio.test');
    await page.getByLabel('Senha', { exact: true }).fill(CONTAS_ANTIGAS);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/minha-conta/);
    await expect(page.getByRole('navigation', { name: 'Minha conta' })).toBeVisible();
    await verificarTela(page, info, 'cliente-inicio');
    for (const [tela, texto] of [['agendamentos', /Corte Ensaio|Barba Ensaio/], ['comprovantes', /140,30/], ['fidelidade', /Saldo\s*[45] pontos/]]) {
        await page.goto(`/minha-conta/${tela}`);
        await expect(page.locator('main')).toContainText(texto);
        await verificarTela(page, info, `cliente-${tela}`);
    }

    // Agendar pelo site (dia diferente em cada projeto).
    await page.goto('/agendar');
    await page.getByRole('link', { name: /Corte Ensaio/ }).first().click();
    const pro = page.getByRole('link', { name: /Barbeiro Ensaio/ });
    if (await pro.count()) await pro.first().click();
    await expect(page.getByRole('heading', { name: 'Escolha o dia e o horário' })).toBeVisible();
    await page.getByRole('navigation', { name: 'Dias disponíveis' }).getByRole('link').nth(info.project.name === 'desktop' ? 2 : 3).click();
    await verificarTela(page, info, 'site-horarios');
    await page.locator('a.slot').first().click();
    await expect(page.getByRole('heading', { name: 'Confirme seu horário' })).toBeVisible();
    // R$ 45,00; depois que o cliente assina o plano (teste da assinatura), o corte sai de graça.
    await expect(page.locator('[data-total]')).toHaveText(/^R\$ (45,00|0,00)$/);
    await page.getByRole('button', { name: 'Confirmar agendamento' }).click();
    await expect(page.getByText(/Agendamento feito! Código AG-/)).toBeVisible();
    await verificarTela(page, info, 'cliente-agendado');
    if (info.project.name !== 'desktop') {
        expect(erros, 'erros de console/CSP').toEqual([]);
        return; // o do celular fica marcado (gera o lembrete da vespera)
    }

    await page.getByRole('link', { name: 'Remarcar' }).click();
    await page.locator('label.slot').last().click();
    await page.getByRole('button', { name: 'Remarcar para o horário escolhido' }).click();
    await expect(page.getByText('Agendamento remarcado.')).toBeVisible();
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Cancelar agendamento' }).click();
    const modal = page.getByRole('dialog', { name: 'Cancelar este horário?' });
    await modal.getByRole('button', { name: 'Cancelar agendamento' }).click();
    await expect(page.getByText('Agendamento cancelado.')).toBeVisible();
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('recepção: caixa, encaixe do cliente migrado, atendimento pago e comprovante por e-mail', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'caixa é um só: fluxo que grava roda no desktop');
    test.slow();
    const erros = observarErros(page);
    await entrarEquipe(page, 'homolog.recepcao');

    await page.goto('/painel/caixa');
    const abrir = page.getByRole('button', { name: 'Abrir caixa' });
    if (await abrir.isVisible()) {
        await page.getByLabel('Dinheiro na gaveta (valor inicial)').fill('100,00');
        await abrir.click();
    }
    await expect(page.getByRole('heading', { name: 'Caixa aberto' })).toBeVisible();
    await verificarTela(page, info, 'recepcao-caixa');

    await page.goto('/painel/atendimentos/novo');
    // A busca recarrega a tela: busca primeiro, escolhe o serviço depois.
    await page.getByLabel('Buscar cliente cadastrado').fill('Ensaio Um');
    await page.getByRole('button', { name: 'Buscar' }).click();
    await page.getByLabel('Cliente Ensaio Um').check();
    await page.getByLabel('Serviço').selectOption({ label: 'Corte Ensaio — R$ 45,00' });
    await page.getByLabel('Profissional').selectOption({ label: 'Barbeiro Ensaio' });
    await verificarTela(page, info, 'recepcao-encaixe');
    await page.getByRole('button', { name: 'Abrir atendimento' }).click();
    await expect(page).toHaveURL(/\/painel\/atendimentos\/AT-/);

    await page.getByRole('button', { name: 'Iniciar atendimento' }).click();
    await page.getByLabel('Vender produto').selectOption({ label: 'Pomada Ensaio — R$ 29,90' });
    await page.getByRole('button', { name: 'Incluir produto' }).click();
    await expect(page.locator('[data-total]')).toHaveText('R$ 74,90');
    await verificarTela(page, info, 'recepcao-atendimento');
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Concluir e receber' }).click();
    const modal = page.getByRole('dialog', { name: 'Concluir e receber' });
    await modal.locator('#pagamento-0-forma').selectOption('pix');
    await modal.locator('#pagamento-0-valor').fill('74,90');
    await modal.locator('#pagamento-0-gorjeta').fill('5,00');
    await modal.getByRole('button', { name: 'Confirmar conclusão' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Atendimento concluído. Total: R$ 74,90' })).toBeVisible();

    await page.getByRole('link', { name: 'Comprovante (imprimir ou e-mail)' }).click();
    await expect(page.getByLabel('Enviar por e-mail para')).toHaveValue('cliente.um@ensaio.test');
    await page.getByRole('button', { name: 'Enviar por e-mail' }).click();
    await expect(page.getByText(/enviado|fila/i).first()).toBeVisible();
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('gerente: aprova a avaliação migrada e registra entrada de estoque', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'grava dados compartilhados');
    const erros = observarErros(page);
    await entrarEquipe(page, 'homolog.gerente');
    // Avaliação do sistema antigo já era pública: entra publicada; o gerente responde.
    await page.goto('/painel/avaliacoes?situacao=approved');
    const cartao = page.locator('[data-review]').filter({ hasText: 'Atendimento ficticio otimo' });
    await expect(cartao).toBeVisible();
    await verificarTela(page, info, 'gerente-avaliacoes');
    await cartao.getByLabel('Responder').fill('Obrigado pela visita! (homologação)');
    await cartao.getByRole('button', { name: 'Publicar resposta' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Resposta publicada.' })).toBeVisible();

    await page.goto('/painel/produtos');
    await page.getByRole('link', { name: /Pomada Ensaio/ }).first().click();
    if (!/estoque$/.test(page.url())) await page.getByRole('link', { name: /Estoque/ }).first().click();
    const entrada = page.locator('form', { has: page.getByRole('button', { name: 'Registrar entrada' }) });
    await entrada.getByLabel('Quantidade').fill('6');
    await entrada.getByLabel('Custo unitário').fill('12,00');
    await page.getByRole('button', { name: 'Registrar entrada' }).click();
    await expect(page.getByRole('status').first()).toBeVisible();
    await verificarTela(page, info, 'gerente-estoque');
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('financeiro: extrato do barbeiro migrado e repasse', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'grava dados compartilhados');
    const erros = observarErros(page);
    await entrarEquipe(page, 'homolog.financeiro');
    await page.goto('/painel/comissoes');
    await expect(page.getByText('Barbeiro Ensaio').first()).toBeVisible();
    await verificarTela(page, info, 'financeiro-comissoes');
    await page.getByRole('link', { name: /Barbeiro Ensaio/ }).first().click();
    await verificarTela(page, info, 'financeiro-extrato');
    const repasse = page.getByRole('button', { name: /Registrar repasse de R\$/ });
    if (await repasse.count()) {
        await page.getByLabel('Forma de pagamento').selectOption('pix');
        await repasse.click();
        await expect(page.getByRole('status').first()).toContainText(/[Rr]epasse/);
    }
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('proprietário: link de assinatura pago no checkout ativa a assinatura', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'ciclo do Stripe (simulado) uma vez');
    test.slow();
    await entrarEquipe(page, 'ensaio-admin', ADMIN_ANTIGA);
    await page.goto('/painel/assinaturas/link?busca=Ensaio+Um');
    await verificarTela(page, info, 'assinatura-link');
    // Rodada repetida: o cliente ja ficou com a assinatura ativa na anterior.
    await page.goto('/painel/assinaturas?busca=cliente.um%40ensaio.test');
    if (await page.locator('tr').filter({ hasText: 'Cliente Ensaio Um' }).filter({ hasText: 'Ativa' }).count()) return;
    await page.goto('/painel/assinaturas/link?busca=Ensaio+Um');
    const linha = page.locator('li').filter({ hasText: 'Cliente Ensaio Um' });
    await linha.getByRole('button', { name: 'Gerar link' }).click();
    await expect(page.getByText('Link de pagamento gerado.')).toBeVisible();
    const url = await page.locator('input[value*="/pay/cs_test_"]').first().inputValue();
    await page.goto(url);
    await page.getByRole('button', { name: /Pagar/ }).click();
    // Volta para a conta do cliente (success_url); o webhook chega logo depois.
    await expect(page).toHaveURL(/\/minha-conta\/assinatura|\/entrar/);
    await expect.poll(async () => {
        await page.goto('/painel/assinaturas?busca=cliente.um%40ensaio.test');
        return page.locator('tr').filter({ hasText: 'Cliente Ensaio Um' }).filter({ hasText: 'Ativa' }).count();
    }, { timeout: 30_000 }).toBe(1);
    await verificarTela(page, info, 'assinaturas');
});

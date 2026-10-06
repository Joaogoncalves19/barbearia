// Capturas para a revisao visual (ver playwright.visual.config.js). Cada
// tela vira docs/reconstrucao/img/redesign/<CAPTURA>/<area>/<nome>-<aparelho>.jpg.
// Paginas com registro (agendamento, atendimento, caixa...) sao descobertas
// pelo primeiro link da lista, sem ids fixos.
import { test } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const FASE = process.env.CAPTURA || 'antes';
const SENHA = process.env.DEMO_PASSWORD;
const RAIZ = '../docs/reconstrucao/img/redesign';

async function capturar(page, info, area, nome) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.evaluate(() => document.fonts && document.fonts.ready);
    const pasta = `${RAIZ}/${FASE}/${area}`;
    mkdirSync(pasta, { recursive: true });
    await page.screenshot({ path: `${pasta}/${nome}-${info.project.name}.jpg`, fullPage: true, type: 'jpeg', quality: 70, scale: 'css' });
}

async function visitar(page, info, area, nome, url) {
    await page.goto(url);
    await capturar(page, info, area, nome);
}

/** Primeiro link da pagina atual cujo href combina com o padrao (ou null). */
async function primeiroLink(page, padrao) {
    const hrefs = await page.locator('a[href]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    return hrefs.find((h) => padrao.test(h ?? '')) ?? null;
}

test('site público e acesso', async ({ page }, info) => {
    const a = 'publico';
    await visitar(page, info, a, '01-inicio', '/');
    await visitar(page, info, a, '02-servicos', '/servicos');
    await visitar(page, info, a, '03-equipe', '/equipe');
    await visitar(page, info, a, '04-profissional', '/equipe/rafael-moura');
    await visitar(page, info, a, '05-assinatura', '/assinatura');
    await visitar(page, info, a, '06-agendar-servico', '/agendar');
    await visitar(page, info, a, '07-agendar-profissional', '/agendar/corte');
    await visitar(page, info, a, '08-agendar-horario', '/agendar/corte/horarios?profissional=rafael-moura');
    await visitar(page, info, a, '09-erro-404', '/pagina-que-nao-existe');

    const b = 'acesso';
    await visitar(page, info, b, '01-entrar-cliente', '/entrar');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await capturar(page, info, b, '02-entrar-cliente-erro');
    await visitar(page, info, b, '03-cadastro', '/cadastro');
    await visitar(page, info, b, '04-esqueci-senha-cliente', '/esqueci-a-senha');
    await visitar(page, info, b, '05-link-de-acesso', '/entrar/link');
    await visitar(page, info, b, '06-entrar-equipe', '/painel/entrar');
    await visitar(page, info, b, '07-esqueci-senha-equipe', '/painel/esqueci-a-senha');
});

test('painel da equipe', async ({ page }, info) => {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill('dono');
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await page.waitForURL(/\/painel$/);

    const a = 'painel';
    await capturar(page, info, a, '01-inicio');
    await visitar(page, info, 'agenda', '01-agenda-dia', '/painel/agenda');
    const ag = await primeiroLink(page, /\/painel\/agendamentos\/AG-/);
    if (ag) await visitar(page, info, 'agenda', '03-agendamento', ag);
    await visitar(page, info, 'agenda', '04-novo-agendamento', '/painel/agenda/novo');
    await visitar(page, info, 'agenda', '05-configuracoes', '/painel/agenda/configuracoes');
    await visitar(page, info, 'agenda', '06-folgas', '/painel/folgas');
    await visitar(page, info, 'agenda', '07-bloqueios', '/painel/bloqueios');

    await visitar(page, info, 'caixa', '01-atendimentos', '/painel/atendimentos');
    const at = await primeiroLink(page, /\/painel\/atendimentos\/AT-/);
    if (at) await visitar(page, info, 'caixa', '02-atendimento', at);
    await visitar(page, info, 'caixa', '03-novo-atendimento', '/painel/atendimentos/novo');
    await visitar(page, info, 'caixa', '04-caixa', '/painel/caixa');
    const sessao = await primeiroLink(page, /\/painel\/caixa\/\d+/);
    if (sessao) await visitar(page, info, 'caixa', '05-caixa-sessao', sessao);

    await visitar(page, info, 'catalogo', '01-servicos', '/painel/servicos');
    const servico = await primeiroLink(page, /\/painel\/servicos\/[^/]+\/editar/);
    if (servico) await visitar(page, info, 'catalogo', '02-servico-editar', servico);
    await visitar(page, info, 'catalogo', '03-categorias', '/painel/categorias');
    await visitar(page, info, 'catalogo', '04-produtos', '/painel/produtos');
    const estoque = await primeiroLink(page, /\/painel\/produtos\/[^/]+\/estoque/);
    if (estoque) await visitar(page, info, 'catalogo', '05-estoque', estoque);
    await visitar(page, info, 'catalogo', '06-profissionais', '/painel/profissionais');
    const pro = await primeiroLink(page, /\/painel\/profissionais\/[^/]+$/);
    if (pro) await visitar(page, info, 'catalogo', '07-profissional', pro);

    await visitar(page, info, 'financeiro', '01-comissoes', '/painel/comissoes');
    const com = await primeiroLink(page, /\/painel\/comissoes\/[^/]+$/);
    if (com) await visitar(page, info, 'financeiro', '02-comissao-profissional', com);
    await visitar(page, info, 'financeiro', '03-regras', '/painel/comissoes/regras');
    await visitar(page, info, 'financeiro', '04-repasses', '/painel/repasses');
    await visitar(page, info, 'financeiro', '05-historico', '/painel/comissoes/historico');

    await visitar(page, info, 'promocoes', '01-cupons', '/painel/cupons');
    await visitar(page, info, 'promocoes', '02-cupom-novo', '/painel/cupons/novo');
    await visitar(page, info, 'promocoes', '03-vales', '/painel/vales-presente');
    await visitar(page, info, 'promocoes', '04-fidelidade', '/painel/fidelidade');
    await visitar(page, info, 'promocoes', '05-pontos-clientes', '/painel/fidelidade/clientes');

    await visitar(page, info, 'assinaturas', '01-assinaturas', '/painel/assinaturas');
    const assin = await primeiroLink(page, /\/painel\/assinaturas\/[0-9A-Z]{20,}/);
    if (assin) await visitar(page, info, 'assinaturas', '02-assinatura', assin);
    await visitar(page, info, 'assinaturas', '03-planos', '/painel/planos');

    await visitar(page, info, 'comunicacao', '01-campanhas', '/painel/campanhas');
    await visitar(page, info, 'comunicacao', '02-campanha-nova', '/painel/campanhas/nova');
    await visitar(page, info, 'comunicacao', '03-emails', '/painel/emails');
    await visitar(page, info, 'comunicacao', '04-avaliacoes', '/painel/avaliacoes');
    await visitar(page, info, 'comunicacao', '05-configuracoes', '/painel/comunicacao');

    await visitar(page, info, 'sistema', '01-usuarios', '/painel/usuarios');
    await visitar(page, info, 'sistema', '02-usuario-novo', '/painel/usuarios/novo');
    await visitar(page, info, 'sistema', '03-auditoria', '/painel/auditoria');
    await visitar(page, info, 'sistema', '04-site', '/painel/site');
    await visitar(page, info, 'sistema', '05-site-imagens', '/painel/site/imagens');
    await visitar(page, info, 'sistema', '06-minha-conta', '/painel/minha-conta');
});

test('área do cliente', async ({ page }, info) => {
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill('cliente@barbearia.test');
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(/\/minha-conta/);

    const a = 'cliente';
    await capturar(page, info, a, '01-inicio');
    await visitar(page, info, a, '02-agendamentos', '/minha-conta/agendamentos');
    const ag = await primeiroLink(page, /\/minha-conta\/agendamentos\/AG-/);
    if (ag) {
        await visitar(page, info, a, '03-horario', ag);
        await visitar(page, info, a, '04-remarcar', `${ag}/remarcar`);
    }
    await visitar(page, info, a, '05-comprovantes', '/minha-conta/comprovantes');
    const comp = await primeiroLink(page, /\/minha-conta\/atendimentos\/AT-/);
    if (comp) await visitar(page, info, a, '06-comprovante', comp);
    await visitar(page, info, a, '07-beneficios', '/minha-conta/fidelidade');
    await visitar(page, info, a, '08-assinatura', '/minha-conta/assinatura');
    await visitar(page, info, a, '09-avaliacoes', '/minha-conta/avaliacoes');
    await visitar(page, info, a, '10-avisos', '/minha-conta/avisos');
    await visitar(page, info, a, '11-meus-dados', '/minha-conta/dados');
    await visitar(page, info, a, '12-privacidade', '/minha-conta/privacidade');
    await visitar(page, info, a, '13-senha', '/minha-conta/senha');
    await visitar(page, info, a, '14-confirmar-senha', '/minha-conta/confirmar-senha');
});

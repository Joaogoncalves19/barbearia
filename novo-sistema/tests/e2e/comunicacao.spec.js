// Fase 10 — comunicação e avaliações no navegador (celular e desktop). Nada sai
// da máquina: os e-mails ficam na fila local (sem provedor neste ambiente).
// Cliente (atendimento concluído ontem): avalia (com erro de validação e
// comentário com HTML, que aparece como texto), escolhe as preferências de
// e-mail e vê os avisos. Dono: aprova e responde a avaliação, cria campanha
// (rascunho + teste), consulta o registro de e-mails e a configuração.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase10-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

async function entrarNaConta(page, email) {
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/minha-conta/);
}

test('cliente avalia, escolhe preferências; dono modera e responde', async ({ page }, info) => {
    test.slow();
    const s = info.project.name;
    const marca = `E2E ${s} ${Date.now().toString(36)}`;
    const comentario = `<b>Ótimo</b> atendimento ${marca}`;

    // --- Cliente ---
    const pc = page;
    const erros = observarErros(page);
    await entrarNaConta(pc, `e2e-comunica-cliente-${s}@exemplo.test`);

    await pc.goto('/minha-conta/avaliacoes');
    await expect(pc.getByRole('heading', { name: 'Avaliações', exact: true })).toBeVisible();
    await verificarTela(pc, info, 'Avaliações do cliente');
    await pc.getByRole('link', { name: /Avaliar/ }).first().click();
    await expect(pc.getByRole('heading', { name: 'Como foi o seu atendimento?' })).toBeVisible();
    await pc.getByRole('button', { name: 'Enviar avaliação' }).click();
    await expect(pc.getByText('Escolha uma nota de 1 a 5.')).toBeVisible();
    await verificarTela(pc, info, 'Avaliar atendimento');
    await pc.getByLabel('5 estrelas').check();
    await pc.getByLabel('Comentário').fill(comentario);
    await pc.getByRole('button', { name: 'Enviar avaliação' }).click();
    await expect(pc.getByText('Obrigado pela avaliação!')).toBeVisible();
    await expect(pc.getByText(comentario)).toBeVisible(); // texto, nunca HTML
    await expect(pc.locator('b', { hasText: 'Ótimo' })).toHaveCount(0);
    await expect(pc.getByText('Em revisão').first()).toBeVisible();

    await pc.goto('/minha-conta/dados');
    await expect(pc.getByText('Você ainda não escolheu.')).toBeVisible();
    await pc.getByRole('radio', { name: 'Quero receber', exact: true }).check();
    await pc.getByRole('button', { name: 'Salvar preferências' }).click();
    await expect(pc.getByText(/Preferências salvas: novidades por e-mail ligadas/)).toBeVisible();
    await expect(pc.getByRole('radio', { name: 'Quero receber', exact: true })).toBeChecked();
    await verificarTela(pc, info, 'Preferências de e-mail');

    await pc.goto('/minha-conta/avisos');
    await expect(pc.getByRole('heading', { name: 'Avisos' })).toBeVisible();
    await verificarTela(pc, info, 'Avisos');

    // --- Dono (outra sessao: sai da conta do cliente) ---
    await page.context().clearCookies();
    const pd = page;
    await entrarNoPainel(pd, `e2e-comunica-${s}`);

    await pd.goto('/painel/avaliacoes');
    const cartao = pd.locator('[data-review]').filter({ hasText: marca });
    await expect(cartao).toBeVisible();
    await expect(cartao.getByText(comentario)).toBeVisible();
    await verificarTela(pd, info, 'Avaliações aguardando');
    await cartao.getByRole('button', { name: /Aprovar/ }).click();
    await expect(pd.getByRole('status').filter({ hasText: 'Avaliação aprovada e publicada.' })).toBeVisible();

    await pd.goto('/painel/avaliacoes?situacao=approved');
    const publicada = pd.locator('[data-review]').filter({ hasText: marca });
    await publicada.getByLabel('Responder').fill(`Obrigado! ${marca}`);
    await publicada.getByRole('button', { name: 'Publicar resposta' }).click();
    await expect(pd.getByRole('status').filter({ hasText: 'Resposta publicada.' })).toBeVisible();
    await expect(pd.locator('[data-review]').filter({ hasText: marca }).getByText(`Obrigado! ${marca}`).first()).toBeVisible();
    await verificarTela(pd, info, 'Avaliações publicadas');
    expect(erros, 'erros de console/CSP').toEqual([]);
});

test('dono: campanha em rascunho com teste, registro de e-mails e configuração', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const s = info.project.name;
    const nome = `Campanha E2E ${s} ${Date.now().toString(36)}`;
    await entrarNoPainel(page, `e2e-comunica-${s}`);

    await page.goto('/painel/campanhas');
    await expect(page.getByRole('heading', { name: 'Campanhas', exact: true })).toBeVisible();
    await verificarTela(page, info, 'Campanhas');
    await page.getByRole('link', { name: 'Nova campanha' }).click();
    await page.getByLabel('Nome interno').fill(nome);
    await page.getByRole('button', { name: 'Salvar rascunho' }).click();
    await expect(page.locator('.field__error').first()).toBeVisible();
    await verificarTela(page, info, 'Nova campanha');
    await page.getByLabel('Assunto do e-mail').fill('Novidade, {primeiro_nome}!');
    await page.getByLabel('Texto').fill('Oi, {primeiro_nome}!\n\nTemos horário livre nesta semana.');
    await page.getByRole('button', { name: 'Salvar rascunho' }).click();
    await expect(page.getByRole('heading', { name: nome })).toBeVisible();
    await expect(page.getByText('Oi, Maria!')).toBeVisible();
    await expect(page.locator('[data-audience]')).toBeVisible();
    await page.getByRole('button', { name: 'Enviar teste para mim' }).click();
    await expect(page.getByRole('status').filter({ hasText: /Teste enviado para o seu e-mail/ })).toBeVisible();
    await verificarTela(page, info, 'Campanha rascunho');

    await page.goto('/painel/emails');
    await expect(page.getByRole('heading', { name: 'E-mails enviados' })).toBeVisible();
    const linha = page.locator('tr[data-email]').filter({ hasText: 'Campanha (teste para a equipe)' }).first();
    await expect(linha).toBeVisible();
    await expect(linha.getByText(/e2\*\*\*@barbearia\.test/)).toBeVisible(); // endereço mascarado
    await verificarTela(page, info, 'Registro de e-mails');

    await page.goto('/painel/comunicacao');
    await expect(page.getByRole('heading', { name: 'Lembretes e avisos' })).toBeVisible();
    await page.getByRole('button', { name: 'Salvar' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Configuração salva.' })).toBeVisible();
    await verificarTela(page, info, 'Configuração de lembretes');

    expect(erros, 'erros de console/CSP').toEqual([]);
});

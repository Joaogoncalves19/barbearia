// (Roda depois de homologacao.spec.js: precisa dos usuarios criados la; ordem alfabetica.)
// Fase 13 — homologacao dos E-MAILS que dependem de acao de alguem: aceite de
// novidades, troca de e-mail, redefinicao de senha, link magico, remarcacao,
// campanha (com descadastro de um clique depois) e falha de pagamento da
// assinatura. Os e-mails saem pela fila real (agendador) para o receptor SMTP
// de ensaio; o conteudo e conferido pelos arquivos .eml.
import { test, expect } from '@playwright/test';

const CONTAS_ANTIGAS = process.env.HOMOLOG_SENHA_CONTAS_ANTIGAS;
const PESSOAL = `${process.env.HOMOLOG_SENHA_PROVISORIA}N`;

test.describe.configure({ mode: 'serial' });

async function entrarCliente(page) {
    await page.context().clearCookies();
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill('cliente.um@ensaio.test');
    await page.getByLabel('Senha', { exact: true }).fill(CONTAS_ANTIGAS);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/minha-conta/);
}

test('cliente: aceita novidades, pede troca de e-mail e remarca', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'uma vez só');
    test.slow();
    await entrarCliente(page);
    await page.goto('/minha-conta/dados');
    await page.getByLabel('Quero receber', { exact: true }).check();
    await page.getByRole('button', { name: 'Salvar preferências' }).click();
    await expect(page.getByRole('status').first()).toBeVisible();

    await page.goto('/minha-conta/email');
    if (/confirmar-senha/.test(page.url())) {
        await page.getByLabel('Sua senha').fill(CONTAS_ANTIGAS);
        await page.getByRole('button', { name: 'Confirmar' }).click();
        await page.goto('/minha-conta/email');
    }
    await page.getByLabel('Novo e-mail').fill('cliente.um.novo@ensaio.test');
    await page.getByRole('button', { name: 'Enviar link de confirmação' }).click();
    await expect(page.getByText(/cliente\.um\.novo@ensaio\.test/).first()).toBeVisible();
    // Cancela o pedido: o e-mail da conta continua o mesmo para os outros roteiros.
    await page.goto('/minha-conta/dados');
    await page.getByRole('button', { name: 'Cancelar pedido' }).click();

    // Agenda e remarca (fica marcado: a remarcacao chega por e-mail).
    await page.goto('/agendar');
    await page.getByRole('link', { name: /Barba Ensaio/ }).first().click();
    const pro = page.getByRole('link', { name: /Barbeiro Ensaio/ });
    if (await pro.count()) await pro.first().click();
    await page.getByRole('navigation', { name: 'Dias disponíveis' }).getByRole('link').nth(4).click();
    await page.locator('a.slot').first().click();
    await page.getByRole('button', { name: 'Confirmar agendamento' }).click();
    await expect(page.getByText(/Agendamento feito! Código AG-/)).toBeVisible();
    // Deixa a confirmacao sair antes de remarcar (o agendador roda a cada minuto).
    await page.waitForTimeout(70_000);
    await page.getByRole('link', { name: 'Remarcar' }).click();
    await page.locator('label.slot').last().click();
    await page.getByRole('button', { name: 'Remarcar para o horário escolhido' }).click();
    await expect(page.getByText('Agendamento remarcado.')).toBeVisible();
});

test('cliente sem sessão: esqueci a senha e link mágico', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'uma vez só');
    await page.context().clearCookies();
    await page.goto('/esqueci-a-senha');
    await page.getByLabel('E-mail').fill('cliente.um@ensaio.test');
    await page.getByRole('button').filter({ hasText: /Enviar|link/i }).first().click();
    await expect(page.getByRole('status').first()).toBeVisible();
    await page.goto('/entrar/link');
    await page.getByLabel('E-mail').fill('cliente.um@ensaio.test');
    await page.getByRole('button').filter({ hasText: /Enviar|link/i }).first().click();
    await expect(page.getByRole('status').first()).toBeVisible();
});

test('gerente: campanha para quem aceitou novidades', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'uma vez só');
    await page.context().clearCookies();
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill('homolog.gerente');
    await page.getByLabel('Senha', { exact: true }).fill(PESSOAL);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await page.goto('/painel/campanhas/nova');
    await page.getByLabel('Nome interno').fill('Homologação');
    await page.getByLabel('Assunto do e-mail').fill('Novidade da Barbearia Ensaio');
    await page.getByLabel('Texto').fill('Olá, {nome}! Esta é uma campanha de homologação (dados fictícios).');
    await page.getByRole('button', { name: 'Salvar rascunho' }).click();
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Disparar' }).first().click();
    const modal = page.getByRole('dialog');
    await modal.getByLabel('Revisei o texto, o assunto e o público').check();
    await modal.getByRole('button', { name: 'Disparar' }).click();
    await expect(page.getByRole('status').first()).toBeVisible();
    await page.screenshot({ path: `storage/e2e/homologacao/${info.project.name}-campanha.png`, fullPage: true });
});

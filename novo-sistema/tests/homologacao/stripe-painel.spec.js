// Fase 13 — homologacao: o que o PAINEL pede ao Stripe (simulado), com a
// resposta chegando por webhook depois: cancelar no fim do periodo, reativar,
// reembolsar um pagamento e cancelar imediatamente. Roda so no desktop, depois
// de homologacao.spec.js (o cliente ja tem a assinatura paga pelo checkout).
import { test, expect } from '@playwright/test';

const ADMIN_ANTIGA = process.env.HOMOLOG_SENHA_ADMIN_ANTIGA;

test('proprietário: cancelar no fim, reativar, reembolsar e cancelar agora', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'uma vez só');
    test.slow();
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill('ensaio-admin');
    await page.getByLabel('Senha', { exact: true }).fill(ADMIN_ANTIGA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);

    await page.goto('/painel/assinaturas?busca=cliente.um%40ensaio.test');
    await page.getByRole('link', { name: 'Cliente Ensaio Um' }).first().click();
    const pagina = page.url();
    const situacao = async (texto) => expect.poll(async () => {
        await page.goto(pagina);
        return page.locator('main').innerText();
    }, { timeout: 30_000 }).toContain(texto);

    const cancelar = async (modo, motivo) => {
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Cancelar assinatura' }).first().click();
        const modal = page.getByRole('dialog', { name: 'Cancelar esta assinatura?' });
        await modal.getByLabel(modo).check();
        await modal.getByLabel('Motivo').fill(motivo);
        await modal.getByRole('button', { name: 'Cancelar assinatura' }).click();
    };

    await cancelar('No fim do período pago', 'Ensaio: cliente pediu para não renovar');
    await situacao('Cancelamento agendado');

    await page.getByRole('button', { name: 'Reativar (desfazer cancelamento)' }).click();
    await situacao('Ativa');

    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Reembolsar' }).first().click();
    const reembolso = page.getByRole('dialog').filter({ has: page.getByLabel('Valor') }).first();
    await reembolso.getByLabel('Valor').fill('10,00');
    await reembolso.getByLabel('Motivo').fill('Ensaio: reembolso parcial');
    await reembolso.getByRole('button', { name: 'Reembolsar' }).click();
    await situacao('10,00');

    await cancelar('Imediatamente', 'Ensaio: encerrar agora');
    await situacao('Cancelada');
    await page.screenshot({ path: `storage/e2e/homologacao/${info.project.name}-assinatura-cancelada.png`, fullPage: true });
});

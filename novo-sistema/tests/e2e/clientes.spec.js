// Fase 13 (P13-01) — tela Clientes do painel, no celular e no desktop.
// Recepção: acha o cliente pela busca, vê a ficha (CPF mascarado, anotação,
// atendimento) e edita o celular. Financeiro: sem acesso (matriz). Dono:
// anonimiza um cliente com senha reconfirmada e a palavra de confirmação.
// Em cada tela: axe sem violação grave, sem erro de console/CSP e sem rolagem
// lateral.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-clientes-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

test('recepção busca, abre a ficha e edita o celular', async ({ page }, info) => {
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNoPainel(page, `e2e-clientes-rec-${s}`);

    // O menu leva à tela (no celular o menu fica recolhido).
    await expect(page.locator('a[href$="/painel/clientes"]').first()).toBeAttached();
    await page.goto('/painel/clientes');
    await expect(page.getByRole('heading', { name: 'Clientes', exact: true })).toBeVisible();
    await verificarTela(page, info, 'lista');

    await page.getByLabel('Nome, e-mail ou celular').fill(`Cliente Ficha ${s}`);
    await page.getByRole('button', { name: 'Buscar' }).click();
    await expect(page.locator('[data-customer-row]')).toHaveCount(1);
    await page.getByRole('link', { name: `Cliente Ficha ${s}` }).click();

    await expect(page.getByRole('heading', { name: `Cliente Ficha ${s}` })).toBeVisible();
    await expect(page.locator('[data-cpf]')).toHaveText(/\d{3}\.\*\*\*\.\*\*\*-\d{2}/);
    await expect(page.getByText('Pele sensível: usar navalha nova')).toBeVisible();
    await expect(page.locator('[data-attendance-row]').first()).toBeVisible();
    await expect(page.getByText('Anonimizar cadastro')).toHaveCount(0);
    await verificarTela(page, info, 'ficha');

    await page.getByRole('link', { name: 'Editar' }).click();
    await expect(page.getByLabel('CPF')).toHaveCount(0);
    await verificarTela(page, info, 'editar');
    const celular = s === 'desktop' ? '(11) 97000-1001' : '(11) 97000-1002';
    await page.getByLabel('Celular').fill(celular);
    await page.getByRole('button', { name: 'Salvar' }).click();
    await expect(page.getByText('Cadastro atualizado.')).toBeVisible();
    await expect(page.locator('[data-customer-phone]')).toHaveText(s === 'desktop' ? '+5511970001001' : '+5511970001002');

    expect(erros, 'sem erro de console ou CSP').toEqual([]);
});

test('financeiro não vê clientes nem o cadastro', async ({ page }, info) => {
    await entrarNoPainel(page, `e2e-clientes-fin-${info.project.name}`);
    await expect(page.locator('a[href$="/painel/clientes"]')).toHaveCount(0);
    for (const url of ['/painel/clientes', '/painel/clientes/novo']) {
        const r = await page.goto(url);
        expect(r.status(), url).toBe(403);
    }
});

/** CPF valido e novo a cada execucao (digitos verificadores calculados). */
function cpfNovo() {
    const d = Array.from({ length: 9 }, () => Math.floor(Math.random() * 10));
    for (const t of [9, 10]) {
        const soma = d.reduce((s, v, i) => s + v * (t + 1 - i), 0);
        d.push(((10 * soma) % 11) % 10);
    }
    return d.join('');
}

test('recepção cadastra cliente no balcão, desativa e reativa', async ({ page }, info) => {
    const s = info.project.name;
    const erros = observarErros(page);
    const nome = `Cliente Balcão ${s} ${Date.now()}`;
    await entrarNoPainel(page, `e2e-clientes-rec-${s}`);

    await page.goto('/painel/clientes');
    await page.getByRole('link', { name: 'Novo cliente' }).click();
    await expect(page.getByRole('heading', { name: 'Novo cliente' })).toBeVisible();
    await expect(page.getByLabel('Senha')).toHaveCount(0);
    await verificarTela(page, info, 'novo');

    // CPF obrigatorio: a tela recusa e mantem o que foi digitado.
    await page.getByLabel('Nome').fill(nome);
    await page.getByRole('button', { name: 'Cadastrar cliente' }).click();
    await expect(page.getByText(/CPF.*obrigat/i)).toBeVisible();
    await expect(page.getByLabel('Nome')).toHaveValue(nome);
    await page.getByLabel('CPF').fill('123.456.789-00');
    await page.getByRole('button', { name: 'Cadastrar cliente' }).click();
    await expect(page.getByText('Informe um CPF válido.')).toBeVisible();
    await verificarTela(page, info, 'novo-com-erro');

    await page.getByLabel('CPF').fill(cpfNovo());
    await page.getByRole('button', { name: 'Cadastrar cliente' }).click();
    await expect(page.getByText(/Cliente cadastrado, sem e-mail/)).toBeVisible();
    await expect(page.getByRole('heading', { name: nome })).toBeVisible();
    await expect(page.locator('[data-cpf]')).toHaveText(/\d{3}\.\*\*\*\.\*\*\*-\d{2}/);

    await page.goto(`/painel/clientes?busca=${encodeURIComponent(nome)}`);
    await expect(page.locator('[data-customer-row]')).toHaveCount(1);
    await page.getByRole('link', { name: nome }).click();

    await page.getByRole('button', { name: 'Desativar', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await verificarTela(page, info, 'desativar');
    await page.getByRole('button', { name: 'Desativar cadastro' }).click();
    await expect(page.getByText(/Cadastro desativado/)).toBeVisible();
    await expect(page.locator('[data-customer-status]')).toHaveText('Inativo');

    await page.getByRole('button', { name: 'Reativar' }).click();
    await expect(page.getByText(/Cadastro reativado/)).toBeVisible();
    await expect(page.locator('[data-customer-status]')).toHaveText('Ativo');

    expect(erros, 'sem erro de console ou CSP').toEqual([]);
});

test('dono anonimiza com senha reconfirmada', async ({ page }, info) => {
    const s = info.project.name;
    const erros = observarErros(page);
    await entrarNoPainel(page, `e2e-clientes-dono-${s}`);

    await page.goto(`/painel/clientes?busca=${encodeURIComponent(`Cliente Anonimizar ${s}`)}`);
    await page.getByRole('link', { name: `Cliente Anonimizar ${s}` }).click();
    await expect(page.locator('[data-cpf]')).toHaveText(/\d{3}\.\d{3}\.\d{3}-\d{2}/);
    await page.getByRole('link', { name: 'Anonimizar cadastro' }).click();

    await expect(page).toHaveURL(/\/painel\/confirmar-senha$/);
    await page.getByLabel('Sua senha').fill(SENHA);
    await page.getByRole('button', { name: 'Confirmar' }).click();

    await expect(page.getByRole('heading', { name: `Anonimizar Cliente Anonimizar ${s}` })).toBeVisible();
    await verificarTela(page, info, 'anonimizar');
    await page.getByLabel('Digite ANONIMIZAR para confirmar').fill('ANONIMIZAR');
    await page.getByRole('button', { name: 'Anonimizar cadastro' }).click();

    await expect(page.getByText('Cadastro anonimizado.', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Cliente removido' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Editar' })).toHaveCount(0);
    await verificarTela(page, info, 'anonimizado');

    expect(erros, 'sem erro de console ou CSP').toEqual([]);
});

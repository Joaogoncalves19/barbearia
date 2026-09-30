// Fase 5 — agenda e agendamento no navegador (celular e desktop).
// Cliente: servico -> profissional -> dia -> horario -> login -> confirmar ->
// ver -> remarcar -> cancelar. Equipe: agenda do dia, criar, remarcar,
// cancelar. Profissional: so a propria agenda. Em cada tela: axe, sem erro
// de console/CSP e sem rolagem lateral. Os horarios vem do servidor.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase5-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

// Cada projeto usa um barbeiro, para os dois rodarem em paralelo sem disputar horario.
const barbeiro = (info) => (info.project.name === 'celular' ? 'Barbeiro A celular' : 'Barbeiro B desktop');

test('cliente: agenda pelo site, vê, remarca e cancela', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);

    // 1. Servico (sem login)
    await page.goto('/agendar');
    await verificarTela(page, info, 'Agendar - serviço');
    await page.getByRole('link', { name: /Corte E2E/ }).click();

    // 2. Profissional
    await expect(page.getByRole('heading', { name: 'Com quem?' })).toBeVisible();
    await verificarTela(page, info, 'Agendar - profissional');
    await page.getByRole('link', { name: barbeiro(info) }).click();

    // 3. Dia (o 3o dia disponivel, longe da antecedencia minima) e horario
    await expect(page.getByRole('heading', { name: 'Escolha o dia e o horário' })).toBeVisible();
    await page.getByRole('navigation', { name: 'Dias disponíveis' }).getByRole('link').nth(2).click();
    await verificarTela(page, info, 'Agendar - horários');
    const primeiro = page.locator('a.slot').first();
    const hora = (await primeiro.innerText()).trim();
    await primeiro.click();

    // 4. Login e volta para a confirmacao
    await expect(page).toHaveURL(/\/entrar$/);
    await page.getByLabel('E-mail').fill(`e2e-cliente-${info.project.name}@exemplo.test`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Confirme seu horário' })).toBeVisible();
    await expect(page.getByText('R$ 50,00')).toBeVisible();
    await verificarTela(page, info, 'Agendar - confirmação');
    await page.getByRole('button', { name: 'Confirmar agendamento' }).click();

    await expect(page.getByText(/Agendamento feito! Código AG-/)).toBeVisible();
    await expect(page.getByText(hora).first()).toBeVisible();
    await verificarTela(page, info, 'Meu agendamento');

    // Remarcar para o ultimo horario livre do mesmo dia
    await page.getByRole('link', { name: 'Remarcar' }).click();
    await expect(page.getByRole('heading', { name: 'Remarcar' })).toBeVisible();
    await verificarTela(page, info, 'Remarcar');
    const opcoes = page.locator('label.slot');
    const novo = (await opcoes.last().innerText()).trim();
    await opcoes.last().click();
    await page.getByRole('button', { name: 'Remarcar para o horário escolhido' }).click();
    await expect(page.getByText('Agendamento remarcado.')).toBeVisible();
    await expect(page.getByText(novo).first()).toBeVisible();

    // Cancelar: modal pede confirmacao; Esc cancela e devolve o foco
    await page.waitForLoadState('networkidle');
    const cancelar = page.getByRole('button', { name: 'Cancelar agendamento' });
    await cancelar.click();
    const modal = page.getByRole('dialog', { name: 'Cancelar este horário?' });
    await expect(modal).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(cancelar).toBeFocused();
    await cancelar.click();
    await modal.getByRole('button', { name: 'Cancelar agendamento' }).click();
    await expect(page.getByText('Agendamento cancelado.')).toBeVisible();
    await expect(page.getByText('Cancelado', { exact: true })).toBeVisible();

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('equipe: agenda do dia, cria, remarca e cancela', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const cliente = `Passante ${info.project.name} ${Date.now().toString(36)}`;
    await entrarNoPainel(page, `e2e-gerente-${info.project.name}`);

    await page.goto('/painel/agenda');
    await expect(page.getByRole('heading', { name: 'Agenda', exact: true })).toBeVisible();
    await verificarTela(page, info, 'Agenda do dia');

    // Criar
    await page.getByRole('link', { name: 'Novo agendamento' }).click();
    await page.getByLabel('Serviço').selectOption({ label: 'E2E · Corte E2E (30 min, R$ 50,00)' });
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByLabel('Profissional').selectOption({ label: barbeiro(info) });
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByRole('navigation', { name: 'Dias disponíveis' }).getByRole('link').nth(3).click();
    await verificarTela(page, info, 'Novo agendamento (equipe)');

    // Sem horario escolhido: o navegador ou o servidor recusa com mensagem
    await page.getByLabel('Nome do cliente').fill(cliente);
    await page.locator('label.slot').first().click();
    await page.getByRole('button', { name: 'Agendar', exact: true }).click();
    await expect(page.getByText(/Agendamento AG-\w+ criado\./)).toBeVisible();
    await expect(page.getByRole('heading', { name: cliente })).toBeVisible();
    await verificarTela(page, info, 'Agendamento (equipe)');

    // Remarcar
    await page.getByRole('link', { name: 'Remarcar' }).click();
    await page.locator('label.slot').nth(2).click();
    await page.getByRole('button', { name: 'Remarcar', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Agendamento remarcado.' })).toBeVisible();
    await expect(page.locator('.timeline')).toContainText('Agendamento remarcado.'); // historico: de -> para
    await expect(page.locator('.timeline')).toContainText('para ');

    // Cancelar com motivo (modal)
    // O modal depende do JS da pagina: espera carregar (servidor embutido e lento em paralelo).
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    const modal = page.getByRole('dialog', { name: 'Cancelar este agendamento?' });
    await expect(modal).toBeVisible();
    await modal.getByLabel('Motivo').fill('Teste automatizado');
    await modal.getByRole('button', { name: 'Cancelar agendamento' }).click();
    await expect(page.getByText('Agendamento cancelado. O registro continua no histórico.')).toBeVisible();
    await expect(page.getByText('Motivo: Teste automatizado')).toBeVisible();

    // Telas de configuracao
    for (const [nome, url] of [['Funcionamento', '/painel/agenda/configuracoes'], ['Folgas', '/painel/folgas'], ['Bloqueios', '/painel/bloqueios']]) {
        await page.goto(url);
        await verificarTela(page, info, nome);
    }

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('profissional: vê só a própria agenda e não configura', async ({ page }, info) => {
    await entrarNoPainel(page, `e2e-barbeiro-a-${info.project.name}`);

    await page.goto('/painel/agenda');
    await expect(page.getByRole('heading', { name: `Barbeiro A ${info.project.name}` })).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Barbeiro B/ })).toHaveCount(0);
    await expect(page.getByLabel('Profissional')).toHaveCount(0);

    let r = await page.goto('/painel/agenda/configuracoes');
    expect(r.status()).toBe(403);
    r = await page.goto('/painel/bloqueios');
    expect(r.status()).toBe(403);
});

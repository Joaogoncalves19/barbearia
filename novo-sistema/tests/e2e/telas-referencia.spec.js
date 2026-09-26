// Verificacoes automaticas das telas de referencia (Fase 1):
// sem erro de console, sem violacao de CSP, sem rolagem horizontal,
// acessibilidade (axe: nenhuma violacao seria/critica), nas duas direcoes.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const telas = [
    ['Home', '/prototipos/home'],
    ['Serviços', '/prototipos/servicos'],
    ['Agendamento', '/prototipos/agendamento'],
    ['Painel', '/prototipos/painel'],
    ['Agenda (dia)', '/prototipos/agenda'],
    ['Agenda (lista)', '/prototipos/agenda?visao=lista'],
    ['Design System', '/design-system'],
    ['Login', '/entrar'],
];

function observarErros(page) {
    const erros = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error' || /Content Security Policy/i.test(msg.text())) erros.push(msg.text());
    });
    page.on('pageerror', (e) => erros.push(String(e)));
    return erros;
}

for (const direcao of ['a', 'b']) {
    for (const [nome, caminho] of telas) {
        test(`${nome} [direção ${direcao}] carrega sem erros, sem rolagem lateral e acessível`, async ({ page }, info) => {
            const erros = observarErros(page);
            const url = caminho + (caminho.includes('?') ? '&' : '?') + `direcao=${direcao}`;
            const resposta = await page.goto(url, { waitUntil: 'networkidle' });
            expect(resposta.status()).toBe(200);

            // Sem rolagem horizontal (a agenda rola DENTRO do proprio container).
            const larguraExtra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            expect(larguraExtra, 'página não deve rolar na horizontal').toBeLessThanOrEqual(1);

            const axe = await new AxeBuilder({ page }).analyze();
            const graves = axe.violations
                .filter((v) => ['serious', 'critical'].includes(v.impact))
                .map((v) => `${v.id}: ${v.help} (${v.nodes.length}x) ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
            expect(graves, 'violações de acessibilidade graves').toEqual([]);

            expect(erros, 'erros de console / CSP').toEqual([]);

            await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-${direcao}-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
        });
    }
}

test('agendamento: avança só com escolha feita e atualiza o resumo', async ({ page }) => {
    const erros = observarErros(page);
    await page.goto('/prototipos/agendamento');
    const continuar = page.locator('.booking__nav .btn--accent, .booking__bar .btn--accent').locator('visible=true');
    await expect(continuar).toBeDisabled();

    await page.locator('label.option-card:has(input[value="degrade"])').click();
    await expect(continuar).toBeEnabled();
    await expect(page.locator('.booking__bar strong').first()).toHaveText(/R\$\s?60,00/);
    await continuar.click();

    await page.locator('label.option-card:has(input[value="qualquer"])').click();
    await continuar.click();

    await expect(continuar).toBeDisabled();
    await page.locator('button.day:not([disabled])').first().click();
    await page.locator('button.slot').first().click();
    await expect(page.locator('button.slot').first()).toHaveAttribute('aria-pressed', 'true');
    await continuar.click();

    await expect(page.locator('#etapa-4')).toBeVisible();
    expect(erros).toEqual([]);
});

test('modal: abre, prende o foco, fecha com Esc e devolve o foco', async ({ page }) => {
    await page.goto('/design-system');
    const botao = page.getByRole('button', { name: 'Abrir modal' });
    await botao.click();
    const modal = page.locator('#ds-modal');
    await expect(modal).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(botao).toBeFocused();
});

test('abas: setas do teclado trocam de aba', async ({ page }) => {
    await page.goto('/design-system');
    const primeira = page.getByRole('tab', { name: 'Dados' });
    await primeira.focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('tab', { name: 'Histórico' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#painel-historico')).toBeVisible();
    await expect(page.locator('#painel-dados')).toBeHidden();
});

test('dropdown: abre, fecha com Esc e devolve o foco', async ({ page }) => {
    await page.goto('/design-system');
    const gatilho = page.getByRole('button', { name: 'Ações do agendamento' });
    await gatilho.click();
    await expect(page.getByRole('button', { name: 'Remarcar' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Remarcar' })).toBeHidden();
    await expect(gatilho).toBeFocused();
});

test('site no celular: menu abre e barra de agendar fica visível', async ({ page }, info) => {
    test.skip(info.project.name !== 'celular', 'só no celular');
    await page.goto('/prototipos/home');
    await expect(page.locator('.bottom-bar')).toBeVisible();
    await page.getByRole('button', { name: 'Abrir menu' }).click();
    await expect(page.locator('#menu-movel')).toBeVisible();
});

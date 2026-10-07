// Fase 3 — acesso, contas e permissoes no navegador (celular e desktop):
// telas de acesso acessiveis (axe), sem erro de console/CSP e sem rolagem
// lateral; fluxos reais de login da equipe e do cliente; bloqueio de acesso
// horizontal pela URL. Contas ficticias criadas pelo global-setup.js.
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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase3-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function entrarNoPainel(page, usuario) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(usuario);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
}

const telasDeAcesso = [
    ['Login da equipe', '/painel/entrar'],
    ['Esqueci a senha (equipe)', '/painel/esqueci-a-senha'],
    ['Login do cliente', '/entrar'],
    ['Cadastro do cliente', '/cadastro'],
    ['Link de acesso', '/entrar/link'],
    ['Esqueci a senha (cliente)', '/esqueci-a-senha'],
];

for (const [nome, caminho] of telasDeAcesso) {
    test(`${nome}: carrega sem erros, sem rolagem lateral e acessível`, async ({ page }, info) => {
        const erros = observarErros(page);
        const resposta = await page.goto(caminho, { waitUntil: 'networkidle' });
        expect(resposta.status()).toBe(200);
        expect(resposta.headers()['cache-control']).toContain('no-store');
        await verificarTela(page, info, nome);
        expect(erros, 'erros de console / CSP').toEqual([]);
    });
}

test('login errado mostra mensagem neutra, ligada ao campo', async ({ page }, info) => {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(`nao-existe-${info.project.name}`);
    await page.getByLabel('Senha', { exact: true }).fill('SenhaErrada123');
    await page.getByRole('button', { name: 'Entrar' }).click();

    const campo = page.getByLabel('Usuário ou e-mail');
    await expect(page.getByText('Usuário, e-mail ou senha incorretos.')).toBeVisible();
    await expect(campo).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Senha', { exact: true })).toHaveValue('');
});

test('login só com teclado', async ({ page }, info) => {
    test.skip(info.project.name === 'celular', 'teclado físico só no desktop');
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').focus();
    await page.keyboard.type(`e2e-dono-${info.project.name}`);
    await page.keyboard.press('Tab');
    await page.keyboard.type(SENHA);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/painel$/);
});

test('proprietário: entra, reconfirma a senha para gerenciar usuários e sai', async ({ page }, info) => {
    test.slow(); // varias paginas + axe em cada uma
    const erros = observarErros(page);
    await entrarNoPainel(page, `e2e-dono-${info.project.name}`);
    await expect(page).toHaveURL(/\/painel$/);
    await verificarTela(page, info, 'Painel - início');

    await page.goto('/painel/usuarios');
    await expect(page).toHaveURL(/\/painel\/confirmar-senha$/);
    await page.getByLabel('Sua senha').fill(SENHA);
    await page.getByRole('button', { name: 'Confirmar' }).click();

    await expect(page).toHaveURL(/\/painel\/usuarios$/);
    await expect(page.getByRole('heading', { name: 'Usuários da equipe' })).toBeVisible();
    await verificarTela(page, info, 'Usuários');

    await page.goto('/painel/usuarios/novo');
    await verificarTela(page, info, 'Novo usuário');

    await page.goto('/painel/minha-conta');
    // A lista de permissoes fica recolhida (passa de cem itens no proprietario).
    await page.getByText('Ver a lista completa').click();
    await expect(page.getByText('Ver a trilha de auditoria')).toBeVisible();
    await verificarTela(page, info, 'Minha conta (equipe)');

    await page.goto('/painel/auditoria');
    await expect(page.getByText('auth.login').first()).toBeVisible();
    await verificarTela(page, info, 'Auditoria');

    // Sair pelo menu do usuario (botao com o nome da pessoa, na barra do topo).
    await page.getByRole('button', { name: /Dono E2E/ }).click();
    await page.getByRole('button', { name: 'Sair' }).click();
    await expect(page).toHaveURL(/\/painel\/entrar$/);

    // Voltar para o painel depois de sair: login de novo.
    await page.goto('/painel');
    await expect(page).toHaveURL(/\/painel\/entrar$/);
    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('senha provisória: a troca é obrigatória antes de usar o painel', async ({ page }, info) => {
    test.slow(); // varias paginas + axe em cada uma
    await entrarNoPainel(page, `e2e-provisorio-${info.project.name}`);
    await expect(page).toHaveURL(/\/painel\/minha-conta\/senha$/);
    await expect(page.getByRole('heading', { name: 'Crie sua senha pessoal' })).toBeVisible();
    await verificarTela(page, info, 'Troca obrigatória de senha');

    await page.goto('/painel');
    await expect(page).toHaveURL(/\/painel\/minha-conta\/senha$/);

    const nova = `Nova${SENHA.slice(0, 10)}9x`;
    await page.getByLabel('Senha provisória').fill(SENHA);
    await page.getByLabel('Nova senha', { exact: true }).fill(nova);
    await page.getByLabel('Repita a nova senha').fill(nova);
    await page.getByRole('button', { name: 'Salvar senha' }).click();
    await expect(page).toHaveURL(/\/painel$/);
});

test('profissional: vê a própria ficha e não a de outro, nem a gestão de usuários', async ({ page }, info) => {
    await entrarNoPainel(page, `e2e-barbeiro-a-${info.project.name}`);
    await expect(page).toHaveURL(/\/painel$/);

    await page.goto('/painel');
    // No celular o menu fechado fica oculto (fora do teclado e do leitor de tela): le o link pelo elemento.
    const ficha = await page.locator('a.nav-link', { hasText: 'Minha ficha' }).getAttribute('href');
    const id = Number(ficha.split('/').pop());

    let r = await page.goto(ficha);
    expect(r.status()).toBe(200);
    await expect(page.getByRole('heading', { name: `Barbeiro A ${info.project.name}` })).toBeVisible();

    r = await page.goto(`/painel/profissionais/${id + 1}`);
    expect(r.status(), 'ficha de outro profissional pela URL').toBe(404);

    r = await page.goto('/painel/usuarios');
    expect(r.status(), 'gestão de usuários').toBe(403);
});

test('cliente: entra, vê só o próprio horário e não abre o de outra pessoa pela URL', async ({ page }, info) => {
    test.slow(); // varias paginas + axe em cada uma
    const erros = observarErros(page);
    const s = info.project.name;

    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill(`e2e-cliente-${s}@exemplo.test`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();

    await expect(page).toHaveURL(/\/minha-conta$/);
    // A lista traz o link do proprio horario e nenhum da outra cliente.
    await expect(page.locator(`a[href$="/minha-conta/agendamentos/AG-E2E-A-${s}"]`)).toBeVisible();
    await expect(page.locator(`a[href*="AG-E2E-B-${s}"]`)).toHaveCount(0);
    await verificarTela(page, info, 'Minha conta (cliente)');

    let r = await page.goto(`/minha-conta/agendamentos/AG-E2E-A-${s}`);
    expect(r.status()).toBe(200);
    await verificarTela(page, info, 'Horário do cliente');

    expect(erros, 'erros de console / CSP').toEqual([]);
    r = await page.goto(`/minha-conta/agendamentos/AG-E2E-B-${s}`);
    expect(r.status(), 'horário de outra cliente pela URL').toBe(404);
    erros.length = 0; // o 404 acima e esperado (o navegador o registra no console)

    await page.goto('/minha-conta/dados');
    // CPF sempre mascarado (desde a Fase 12 ele fica em "Meus dados", nao no inicio).
    await expect(page.getByText(/\d{3}\.\*\*\*\.\*\*\*-\d{2}/)).toBeVisible();
    await verificarTela(page, info, 'Meus dados');

    // Sessão de cliente não abre o painel da equipe.
    await page.goto('/painel');
    await expect(page).toHaveURL(/\/painel\/entrar$/);

    await page.goto('/minha-conta');
    await page.getByRole('button', { name: 'Sair' }).click();
    await expect(page).toHaveURL(/\/entrar$/);
    expect(erros, 'erros de console / CSP').toEqual([]);
});

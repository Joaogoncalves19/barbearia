// Temas visuais (temas-visuais.md). O tema e GLOBAL (vale para todo o site):
// por isso este arquivo roda num projeto proprio ("temas", playwright.config.js),
// sozinho e DEPOIS dos outros, e volta ao Oficio no fim.
//
// Para cada um dos 8 temas, escolhido pela tela Aparencia como o dono faria:
// - o tema aparece no site, no acesso, no painel, na area do profissional
//   (Fase 12.5) e na conta do cliente;
// - nenhuma tela fica com a cor do tema anterior (o fundo pintado e o do tema);
// - contraste minimo MEDIDO no navegador (texto 4,5:1; borda de campo e foco 3:1),
//   nas duas superficies do tema;
// - axe sem violacao grave e sem rolagem lateral, no desktop e no celular.
import { test, expect, devices } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SENHA = process.env.E2E_PASSWORD;
const TEMAS = ['oficio', 'black-label', 'gentlemans-club', 'urban-barber', 'old-school', 'red-barber', 'minimal', 'copper-club'];

// Um tema por teste, em sequencia no mesmo worker (o projeto "temas" nao roda
// em paralelo); se um tema falhar, os outros continuam sendo verificados.

/** Contraste WCAG entre duas cores, medido com os tokens reais do tema. */
async function contrastes(page, tema) {
    return page.evaluate((tema) => {
        const rgb = (c) => {
            const ctx = document.createElement('canvas').getContext('2d');
            ctx.fillStyle = '#000';
            ctx.fillStyle = c;
            ctx.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
            return [r, g, b, a / 255];
        };
        const sobre = (fg, bg) => fg.slice(0, 3).map((v, i) => v * fg[3] + bg[i] * (1 - fg[3]));
        const lum = (c) => {
            const f = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        const razao = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };

        const pares = [
            ['--c-text', '--c-bg', 4.5], ['--c-text', '--c-surface', 4.5], ['--c-text', '--c-surface-sunken', 4.5],
            ['--c-text-muted', '--c-bg', 4.5], ['--c-text-muted', '--c-surface', 4.5], ['--c-text-muted', '--c-surface-sunken', 4.5],
            ['--c-accent-text', '--c-bg', 4.5], ['--c-accent-text', '--c-surface', 4.5],
            ['--c-on-primary', '--c-primary', 4.5], ['--c-on-accent', '--c-accent', 4.5],
            ['--c-on-primary-accent', '--c-primary', 4.5], ['--c-on-plate', '--c-plate', 4.5],
            ['--c-success', '--c-success-bg', 4.5], ['--c-warning', '--c-warning-bg', 4.5],
            ['--c-danger', '--c-danger-bg', 4.5], ['--c-info', '--c-info-bg', 4.5],
            ['--c-success', '--c-surface', 4.5], ['--c-danger', '--c-surface', 4.5],
            ['--c-border-control', '--c-surface', 3], ['--c-focus', '--c-bg', 3],
        ];
        const falhas = [];
        for (const sup of ['clara', 'escura']) {
            const caixa = document.createElement('div');
            caixa.dataset.tema = tema;
            caixa.dataset.superficie = sup;
            document.body.append(caixa);
            // Cada token vira uma cor computada (rgb ou color(srgb ...)) num elemento.
            const amostra = document.createElement('span');
            caixa.append(amostra);
            const v = (n) => { amostra.style.color = `var(${n})`; return getComputedStyle(amostra).color; };
            const fundo = rgb(v('--c-bg'));
            for (const [fg, bg, min] of pares) {
                const b = rgb(v(bg));
                const corFundo = b[3] < 1 ? sobre(b, fundo) : b.slice(0, 3);
                const f = rgb(v(fg));
                const corTexto = f[3] < 1 ? sobre(f, corFundo) : f.slice(0, 3);
                const r = razao(corTexto, corFundo);
                if (r < min) falhas.push(`${sup}: ${fg} sobre ${bg} = ${r.toFixed(2)} (minimo ${min})`);
            }
            caixa.remove();
        }
        return falhas;
    }, tema);
}

async function verificar(page, nome, tema) {
    await expect(page.locator('html'), `${nome}: tema`).toHaveAttribute('data-tema', tema);
    // O fundo pintado e o do tema ativo (nada da cor do tema anterior).
    const fundo = await page.evaluate(() => {
        const sup = document.documentElement.dataset.superficie;
        const sonda = document.createElement('div');
        sonda.dataset.tema = document.documentElement.dataset.tema;
        sonda.dataset.superficie = sup;
        sonda.style.background = 'var(--c-bg)';
        document.body.append(sonda);
        const esperado = getComputedStyle(sonda).backgroundColor;
        sonda.remove();
        return { pintado: getComputedStyle(document.body).backgroundColor, esperado };
    });
    expect(fundo.pintado, `${nome}: fundo do tema`).toBe(fundo.esperado);

    const extra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(extra, `${nome}: sem rolagem lateral`).toBeLessThanOrEqual(1);
    const axe = await new AxeBuilder({ page }).analyze();
    const graves = axe.violations
        .filter((x) => ['serious', 'critical'].includes(x.impact))
        .map((x) => `${x.id}: ${x.help} (${x.nodes.length}x) ${x.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(graves, `${nome}: violacoes graves de acessibilidade`).toEqual([]);
}

async function entrarEquipe(page) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill('e2e-visual-temas');
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

async function entrarProfissional(page) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill('e2e-pro-a-temas');
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/profissional$/);
}

async function entrarCliente(page) {
    await page.goto('/entrar');
    await page.getByLabel('E-mail').fill('e2e-area-temas@exemplo.test');
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/minha-conta/);
}

async function escolherTema(page, tema) {
    await page.goto('/painel/aparencia');
    const cartao = page.locator(`[data-theme-card="${tema}"]`);
    const usar = cartao.getByRole('button', { name: /Usar este tema/ });
    if (await usar.count()) {
        await usar.click();
        await expect(page.getByText(/aplicado ao site/)).toBeVisible();
    }
    await expect(cartao.getByText('Em uso')).toBeVisible();
}

test.describe('temas visuais', () => {
    let equipe;
    let celularEquipe;
    let cliente;
    let celularCliente;
    let profissional;
    let celularProfissional;
    let anterior = null;

    test.beforeAll(async ({ browser }) => {
        equipe = await (await browser.newContext({ ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } })).newPage();
        celularEquipe = await (await browser.newContext({ ...devices['Pixel 7'] })).newPage();
        cliente = await (await browser.newContext({ ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } })).newPage();
        celularCliente = await (await browser.newContext({ ...devices['Pixel 7'] })).newPage();
        await entrarEquipe(equipe);
        await entrarEquipe(celularEquipe);
        await entrarCliente(cliente);
        await entrarCliente(celularCliente);
        profissional = await (await browser.newContext({ ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } })).newPage();
        celularProfissional = await (await browser.newContext({ ...devices['Pixel 7'] })).newPage();
        await entrarProfissional(profissional);
        await entrarProfissional(celularProfissional);
    });

    test.afterAll(async () => {
        // O tema e global: deixa a barbearia de teste no padrao.
        await escolherTema(equipe, 'oficio');
        for (const p of [equipe, celularEquipe, cliente, celularCliente, profissional, celularProfissional]) await p.context().close();
    });

    for (const tema of TEMAS) {
        test(`tema ${tema}: site, acesso, painel e conta, no desktop e no celular`, async () => {
            test.setTimeout(330_000);
            await escolherTema(equipe, tema);

            expect(await contrastes(equipe, tema), `contraste dos tokens do tema ${tema}`).toEqual([]);

            // Previa do proximo tema nao muda o tema em uso.
            const outro = TEMAS[(TEMAS.indexOf(tema) + 1) % TEMAS.length];
            await equipe.goto(`/painel/aparencia/previa/${outro}`);
            await expect(equipe.locator('html')).toHaveAttribute('data-tema', outro);
            await verificar(equipe, `previa ${outro}`, outro);

            for (const [pagina, onde] of [[equipe, 'desktop'], [celularEquipe, 'celular']]) {
                for (const url of ['/painel', '/painel/agenda', '/painel/caixa', '/painel/servicos', '/painel/aparencia', '/painel/clientes', '/painel/clientes/novo']) {
                    await pagina.goto(url);
                    await verificar(pagina, `${tema} ${onde} ${url}`, tema);
                }
                // Tela Clientes (Fase 13, P13-01): ficha e edicao de um cliente da lista.
                await pagina.goto('/painel/clientes');
                const ficha = await pagina.locator('[data-customer-row] a').first().getAttribute('href');
                for (const url of [ficha, `${ficha}/editar`]) {
                    await pagina.goto(url);
                    await verificar(pagina, `${tema} ${onde} ${url}`, tema);
                }
                // Barra lateral na superficie do tema (nada de grafite do Oficio sobrando).
                const lateral = await pagina.locator('#menu-painel').getAttribute('data-superficie');
                expect(['clara', 'escura']).toContain(lateral);
            }

            // Area do profissional (Fase 12.5): mesmo tema, nenhuma regra propria.
            for (const [pagina, onde] of [[profissional, 'desktop'], [celularProfissional, 'celular']]) {
                for (const url of ['/profissional', '/profissional/agenda', '/profissional/atendimentos', '/profissional/ganhos', '/profissional/perfil']) {
                    await pagina.goto(url);
                    await verificar(pagina, `${tema} ${onde} ${url}`, tema);
                }
                expect(['clara', 'escura']).toContain(await pagina.locator('.pro-nav').getAttribute('data-superficie'));
            }

            for (const [pagina, onde] of [[cliente, 'desktop'], [celularCliente, 'celular']]) {
                for (const url of ['/', '/servicos', '/equipe', '/agendar', '/minha-conta', '/minha-conta/agendamentos', '/minha-conta/fidelidade']) {
                    await pagina.goto(url);
                    await verificar(pagina, `${tema} ${onde} ${url}`, tema);
                }
            }

            // Acesso (sem sessao) e pagina de erro tambem no tema.
            const contexto = await equipe.context().browser().newContext();
            const anonimo = await contexto.newPage();
            for (const url of ['/entrar', '/painel/entrar']) {
                await anonimo.goto(url);
                await verificar(anonimo, `${tema} ${url}`, tema);
            }
            const r = await anonimo.goto('/pagina-que-nao-existe');
            expect(r.status()).toBe(404);
            await verificar(anonimo, `${tema} 404`, tema);
            await contexto.close();

            if (anterior) {
                // Nenhum vestigio do tema anterior no HTML das paginas reais.
                for (const p of [equipe, cliente, profissional]) {
                    await p.goto(p === equipe ? '/painel' : p === cliente ? '/minha-conta' : '/profissional');
                    expect(await p.locator(`[data-tema="${anterior}"]`).count(), `${tema}: nada marcado com o tema ${anterior}`).toBe(0);
                }
            }
            anterior = tema;
        });
    }
});

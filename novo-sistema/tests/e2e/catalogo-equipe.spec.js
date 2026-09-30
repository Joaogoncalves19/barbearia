// Fase 4 — catalogo e equipe no navegador (celular e desktop): cadastro de
// categoria e servico, alteracao de preco, ativar/desativar com confirmacao,
// cadastro de profissional (com foto) e vinculo com servicos. Em cada tela:
// axe, sem erro de console/CSP e sem rolagem lateral.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { deflateSync } from 'node:zlib';

const SENHA = process.env.E2E_PASSWORD;

// Nomes unicos por execucao (o banco local acumula execucoes anteriores).
const RODADA = Date.now().toString(36);

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

    await page.screenshot({ path: `storage/e2e/telas/${info.project.name}-fase4-${nome.replace(/\W+/g, '-').toLowerCase()}.png`, fullPage: true });
}

async function criarCategoria(page, nome) {
    await page.goto('/painel/categorias/nova');
    await page.getByLabel('Nome').fill(nome);
    await page.getByRole('button', { name: 'Criar categoria' }).click();
    await expect(page.getByText(`Categoria "${nome}" criada.`)).toBeVisible();
}

async function entrar(page, info) {
    await page.goto('/painel/entrar');
    await page.getByLabel('Usuário ou e-mail').fill(`e2e-gerente-${info.project.name}`);
    await page.getByLabel('Senha', { exact: true }).fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL(/\/painel$/);
}

// PNG 240x240 de uma cor so, gerado aqui (sem arquivo binario no Git).
function pngFicticio(lado = 240) {
    const crcTabela = Array.from({ length: 256 }, (_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        return c >>> 0;
    });
    const crc = (buf) => {
        let c = 0xffffffff;
        for (const b of buf) c = crcTabela[(c ^ b) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
    const bloco = (tipo, dados) => {
        const t = Buffer.from(tipo);
        const tam = Buffer.alloc(4);
        tam.writeUInt32BE(dados.length);
        const c = Buffer.alloc(4);
        c.writeUInt32BE(crc(Buffer.concat([t, dados])));
        return Buffer.concat([tam, t, dados, c]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(lado, 0);
    ihdr.writeUInt32BE(lado, 4);
    ihdr[8] = 8; // bits
    ihdr[9] = 2; // RGB
    const linha = Buffer.concat([Buffer.from([0]), Buffer.alloc(lado * 3, 0xb0)]);
    const pixels = deflateSync(Buffer.concat(Array(lado).fill(linha)));
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), bloco('IHDR', ihdr), bloco('IDAT', pixels), bloco('IEND', Buffer.alloc(0))]);
}

test('categoria e serviço: cadastro, erro de validação, preço e ativação com confirmação', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const categoria = `Cabelo ${info.project.name} ${RODADA}`;
    const servico = `Corte ${info.project.name} ${RODADA}`;
    await entrar(page, info);

    // Categoria
    await page.goto('/painel/categorias/nova');
    await verificarTela(page, info, 'Nova categoria');
    await page.getByLabel('Nome').fill(categoria);
    await page.getByRole('button', { name: 'Criar categoria' }).click();
    await expect(page).toHaveURL(/\/painel\/categorias$/);
    await expect(page.getByText(`Categoria "${categoria}" criada.`)).toBeVisible();
    await verificarTela(page, info, 'Categorias');

    // Servico: primeiro com erro (preco invalido), mensagem ligada ao campo.
    await page.goto('/painel/servicos/novo');
    await verificarTela(page, info, 'Novo serviço');
    await page.getByLabel('Nome').fill(servico);
    await page.getByLabel('Categoria').selectOption({ label: categoria });
    await page.getByLabel('Duração').selectOption('45');
    await page.getByLabel('Preço', { exact: true }).fill('abc');
    await page.getByRole('button', { name: 'Criar serviço' }).click();
    const preco = page.getByLabel('Preço', { exact: true });
    await expect(preco).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByText(/Informe um preço entre/)).toBeVisible();
    await expect(page.getByLabel('Nome')).toHaveValue(servico); // o que foi digitado nao se perde

    await preco.fill('55,00');
    await page.getByRole('button', { name: 'Criar serviço' }).click();
    await expect(page).toHaveURL(/\/painel\/servicos$/);
    await expect(page.getByText(`Serviço "${servico}" criado por R$ 55,00, 45 min.`)).toBeVisible();
    await verificarTela(page, info, 'Serviços');

    // Alterar o preco
    await page.getByRole('link', { name: `Editar ${servico}` }).click();
    await verificarTela(page, info, 'Editar serviço');
    await page.getByLabel('Preço atual').fill('60,00');
    await page.getByRole('button', { name: 'Salvar alterações' }).click();
    await expect(page.getByText(/Novo preço: R\$ 60,00 \(vale para agendamentos novos/)).toBeVisible();
    await page.getByRole('link', { name: `Editar ${servico}` }).click();
    await expect(page.getByRole('heading', { name: 'Histórico de preço' })).toBeVisible();
    await expect(page.locator('td', { hasText: 'R$ 55,00' }).first()).toBeVisible();

    // Desativar: pede confirmacao; Esc cancela e devolve o foco
    await page.goto('/painel/servicos');
    // O modal depende do JS da pagina: espera carregar (servidor embutido e lento em paralelo).
    await page.waitForLoadState('networkidle');
    const desativar = page.getByRole('button', { name: `Desativar ${servico}` });
    await desativar.click();
    const modal = page.getByRole('dialog', { name: `Desativar ${servico}?` });
    await expect(modal).toBeVisible();
    await expect(modal.getByText('O histórico (agendamentos, valores, relatórios) não muda.')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(desativar).toBeFocused();

    await desativar.click();
    await modal.getByRole('button', { name: 'Desativar' }).click();
    await expect(page.getByText(`Serviço "${servico}" desativado`)).toBeVisible();
    await expect(page.getByRole('button', { name: `Desativar ${servico}` })).toHaveCount(0);

    // Filtro "inativos" mostra o servico; ativar de novo
    await page.goto('/painel/servicos?situacao=inativos');
    await page.getByRole('button', { name: `Ativar ${servico}` }).click();
    await expect(page.getByText(`Serviço "${servico}" ativado.`)).toBeVisible();

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('profissional: cadastro com foto e vínculo com serviços', async ({ page }, info) => {
    test.slow();
    const erros = observarErros(page);
    const nome = `Barbeiro ${info.project.name} ${RODADA}`;
    const servico = `Barba ${info.project.name} ${RODADA}`;
    await entrar(page, info);

    // Um servico (e a categoria dele) para vincular.
    const categoria = `Barba ${info.project.name} ${RODADA}`;
    await criarCategoria(page, categoria);
    await page.goto('/painel/servicos/novo');
    await page.getByLabel('Nome').fill(servico);
    await page.getByLabel('Categoria').selectOption({ label: categoria });
    await page.getByLabel('Preço', { exact: true }).fill('35,00');
    await page.getByRole('button', { name: 'Criar serviço' }).click();
    await expect(page).toHaveURL(/\/painel\/servicos$/);

    // Profissional sem conta de acesso, com foto e apresentacao
    await page.goto('/painel/profissionais/novo');
    await verificarTela(page, info, 'Novo profissional');
    await page.getByLabel('Nome de exibição').fill(nome);
    await expect(page.getByLabel('Conta de acesso ao sistema')).toHaveValue('');
    await page.getByLabel('Especialidade').fill('Barba desenhada');
    await page.locator('input[type="file"][name="photo"]').setInputFiles({ name: 'foto.png', mimeType: 'image/png', buffer: pngFicticio() });
    await page.getByRole('button', { name: 'Cadastrar e escolher serviços' }).click();

    // Vai direto para os servicos que ele executa
    await expect(page).toHaveURL(/\/painel\/profissionais\/\d+\/servicos$/);
    await expect(page.getByText(`Profissional "${nome}" cadastrado.`)).toBeVisible();
    await verificarTela(page, info, 'Serviços do profissional');
    await page.getByRole('checkbox', { name: new RegExp(servico) }).check();
    await page.getByRole('button', { name: 'Salvar serviços' }).click();
    await expect(page).toHaveURL(/\/painel\/profissionais$/);
    await expect(page.getByText(`Serviços de ${nome} salvos (1 no total; 1 incluído(s), 0 retirado(s)).`)).toBeVisible();
    await verificarTela(page, info, 'Profissionais');

    // Ficha mostra a foto e o servico
    await page.getByRole('link', { name: nome, exact: true }).click();
    await expect(page.getByRole('heading', { name: nome })).toBeVisible();
    await expect(page.getByText(servico)).toBeVisible();
    const foto = page.locator('img[src*="/storage/professionals/"]').first();
    await expect(foto).toBeVisible();
    // Espera o carregamento (o servidor embutido do PHP atende uma requisicao por vez).
    await expect.poll(() => foto.evaluate((img) => (img.complete ? img.naturalWidth : 0)), { message: 'foto carregou', timeout: 20_000 }).toBeGreaterThan(0);
    await verificarTela(page, info, 'Ficha do profissional');

    // Desativar o profissional (confirmacao) e ver que sai da lista de ativos
    await page.goto('/painel/profissionais');
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: `Desativar ${nome}` }).click();
    await page.getByRole('dialog', { name: `Desativar ${nome}?` }).getByRole('button', { name: 'Desativar' }).click();
    await expect(page.getByText(`${nome} foi desativado`)).toBeVisible();
    await expect(page.getByRole('link', { name: nome, exact: true })).toHaveCount(0);

    expect(erros, 'erros de console / CSP').toEqual([]);
});

test('ordem pelo teclado: subir e descer sem arrastar', async ({ page }, info) => {
    test.skip(info.project.name === 'celular', 'teclado físico só no desktop');
    test.slow();
    const a = `Ordem A ${RODADA}`;
    const b = `Ordem B ${RODADA}`;
    await entrar(page, info);
    await criarCategoria(page, a);
    await criarCategoria(page, b); // entra no fim, abaixo de A

    const posicao = async (nome) => (await page.locator('tbody tr td:first-child strong').allInnerTexts()).indexOf(nome);
    const antes = await posicao(b);
    expect(antes).toBeGreaterThan(await posicao(a));

    await page.getByRole('button', { name: `Subir ${b}` }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Ordem atualizada.')).toBeVisible();
    expect(await posicao(b), 'subiu uma posição').toBe(antes - 1);
});

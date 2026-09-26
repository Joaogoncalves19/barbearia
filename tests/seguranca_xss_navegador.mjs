// Parte de navegador de tests/seguranca_fase1.php (S-02).
// Uso: node tests/seguranca_xss_navegador.mjs <base_url> '<cookies_json>'
// Imprime um JSON: [{descricao, ok, detalhe}]. Chamado pelo script PHP.
//
// O payload semeado pelo teste incrementa window.__xss se for executado.
// Requisicoes para fora do servidor local sao bloqueadas (CDNs), para o
// teste nao depender de rede.

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

function carregarPlaywright() {
    const candidatos = [process.env.PLAYWRIGHT_MODULE, 'playwright', '@playwright/test'].filter(Boolean);
    for (const nome of candidatos) {
        try { return require(nome); } catch (e) { /* tenta o proximo */ }
    }
    // Instalacao global do npm (ex.: ambiente de CI com playwright global).
    try {
        const { execSync } = require('node:child_process');
        const raizGlobal = execSync('npm root -g').toString().trim();
        return require(raizGlobal + '/playwright');
    } catch (e) {
        return null;
    }
}

const pw = carregarPlaywright();
if (!pw) {
    console.log('playwright nao encontrado');
    process.exit(0);
}

const [base, cookiesJson] = process.argv.slice(2);
const host = new URL(base).host;
const resultados = [];

const browser = await pw.chromium.launch();
try {
    const contexto = await browser.newContext();
    await contexto.route('**/*', rota => {
        const url = new URL(rota.request().url());
        return url.host === host ? rota.continue() : rota.abort();
    });

    // --- Painel admin: detalhes do barbeiro e do cliente ---
    const cookies = JSON.parse(cookiesJson || '[]').map(c => ({ ...c, url: base }));
    await contexto.addCookies(cookies);
    const admin = await contexto.newPage();
    await admin.goto(base + '/admin.php', { waitUntil: 'domcontentloaded' });
    const carregou = await admin.evaluate(() => typeof window.renderDetalhesBarbeiro === 'function');
    resultados.push({ descricao: 'painel admin carrega o script de detalhes', ok: carregou });

    if (carregou) {
        await admin.evaluate(() => {
            window.__xss = 0;
            window.renderDetalhesBarbeiro('br-1');
        });
        await admin.waitForTimeout(400);
        const r1 = await admin.evaluate(() => ({
            xss: window.__xss || 0,
            imgs: document.querySelectorAll('#barbeiro-detalhes-content img[src="x"]').length,
            texto: (document.getElementById('barbeiro-detalhes-content') || {}).textContent || '',
        }));
        resultados.push({
            descricao: 'detalhes do barbeiro: comentario e nome nao executam codigo',
            ok: r1.xss === 0 && r1.imgs === 0,
            detalhe: `__xss=${r1.xss}, img injetada=${r1.imgs}`,
        });
        resultados.push({
            descricao: 'detalhes do barbeiro: comentario continua visivel como texto',
            ok: r1.texto.includes('onerror'),
            detalhe: 'texto do comentario nao encontrado',
        });

        await admin.evaluate(() => {
            window.__xss = 0;
            window.renderDetalhesCliente('CL-XSSXSS');
        });
        await admin.waitForTimeout(400);
        const r2 = await admin.evaluate(() => ({
            xss: window.__xss || 0,
            imgs: document.querySelectorAll('#cliente-detalhes-content img[src="x"]').length,
        }));
        resultados.push({
            descricao: 'detalhes do cliente: nome do cliente nao executa codigo',
            ok: r2.xss === 0 && r2.imgs === 0,
            detalhe: `__xss=${r2.xss}, img injetada=${r2.imgs}`,
        });
    }

    // --- Pagina publica: modal de perfil do barbeiro (avaliacoes) ---
    const publico = await (await browser.newContext()).newPage();
    await publico.route('**/*', rota => (new URL(rota.request().url()).host === host ? rota.continue() : rota.abort()));
    await publico.goto(base + '/agendamento', { waitUntil: 'domcontentloaded' });
    await publico.evaluate(() => { window.__xss = 0; });
    const botao = await publico.$('.btn-detalhes-barbeiro');
    if (!botao) {
        resultados.push({ descricao: 'pagina publica: botao de perfil do barbeiro existe', ok: false, detalhe: 'botao nao encontrado' });
    } else {
        await botao.evaluate(el => el.click());
        await publico.waitForTimeout(400);
        const r3 = await publico.evaluate(() => ({
            xss: window.__xss || 0,
            imgs: document.querySelectorAll('img[src="x"]').length,
        }));
        resultados.push({
            descricao: 'pagina publica: comentario de avaliacao nao executa codigo',
            ok: r3.xss === 0 && r3.imgs === 0,
            detalhe: `__xss=${r3.xss}, img injetada=${r3.imgs}`,
        });
    }
} catch (e) {
    resultados.push({ descricao: 'execucao do teste de navegador', ok: false, detalhe: String(e).slice(0, 300) });
} finally {
    await browser.close();
}

console.log(JSON.stringify(resultados));

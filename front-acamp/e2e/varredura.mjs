/**
 * Varredura do site: abre todas as telas que encontrar e aponta as que quebram.
 *
 *   npm run varredura            (com o backend e o front rodando)
 *
 * Como funciona: começa no Início e no menu do painel e vai seguindo os links
 * internos (cada tipo de tela, ex. /admin/pessoas/:id, no máximo 2 vezes).
 * Em cada tela confere: erro de JavaScript, erro no console, resposta da API
 * com erro (4xx/5xx), tela que não existe (404) e tela que ficou "Carregando".
 * Tira uma foto de cada tela e monta varredura/relatorio.html para folhear.
 *
 * Só abre links: não clica em botões nem salva nada. Mas abrir algumas telas
 * roda as varreduras de prazo do servidor (lazy-cron, ver CLAUDE.md), como
 * aconteceria com qualquer pessoa usando o site.
 *
 * Entra com as contas "TESTE CLAUDE Varredura" (todas as permissões) e
 * "… Limitado" (só Categorias). Crie ou acerte as duas com
 *   php bin/cake.php usuarios_varredura      (em backend/)
 *
 * Variáveis (opcionais): VARREDURA_URL (padrão http://localhost:5173),
 * VARREDURA_CPF / VARREDURA_SENHA e VARREDURA_LIMITADO_CPF para usar outras contas.
 * VARREDURA_EXTRA="/admin/pessoas/5,/dashboard/atividades/9" confere endereços a mais.
 */
import { chromium } from 'playwright';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const BASE = (process.env.VARREDURA_URL ?? 'http://localhost:5173').replace(/\/$/, '');
const CPF = process.env.VARREDURA_CPF ?? '90000000175';
const SENHA = process.env.VARREDURA_SENHA ?? 'varredura123';
const CPF_LIMITADO = process.env.VARREDURA_LIMITADO_CPF ?? '90000000256';
const SENHA_LIMITADO = process.env.VARREDURA_LIMITADO_SENHA ?? 'varredura123';
const MAX_POR_TIPO = 2;
const PASTA = 'varredura';

/** Telas do painel por onde a varredura começa (o resto vem pelos links). */
const INICIO_ADMIN = [
	'/',
	'/dashboard',
	'/dashboard/perfil',
	'/dashboard/perfil/senha',
	'/dashboard/equipes',
	'/dashboard/formularios',
	'/admin/usuarios',
	'/admin/atividades',
	'/admin/acampamentos/criar',
	'/admin/eventos/criar',
	'/admin/equipes',
	'/admin/equipes?aba=modelo',
	'/admin/setores',
	'/admin/formularios',
	'/admin/categorias',
	'/admin/participacoes'
];
const INICIO_VISITANTE = ['/', '/dashboard', '/login', '/registro'];
/** Endereços a mais para conferir nesta execução, separados por vírgula (só como organização). */
const EXTRA = (process.env.VARREDURA_EXTRA ?? '').split(',').map((s) => s.trim()).filter(Boolean);

/** O visitante que abre uma tela do painel ou da conta tem que cair no login. */
const SO_COM_LOGIN = ['/admin/usuarios', '/admin/atividades', '/admin/categorias', '/dashboard/perfil'];

/** Telas que têm abas pelo endereço: abre cada aba também. */
const ABAS = [[/^\/admin\/inscricoes\/\d+$/, ['?aba=campistas', '?aba=servos']]];

/** Avisos do console que não são problema do site. */
const RUIDO_DO_CONSOLE = [/^Failed to load resource/, /\[vite\]/i, /favicon/i];

// ─── Ajudas ────────────────────────────────────────────────────────────────

/** "/admin/pessoas/12?volta=3" → "/admin/pessoas/:id?volta=:id" (o tipo de tela). */
function tipoDaTela(caminho) {
	const url = new URL(caminho, BASE);
	const p = url.pathname.replace(/\/\d+(?=\/|$)/g, '/:id');
	const q = [...url.searchParams.keys()]
		.sort()
		.map((k) => `${k}=${/^\d+$/.test(url.searchParams.get(k) ?? '') ? ':id' : url.searchParams.get(k)}`)
		.join('&');
	return q ? `${p}?${q}` : p;
}

/** Só links deste site que levam a telas (não à API, a arquivos ou a fora). */
function linkDeTela(href) {
	let url;
	try {
		url = new URL(href);
	} catch {
		return null;
	}
	if (url.origin !== BASE) return null;
	if (/^\/(api|uploads)\//.test(url.pathname) || /\.\w{2,4}$/.test(url.pathname)) return null;
	return url.pathname + url.search;
}

const nomeDoArquivo = (n, caminho) =>
	`${String(n).padStart(3, '0')}-${caminho.replace(/^\//, '').replace(/[^\w-]+/g, '_').slice(0, 60) || 'inicio'}.png`;

async function entrar(cpf, senha) {
	const res = await fetch(`${BASE}/api/v1/auth/login`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
		body: JSON.stringify({ cpf, password: senha })
	}).catch(() => null);
	if (!res) throw new Error(`Não consegui falar com ${BASE}. O front (npm run dev) e o backend (composer servidor) estão rodando?`);
	const corpo = await res.json().catch(() => ({}));
	const token = corpo.access_token ?? corpo.data?.access_token;
	if (!res.ok || !token)
		throw new Error(`O login de ${cpf} falhou (HTTP ${res.status}). Rode "php bin/cake.php usuarios_varredura" em backend/ (ou ajuste VARREDURA_CPF / VARREDURA_SENHA).`);
	return token;
}

// ─── Uma tela ──────────────────────────────────────────────────────────────

async function visitar(contexto, caminho, persona) {
	const page = await contexto.newPage();
	const problemas = [];
	const avisos = [];

	page.on('pageerror', (e) => problemas.push(`Erro de JavaScript: ${e.message}`));
	page.on('console', (m) => {
		if (m.type() !== 'error') return;
		const texto = m.text();
		if (!RUIDO_DO_CONSOLE.some((r) => r.test(texto))) problemas.push(`Console: ${texto.slice(0, 300)}`);
	});
	page.on('response', (r) => {
		const url = new URL(r.url());
		if (!url.pathname.startsWith('/api/') || r.status() < 400) return;
		// O visitante recebe 401 nas chamadas de quem está logado: é o esperado.
		if (persona === 'visitante' && r.status() === 401) return;
		problemas.push(`API ${r.request().method()} ${url.pathname}${url.search} → ${r.status()}`);
	});
	page.on('requestfailed', (r) => {
		const erro = r.failure()?.errorText ?? '';
		if (r.url().startsWith(BASE + '/api/') && !/ABORTED/i.test(erro)) problemas.push(`API sem resposta: ${r.url()} (${erro})`);
	});

	let status = 0;
	try {
		const res = await page.goto(BASE + caminho, { waitUntil: 'networkidle', timeout: 25000 });
		status = res?.status() ?? 0;
	} catch (e) {
		problemas.push(`Não terminou de abrir em 25 s (${e.message.split('\n')[0]})`);
	}
	await page.waitForTimeout(300);

	if (status >= 400) problemas.push(`A tela respondeu ${status}${status === 404 ? ' (não existe)' : ''}`);

	const finalUrl = new URL(page.url());
	const foiPara = finalUrl.pathname + finalUrl.search;
	const texto = await page.locator('body').innerText().catch(() => '');
	if (/Carregando/i.test(texto)) avisos.push('Ainda mostra "Carregando" depois que a rede parou.');
	const erroNaTela = texto.match(/(Não foi possível[^\n]{0,120}|Erro ao [^\n]{0,120}|Algo deu errado[^\n]{0,120})/i);
	if (erroNaTela) avisos.push(`Mensagem de erro na tela: "${erroNaTela[1].trim()}"`);
	if (texto.trim().length < 20) problemas.push('A tela ficou em branco.');

	const links = await page.$$eval('a[href]', (as) => as.map((a) => a.href)).catch(() => []);
	return { page, caminho, foiPara, problemas, avisos, links };
}

// ─── Uma pessoa navegando ─────────────────────────────────────────────────

async function varrer(navegador, persona, inicio, token, resultados) {
	const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR' });
	if (token) await contexto.addInitScript((t) => localStorage.setItem('token', t), token);

	const fila = [...inicio];
	const visitados = new Set();
	const porTipo = new Map();
	mkdirSync(join(PASTA, persona), { recursive: true });

	while (fila.length) {
		const caminho = fila.shift();
		if (visitados.has(caminho)) continue;
		const tipo = tipoDaTela(caminho);
		const vezes = porTipo.get(tipo) ?? 0;
		if (vezes >= MAX_POR_TIPO && !inicio.includes(caminho)) continue;
		visitados.add(caminho);
		porTipo.set(tipo, vezes + 1);

		const r = await visitar(contexto, caminho, persona);
		const foto = join(persona, nomeDoArquivo(resultados.length + 1, caminho));
		await r.page.screenshot({ path: join(PASTA, foto), fullPage: true }).catch(() => {});
		await r.page.close();

		// O visitante só navega pela parte pública: o painel tem o teste próprio abaixo.
		for (const href of r.links) {
			const proximo = linkDeTela(href);
			if (!proximo || visitados.has(proximo)) continue;
			if (persona === 'visitante' && proximo.startsWith('/admin')) continue;
			fila.push(proximo);
		}
		for (const [regra, abas] of ABAS) {
			const p = new URL(caminho, BASE);
			if (!p.search && regra.test(p.pathname)) fila.push(...abas.map((a) => p.pathname + a));
		}

		const res = { persona, caminho: r.caminho, foiPara: r.foiPara, problemas: [...new Set(r.problemas)], avisos: r.avisos, foto };
		resultados.push(res);
		imprimir(res);
	}

	if (persona === 'visitante') {
		for (const caminho of SO_COM_LOGIN) {
			const r = await visitar(contexto, caminho, persona);
			await r.page.close();
			const problemas = r.foiPara.startsWith('/login') ? [] : [`Visitante abriu sem login (ficou em ${r.foiPara}).`];
			const res = { persona, caminho: `${caminho} (sem login → login)`, foiPara: r.foiPara, problemas, avisos: [], foto: null };
			resultados.push(res);
			imprimir(res);
		}
	}
	await contexto.close();
}

function imprimir(r) {
	const marca = r.problemas.length ? '✖' : r.avisos.length ? '!' : '✔';
	const desvio = r.foiPara && r.foiPara !== r.caminho && !r.caminho.includes('→') ? `  → ${r.foiPara}` : '';
	console.log(`${marca} [${r.persona}] ${r.caminho}${desvio}`);
	for (const p of r.problemas) console.log(`    ✖ ${p}`);
	for (const a of r.avisos) console.log(`    ! ${a}`);
}

// ─── Acesso limitado ──────────────────────────────────────────────────────

/**
 * Quem só tem Categorias: o menu só pode mostrar Categorias, e qualquer outra
 * tela do painel aberta pelo endereço volta, sem aviso, para Categorias.
 */
async function varrerLimitado(navegador, token, resultados) {
	const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR' });
	await contexto.addInitScript((t) => localStorage.setItem('token', t), token);
	mkdirSync(join(PASTA, 'limitado'), { recursive: true });
	const PERMITIDA = '/admin/categorias';
	const OUTRAS_DO_MENU = ['Pessoas e acessos', 'Gestão de Atividades', 'Criar Acampamento', 'Criar Evento', 'Equipes de Servos', 'Formulários', 'Participações anteriores'];

	for (const caminho of INICIO_ADMIN.filter((c) => c.startsWith('/admin'))) {
		const r = await visitar(contexto, caminho, 'limitado');
		const foto = join('limitado', nomeDoArquivo(resultados.length + 1, caminho));
		await r.page.screenshot({ path: join(PASTA, foto), fullPage: true }).catch(() => {});
		const menu = await r.page.locator('nav, aside').allInnerTexts().catch(() => []);
		await r.page.close();

		const problemas = [...new Set(r.problemas)];
		if (!r.foiPara.startsWith(PERMITIDA)) problemas.push(`Devia voltar para ${PERMITIDA}, mas ficou em ${r.foiPara}.`);
		const vazou = OUTRAS_DO_MENU.filter((nome) => menu.some((m) => m.includes(nome)));
		if (vazou.length) problemas.push(`O menu mostra telas que a pessoa não pode abrir: ${vazou.join(', ')}.`);
		const res = { persona: 'limitado', caminho, foiPara: r.foiPara, problemas, avisos: r.avisos, foto };
		resultados.push(res);
		imprimir(res);
	}
	await contexto.close();
}

// ─── Relatório ────────────────────────────────────────────────────────────

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function relatorio(resultados, inicio) {
	const falhas = resultados.filter((r) => r.problemas.length).length;
	const avisos = resultados.filter((r) => !r.problemas.length && r.avisos.length).length;
	const linhas = resultados
		.map((r) => {
			const estado = r.problemas.length ? 'falha' : r.avisos.length ? 'aviso' : 'ok';
			const itens = [...r.problemas.map((p) => `<li class="p">${esc(p)}</li>`), ...r.avisos.map((a) => `<li class="a">${esc(a)}</li>`)].join('');
			const foto = r.foto ? `<a href="${esc(r.foto.replace(/\\/g, '/'))}"><img loading="lazy" src="${esc(r.foto.replace(/\\/g, '/'))}"></a>` : '';
			const desvio = r.foiPara && r.foiPara !== r.caminho ? `<div class="d">foi para ${esc(r.foiPara)}</div>` : '';
			return `<section class="${estado}"><header><b>${esc(r.persona)}</b> ${esc(r.caminho)}</header>${desvio}<ul>${itens}</ul>${foto}</section>`;
		})
		.join('\n');
	return `<!doctype html><meta charset="utf-8"><title>Varredura</title>
<style>
body{font:14px system-ui,sans-serif;margin:24px;background:#f6f4ee;color:#1b2f5e}
h1{margin:0 0 4px}.resumo{margin-bottom:16px}
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
section{background:#fff;border:1px solid #ddd;border-left:6px solid #2e7d32;border-radius:6px;padding:10px}
section.falha{border-left-color:#9b2c2c}section.aviso{border-left-color:#b69e55}
header{word-break:break-all}.d{color:#666;font-size:12px}
ul{margin:6px 0;padding-left:18px}li.p{color:#9b2c2c}li.a{color:#7a6420}
img{width:100%;max-height:260px;object-fit:cover;object-position:top;border:1px solid #eee;margin-top:6px}
label{margin-left:12px}
</style>
<h1>Varredura do site</h1>
<div class="resumo">${resultados.length} telas · <b style="color:#9b2c2c">${falhas} com falha</b> · ${avisos} com aviso · ${esc(inicio)}
<label><input type="checkbox" id="so"> só falhas e avisos</label></div>
<div class="grade">${linhas}</div>
<script>so.onchange=()=>document.querySelectorAll('section.ok').forEach(s=>s.hidden=so.checked)</script>`;
}

// ─── Principal ────────────────────────────────────────────────────────────

const inicio = new Date().toLocaleString('pt-BR');
rmSync(PASTA, { recursive: true, force: true });
mkdirSync(PASTA, { recursive: true });

const [token, tokenLimitado] = await Promise.all([entrar(CPF, SENHA), entrar(CPF_LIMITADO, SENHA_LIMITADO)]).catch((e) => {
	console.error(e.message);
	process.exit(2);
});

const navegador = await chromium.launch();
const resultados = [];
try {
	console.log(`\n— Visitante (sem login) em ${BASE}\n`);
	await varrer(navegador, 'visitante', INICIO_VISITANTE, null, resultados);
	console.log(`\n— Organização, todas as permissões (CPF ${CPF})\n`);
	await varrer(navegador, 'admin', [...INICIO_ADMIN, ...EXTRA], token, resultados);
	console.log(`\n— Acesso limitado, só Categorias (CPF ${CPF_LIMITADO})\n`);
	await varrerLimitado(navegador, tokenLimitado, resultados);
} finally {
	await navegador.close();
}

writeFileSync(join(PASTA, 'relatorio.html'), relatorio(resultados, inicio));
const falhas = resultados.filter((r) => r.problemas.length);
const comAviso = resultados.filter((r) => !r.problemas.length && r.avisos.length);
console.log(`\n${resultados.length} telas · ${falhas.length} com falha · ${comAviso.length} com aviso`);
console.log(`Relatório com as fotos: ${join(process.cwd(), PASTA, 'relatorio.html')}`);
process.exit(falhas.length ? 1 : 0);

<script lang="ts">
	import { auth } from "$lib/stores/auth.svelte";
	import { acessaPainel, primeiraTela } from "$lib/permissoes";
	import { getActivities } from "$lib/services/activityService";
	import { getMinhasInscricoes } from "$lib/services/inscricaoService";
	import { API_HOST } from "$lib/api";
	import { loginUrl } from "$lib/redirect";
	import type { Activity } from "$lib/types/models";
	import { onMount } from "svelte";
	import banner from "$lib/imgs/banner.png";
	import logoAsas from "$lib/imgs/logo-asas.png";

	let activities: Activity[] = $state([]);
	let loading = $state(true);
	let currentFilter = $state("Todos");
	let minhasInscricoesMap: Record<number, string> = $state({});
	/** Formulários que o usuário precisa responder — vira o contador do menu. */
	let formulariosPendentes = $state(0);

	// A lista de atividades é pública: visitante vê tudo, e só precisa entrar
	// (ou criar a conta) na hora de se inscrever.
	onMount(async () => {
		try {
			const activitiesRes = await getActivities();
			if (!activitiesRes.error) {
				activities = activitiesRes.activities;
			}
		} catch (e) {
			console.error("Erro na API:", e);
		} finally {
			loading = false;
		}
	});

	// As inscrições da pessoa só existem com login — espera a sessão salva ser
	// conferida, senão o visitante levaria um 401 e iria parar no login.
	let inscricoesCarregadas = false;
	$effect(() => {
		if (auth.isLoading || !auth.isAuthenticated || inscricoesCarregadas) return;
		inscricoesCarregadas = true;
		getMinhasInscricoes()
			.then((inscricoesRes) => {
				const map: Record<number, string> = {};
				for (const insc of inscricoesRes.inscricoes) {
					if (insc.activity) map[insc.activity.id] = insc.status;
				}
				minhasInscricoesMap = map;
				formulariosPendentes = inscricoesRes.inscricoes.filter(
					(i) => i.temFormulario && i.formularioPendente,
				).length;
			})
			.catch((e) => console.error("Erro na API:", e));
	});

	function linkInscrever(id: number) {
		const destino = `/dashboard/atividades/${id}?inscrever=1`;
		return auth.isAuthenticated ? destino : loginUrl(destino);
	}

	const FILTROS = [
		{ chave: "Todos", rotulo: "Todos" },
		{ chave: "Acampamentos", rotulo: "Acampamentos" },
		{ chave: "Eventos", rotulo: "Eventos Especiais" },
	];

	function doFiltro(chave: string, lista: Activity[]) {
		if (chave === "Todos") return lista;
		const tipo = chave === "Acampamentos" ? "Campings" : "Events";
		return lista.filter((a) => a.activitable_type === tipo);
	}

	let filteredActivities = $derived(doFiltro(currentFilter, activities));

	function handleLogout() {
		auth.clearToken();
		minhasInscricoesMap = {};
		formulariosPendentes = 0;
		inscricoesCarregadas = false;
	}

	function statusSlug(status?: string) {
		if (status === "Inscrições Abertas") return "open";
		if (status === "Encerrado") return "closed";
		return "soon";
	}

	function inscricaoSlug(status?: string) {
		if (status === "Sorteado" || status === "Indicado" || status === "Formulário Enviado") return "confirmed";
		if (status === "Suplente") return "waiting";
		if (status === "Desistência" || status === "Prazo Perdido") return "cancelled";
		return "pending";
	}

	function imagemDe(item: Activity) {
		if (!item.image || item.image === "https://via.placeholder.com/800x400") return null;
		return item.image.startsWith("http") ? item.image : API_HOST + item.image;
	}
</script>

<svelte:head>
	<title>Acampamentos e Eventos | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

<div class="pagina">
	<header class="cabecalho">
		<img src={banner} alt="Comunidade São Miguel do Arcanjo" class="banner" />
		{#if auth.isLoading}
			<!-- conferindo a sessão salva -->
		{:else if auth.isAuthenticated && auth.user}
			<div class="usuario">
				<div class="usuario-saudacao">
					<div class="avatar">{auth.user.name?.charAt(0).toUpperCase()}</div>
					<div class="usuario-nome">
						<span class="usuario-bemvindo">Bem-vindo(a),</span>
						<span class="usuario-primeiro-nome">{auth.user.name?.split(" ")[0]}</span>
					</div>
				</div>
				{#if acessaPainel()}
					<a href={primeiraTela()} class="btn-admin">Painel</a>
				{/if}
				<button class="btn-sair" onclick={handleLogout}>Sair</button>
			</div>
		{:else}
			<a href="/login" class="btn-entrar">Entrar</a>
		{/if}
	</header>

	<!-- Topo em "vitral" -->
	<section class="vitral">
		<div class="vitral-conteudo">
			<div class="vitral-textos">
				<div class="sobretitulo">
					<span class="sobretitulo-traco"></span>
					<span>Primavera do Leste · MT</span>
				</div>
				<h1>Acampamentos e Eventos</h1>
				<p>Confira os próximos eventos da comunidade.</p>
			</div>
			<div class="vitral-arco">
				<img src={logoAsas} alt="" />
			</div>
		</div>
	</section>

	<div class="barra">
		<nav class="filtros">
			{#each FILTROS as filtro}
				<button
					class="filtro"
					class:ativo={currentFilter === filtro.chave}
					onclick={() => (currentFilter = filtro.chave)}
				>
					{filtro.rotulo}
					<span class="filtro-contador">{doFiltro(filtro.chave, activities).length}</span>
				</button>
			{/each}
		</nav>
		{#if !auth.isLoading && auth.isAuthenticated}
			<div class="atalhos">
				<a class="atalho" href="/dashboard/formularios">
					<span class="losango"></span>Meus Formulários
					{#if formulariosPendentes > 0}
						<span class="selo-pendentes">{formulariosPendentes}</span>
					{/if}
				</a>
				<a class="atalho" href="/dashboard/equipes">
					<span class="losango"></span>Minha Equipe
				</a>
			</div>
		{/if}
	</div>

	{#if !auth.isLoading && !auth.isAuthenticated}
		<div class="aviso-visitante">
			<div class="aviso-visitante-textos">
				<span class="aviso-visitante-titulo">Quer participar?</span>
				<span>Para se inscrever em um acampamento ou evento, entre na sua conta ou crie uma.</span>
			</div>
			<a href="/login" class="btn-aviso">Entrar ou criar conta</a>
		</div>
	{/if}

	<main class="conteudo">
		{#if loading}
			<div class="carregando">
				<span class="spinner"></span>Buscando atividades...
			</div>
			<div class="grade">
				{#each [1, 2, 3, 4] as _}
					<div class="esqueleto">
						<div class="esqueleto-arco"></div>
						<div class="esqueleto-barra" style="height: 22px; width: 75%; margin: 6px 6px 0;"></div>
						<div class="esqueleto-barra clara" style="width: 55%;"></div>
						<div class="esqueleto-barra clara" style="width: 45%;"></div>
						<div class="esqueleto-botao"></div>
					</div>
				{/each}
			</div>
		{:else if filteredActivities.length === 0}
			<div class="vazio">
				<div class="vazio-arco">
					<img src={logoAsas} alt="" />
				</div>
				<p class="vazio-titulo">Nenhuma atividade encontrada.</p>
				<p class="vazio-texto">Tente outro filtro ou volte em breve.</p>
			</div>
		{:else}
			<div class="grade">
				{#each filteredActivities as item}
					{@const imagem = imagemDe(item)}
					{@const encerrado = statusSlug(item.status) === "closed"}
					<article class="card" class:encerrado>
						<div class="card-arco" class:sem-imagem={!imagem}>
							{#if imagem}
								<img src={imagem} alt="" class="card-foto" />
							{:else}
								<img src={logoAsas} alt="" class="card-logo" />
							{/if}
							<div class="card-moldura"></div>
							<div class="card-etiquetas">
								{#if item.activitable_type === "Campings"}
									<span class="etiqueta etiqueta-acampamento">Acampamento</span>
								{:else}
									<span class="etiqueta etiqueta-evento">Evento</span>
								{/if}
								<span class="status status-{statusSlug(item.status)}">
									{#if statusSlug(item.status) === "open"}<span class="status-ponto"></span>{/if}
									{item.status || "Em Breve"}
								</span>
							</div>
						</div>

						<div class="card-corpo">
							<h3 class="card-titulo">{item.name}</h3>
							<div class="filete">
								<span class="filete-traco"></span>
								<span class="filete-losango"></span>
								<span class="filete-linha"></span>
							</div>
							<dl class="card-dados">
								<dt>Início</dt>
								<dd>
									{item.start_date
										? new Date(item.start_date).toLocaleDateString("pt-BR")
										: "A definir"}
								</dd>
								{#if item.category || item.targetAudience}
									<dt>Público</dt>
									<dd>{item.category ? item.category.name : item.targetAudience}</dd>
								{/if}
								{#if item.place}
									<dt>Local</dt>
									<dd>{item.place}</dd>
								{/if}
							</dl>

							{#if minhasInscricoesMap[item.id]}
								<div class="inscrito inscrito-{inscricaoSlug(minhasInscricoesMap[item.id])}">
									<span class="inscrito-ponto"></span>
									<span>Você está inscrito(a) · <strong>{minhasInscricoesMap[item.id]}</strong></span>
								</div>
							{/if}

							<div class="card-botoes">
								<a href="/dashboard/atividades/{item.id}" class="btn-detalhes">Ver Detalhes</a>
								{#if item.status === "Inscrições Abertas" && !minhasInscricoesMap[item.id]}
									<a href={linkInscrever(item.id)} class="btn-inscrever">Inscrever-se</a>
								{/if}
							</div>
						</div>
					</article>
				{/each}
			</div>
		{/if}
	</main>
</div>

<style>
	:global(body) {
		margin: 0;
		background: #f7f2e7;
	}

	.pagina {
		--ouro: #b69e55;
		--ouro-claro: #e2ce8a;
		--ouro-texto: #7a6528;
		--ouro-borda: #cdb878;
		--azul: #1b2f5e;
		--azul-noite: #0f1d3d;
		--vermelho: #9b2c2c;
		--marfim: #f7f2e7;
		--pergaminho: #fffdf8;
		--borda: #d9cca6;
		--borda-suave: #e3d8bc;
		--tinta: #1e2233;
		--tinta-2: #2b3045;
		--tinta-3: #4f5468;
		--cinza-encerrado: #5e6272;
		--margem: clamp(12px, 3vw, 48px);

		min-height: 100vh;
		background: var(--marfim);
		font-family: "Source Sans 3", system-ui, sans-serif;
		color: var(--tinta);
	}

	a,
	button {
		font-family: inherit;
		box-sizing: border-box;
	}

	/* ─── Cabeçalho ─────────────────────────────────────────────── */
	.cabecalho {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding: 10px var(--margem);
		background: var(--pergaminho);
		border-bottom: 1px solid var(--borda);
		/* filete dourado duplo */
		box-shadow:
			0 3px 0 -1px #fffdf8,
			0 4px 0 -1px #b69e55;
		position: relative;
		z-index: 2;
	}

	.banner {
		height: clamp(44px, 4.2vw, 60px);
		width: auto;
		display: block;
	}

	.btn-entrar {
		font-size: 16px;
		font-weight: 600;
		color: var(--pergaminho);
		background: var(--azul);
		border: 1px solid var(--azul);
		border-radius: 999px;
		padding: 11px 26px;
		text-decoration: none;
		box-shadow:
			inset 0 0 0 2px #1b2f5e,
			inset 0 0 0 3px rgba(182, 158, 85, 0.8);
		transition: background 0.2s;
	}

	.btn-entrar:hover {
		background: var(--azul-noite);
	}

	.usuario {
		display: flex;
		align-items: center;
		gap: clamp(8px, 1.1vw, 16px);
	}

	.usuario-saudacao {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.avatar {
		width: 42px;
		height: 42px;
		border-radius: 50%;
		background: var(--azul);
		border: 2px solid var(--ouro);
		display: flex;
		align-items: center;
		justify-content: center;
		font: 700 22px "Cormorant Garamond", serif;
		color: var(--ouro-claro);
		flex: 0 0 auto;
		box-sizing: border-box;
	}

	.usuario-nome {
		display: flex;
		flex-direction: column;
		line-height: 1.15;
	}

	.usuario-bemvindo {
		font-size: 13px;
		color: #5a5f72;
	}

	.usuario-primeiro-nome {
		font-size: 17px;
		font-weight: 700;
		color: var(--tinta);
	}

	.btn-admin,
	.btn-sair {
		font-size: 15px;
		font-weight: 600;
		border-radius: 999px;
		padding: 9px 16px;
		cursor: pointer;
		white-space: nowrap;
		text-decoration: none;
		transition: background 0.2s;
	}

	.btn-admin {
		color: #6b5822;
		background: #fbf6e6;
		border: 1px solid var(--ouro);
	}

	.btn-admin:hover {
		background: #f3e9c8;
	}

	.btn-sair {
		color: var(--azul);
		background: transparent;
		border: 1px solid #c9cfde;
	}

	.btn-sair:hover {
		background: #eef1f7;
	}

	/* ─── Topo em vitral ────────────────────────────────────────── */
	.vitral {
		position: relative;
		overflow: hidden;
		margin: clamp(12px, 2vw, 28px) var(--margem) 0;
		border-radius: clamp(14px, 1.6vw, 22px);
		background:
			radial-gradient(circle at 12% 30%, rgba(214, 186, 104, 0.35), transparent 28%),
			radial-gradient(circle at 70% 120%, rgba(31, 107, 110, 0.85), transparent 45%),
			radial-gradient(circle at 95% 0%, rgba(155, 44, 44, 0.35), transparent 30%),
			linear-gradient(150deg, #22397a 0%, #1b2f5e 45%, #0f1d3d 100%);
		box-shadow:
			inset 0 0 0 6px #0f1d3d,
			inset 0 0 0 7px rgba(182, 158, 85, 0.75);
	}

	/* o "chumbo" que separa os vidros */
	.vitral::before {
		content: "";
		position: absolute;
		inset: 0;
		background:
			repeating-linear-gradient(45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px),
			repeating-linear-gradient(-45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px);
		pointer-events: none;
	}

	.vitral-conteudo {
		position: relative;
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 24px;
		padding: clamp(28px, 4.4vw, 64px) clamp(22px, 4.4vw, 64px);
	}

	.vitral-textos {
		max-width: 640px;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	.sobretitulo {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 13px;
		font-weight: 600;
		letter-spacing: 0.16em;
		text-transform: uppercase;
		color: var(--ouro-claro);
	}

	.sobretitulo-traco {
		width: 28px;
		height: 1px;
		background: var(--ouro);
	}

	.vitral h1 {
		margin: 0;
		font: 600 clamp(36px, 5vw, 64px) / 1.02 "Cormorant Garamond", serif;
		color: var(--pergaminho);
		text-wrap: balance;
	}

	.vitral p {
		margin: 0;
		font-size: clamp(17px, 1.4vw, 20px);
		line-height: 1.45;
		color: #e6e9f2;
	}

	.vitral-arco {
		flex: 0 0 auto;
		width: 210px;
		height: 230px;
		margin: -20px 0 -64px;
		border: 1px solid rgba(226, 206, 138, 0.7);
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 38% 38% 0 0;
		display: flex;
		align-items: center;
		justify-content: center;
		box-shadow:
			inset 0 0 0 8px rgba(15, 29, 61, 0.35),
			inset 0 0 0 9px rgba(226, 206, 138, 0.35);
		background: radial-gradient(circle at 50% 45%, rgba(226, 206, 138, 0.22), transparent 60%);
	}

	.vitral-arco img {
		width: 200px;
		height: auto;
		display: block;
		filter: brightness(1.15);
	}

	/* ─── Filtros e atalhos ─────────────────────────────────────── */
	.barra {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 14px;
		padding: clamp(18px, 2vw, 28px) var(--margem) 0;
	}

	.filtros {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	.filtro {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta-2);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 999px;
		padding: 10px 18px;
		cursor: pointer;
		transition:
			background 0.2s,
			border-color 0.2s;
	}

	.filtro:hover {
		border-color: var(--ouro);
		background: #fbf6e6;
	}

	.filtro-contador {
		font-size: 13px;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 999px;
		padding: 1px 8px;
	}

	.filtro.ativo {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.filtro.ativo .filtro-contador {
		color: var(--azul);
		background: var(--ouro-claro);
	}

	.atalhos {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 10px;
		flex: 1 1 340px;
		max-width: 440px;
	}

	.atalho {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 10px;
		padding: 10px 16px;
		text-decoration: none;
		transition: border-color 0.2s;
	}

	.atalho:hover {
		border-color: var(--ouro);
	}

	.losango {
		width: 8px;
		height: 8px;
		background: var(--ouro);
		transform: rotate(45deg);
		flex: 0 0 auto;
	}

	.selo-pendentes {
		min-width: 22px;
		height: 22px;
		border-radius: 999px;
		background: var(--vermelho);
		color: var(--pergaminho);
		font-size: 13px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 0 6px;
		box-sizing: border-box;
	}

	.aviso-visitante {
		margin: 16px var(--margem) 0;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px 24px;
		padding: 16px 20px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 12px;
		box-shadow:
			inset 0 0 0 4px #fffdf8,
			inset 0 0 0 5px rgba(182, 158, 85, 0.35);
	}

	.aviso-visitante-textos {
		display: flex;
		flex-direction: column;
		gap: 2px;
		max-width: 720px;
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.aviso-visitante-titulo {
		font: 700 22px "Cormorant Garamond", serif;
		color: var(--azul);
	}

	.btn-aviso {
		font-size: 16px;
		font-weight: 600;
		color: var(--azul-noite);
		background: #c9af62;
		border: 1px solid var(--ouro);
		border-radius: 999px;
		padding: 10px 22px;
		text-decoration: none;
		transition: background 0.2s;
	}

	.btn-aviso:hover {
		background: var(--ouro);
	}

	/* ─── Lista ─────────────────────────────────────────────────── */
	.conteudo {
		padding: clamp(18px, 2vw, 28px) var(--margem) clamp(32px, 4vw, 64px);
	}

	.grade {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
		gap: 22px;
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-bottom: 18px;
		font-size: 17px;
		color: var(--tinta-3);
	}

	.spinner {
		width: 18px;
		height: 18px;
		border-radius: 50%;
		border: 2px solid var(--borda-suave);
		border-top-color: var(--ouro);
		box-sizing: border-box;
		animation: girar 1s linear infinite;
	}

	@keyframes girar {
		to {
			transform: rotate(360deg);
		}
	}

	.esqueleto {
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		padding: 12px;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	.esqueleto-arco {
		height: 190px;
		border-radius: 50% 50% 6px 6px / 90px 90px 6px 6px;
		background: linear-gradient(90deg, #ede5d1, #f5efe0, #ede5d1);
	}

	.esqueleto-barra {
		height: 14px;
		border-radius: 4px;
		background: #ede5d1;
		margin: 0 6px;
	}

	.esqueleto-barra.clara {
		background: #f1eada;
	}

	.esqueleto-botao {
		height: 44px;
		border-radius: 999px;
		background: #f1eada;
		margin: 8px 6px 6px;
	}

	.vazio {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 14px;
		padding: 56px 20px;
	}

	.vazio-arco {
		width: 150px;
		height: 170px;
		border: 1px solid var(--ouro-borda);
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 38% 38% 0 0;
		display: flex;
		align-items: center;
		justify-content: center;
		background: radial-gradient(circle at 50% 50%, #fbf6e6, transparent 70%);
	}

	.vazio-arco img {
		width: 140px;
		opacity: 0.55;
	}

	.vazio-titulo {
		margin: 0;
		font: 600 28px "Cormorant Garamond", serif;
		color: var(--azul);
	}

	.vazio-texto {
		margin: 0;
		font-size: 16px;
		color: var(--tinta-3);
	}

	/* ─── Card ──────────────────────────────────────────────────── */
	.card {
		display: flex;
		flex-direction: column;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		padding: 10px;
		box-shadow: 0 14px 28px -20px rgba(15, 29, 61, 0.45);
	}

	/* imagem em arco de janela de igreja */
	.card-arco {
		position: relative;
		height: 200px;
		border-radius: 50% 50% 6px 6px / 92px 92px 6px 6px;
		overflow: hidden;
		box-shadow: inset 0 0 0 1px #cdb878;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.card.encerrado .card-arco {
		filter: grayscale(0.75) opacity(0.7);
	}

	.card-foto {
		position: absolute;
		inset: 0;
		width: 100%;
		height: 100%;
		object-fit: cover;
	}

	/* atividade sem foto: vitral com o logo */
	.card-arco.sem-imagem {
		background:
			repeating-linear-gradient(45deg, transparent 0 26px, rgba(8, 14, 32, 0.45) 26px 28px),
			repeating-linear-gradient(-45deg, transparent 0 26px, rgba(8, 14, 32, 0.45) 26px 28px),
			radial-gradient(circle at 25% 25%, rgba(226, 196, 110, 0.55), transparent 35%),
			radial-gradient(circle at 80% 75%, rgba(31, 107, 110, 0.9), transparent 45%),
			radial-gradient(circle at 85% 15%, rgba(155, 44, 44, 0.45), transparent 30%),
			linear-gradient(160deg, #25407f, #1b2f5e 50%, #0f1d3d);
	}

	.card-logo {
		width: 150px;
		margin-top: -18px;
		filter: brightness(1.2) drop-shadow(0 2px 6px rgba(0, 0, 0, 0.4));
	}

	/* moldura dupla por cima da foto (img não mostra sombra interna) */
	.card-moldura {
		position: absolute;
		inset: 0;
		border-radius: inherit;
		box-shadow:
			inset 0 0 0 5px rgba(255, 253, 248, 0.9),
			inset 0 0 0 6px rgba(182, 158, 85, 0.8);
		pointer-events: none;
	}

	.card-etiquetas {
		position: absolute;
		left: 12px;
		right: 12px;
		bottom: 12px;
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 8px;
	}

	.etiqueta,
	.status {
		font-size: 12px;
		font-weight: 700;
		text-transform: uppercase;
		border-radius: 999px;
		padding: 5px 10px;
		white-space: nowrap;
	}

	.etiqueta {
		letter-spacing: 0.08em;
		color: var(--pergaminho);
	}

	.etiqueta-acampamento {
		background: var(--azul);
		border: 1px solid var(--ouro);
	}

	.etiqueta-evento {
		background: var(--vermelho);
		border: 1px solid #d9a9a0;
	}

	.status {
		letter-spacing: 0.04em;
	}

	.status-open {
		display: flex;
		align-items: center;
		gap: 6px;
		color: var(--azul-noite);
		background: var(--ouro-claro);
		border: 1px solid #fff3c8;
		box-shadow: 0 2px 8px rgba(15, 29, 61, 0.35);
	}

	.status-ponto {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		background: #1f6b3f;
		box-shadow: 0 0 0 3px rgba(31, 107, 63, 0.2);
	}

	.status-soon {
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid #c9cfde;
		padding: 5px 11px;
	}

	.status-closed {
		color: var(--pergaminho);
		background: var(--cinza-encerrado);
		padding: 5px 11px;
	}

	.card-corpo {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 16px 8px 6px;
		flex: 1;
	}

	.card-titulo {
		margin: 0;
		font: 600 25px / 1.12 "Cormorant Garamond", serif;
		color: var(--tinta);
		text-wrap: balance;
	}

	.card.encerrado .card-titulo {
		color: var(--tinta-3);
	}

	.filete {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.filete-traco {
		flex: 0 0 22px;
		height: 1px;
		background: var(--ouro);
	}

	.filete-losango {
		width: 5px;
		height: 5px;
		background: var(--ouro);
		transform: rotate(45deg);
	}

	.filete-linha {
		flex: 1;
		min-width: 0;
		height: 1px;
		background: var(--borda-suave);
	}

	.card-dados {
		margin: 0;
		display: grid;
		grid-template-columns: auto 1fr;
		gap: 6px 12px;
		font-size: 16px;
		line-height: 1.3;
	}

	.card-dados dt {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
		padding-top: 2px;
	}

	.card-dados dd {
		margin: 0;
		color: var(--tinta-2);
	}

	/* "Você está inscrito(a)", com a cor do andamento da inscrição */
	.inscrito {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		line-height: 1.3;
		border: 1px solid;
		border-radius: 8px;
		padding: 9px 12px;
	}

	.inscrito-ponto {
		flex: 0 0 8px;
		height: 8px;
		border-radius: 50%;
	}

	.inscrito-confirmed {
		background: #e4f1e8;
		color: #1b5236;
		border-color: #b9d9c4;
	}

	.inscrito-confirmed .inscrito-ponto {
		background: #2e7d4f;
	}

	.inscrito-waiting {
		background: #fbf0d6;
		color: #6a480c;
		border-color: #ebd39c;
	}

	.inscrito-waiting .inscrito-ponto {
		background: #c08a1e;
	}

	.inscrito-cancelled {
		background: #f6e2df;
		color: #842424;
		border-color: #e6bdb6;
	}

	.inscrito-cancelled .inscrito-ponto {
		background: #b33a3a;
	}

	.inscrito-pending {
		background: #e6ebf5;
		color: #21376a;
		border-color: #c4cfe6;
	}

	.inscrito-pending .inscrito-ponto {
		background: #3c5aa0;
	}

	.card-botoes {
		display: flex;
		gap: 8px;
		margin-top: auto;
		padding-top: 4px;
	}

	.btn-detalhes,
	.btn-inscrever {
		flex: 1;
		font-size: 16px;
		border-radius: 999px;
		padding: 11px 14px;
		text-align: center;
		text-decoration: none;
		transition: background 0.2s;
	}

	.btn-detalhes {
		font-weight: 600;
		color: var(--azul);
		background: transparent;
		border: 1.5px solid var(--azul);
	}

	.btn-detalhes:hover {
		background: #eef1f7;
	}

	.btn-inscrever {
		font-weight: 700;
		color: var(--azul-noite);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
		box-shadow: 0 4px 12px -6px rgba(122, 101, 40, 0.8);
	}

	.btn-inscrever:hover {
		background: var(--ouro);
	}

	/* O arco com o logo não cabe ao lado do título em tela estreita. */
	@media (max-width: 640px) {
		.vitral-arco {
			display: none;
		}
	}
</style>

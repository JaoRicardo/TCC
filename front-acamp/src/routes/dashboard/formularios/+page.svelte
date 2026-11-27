<script lang="ts">
	/**
	 * "Meus formulários" — as inscrições que têm formulário para responder, no
	 * mesmo visual de "Minha equipe" (marfim, azul e ouro). Daqui a pessoa abre a
	 * tela de resposta (`/dashboard/formulario/{id}`).
	 */
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { loginUrl } from '$lib/redirect';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import '$lib/styles/formularios.css';
	import { getMinhasInscricoes, type MinhaInscricao } from '$lib/services/inscricaoService';

	let estado: 'inicio' | 'carregando' | 'erro' | 'pronto' = $state('inicio');
	let inscricoes: MinhaInscricao[] = $state([]);

	async function carregar() {
		estado = 'carregando';
		const res = await getMinhasInscricoes();
		if (res.error) {
			estado = 'erro';
			return;
		}
		inscricoes = res.inscricoes;
		estado = 'pronto';
	}

	$effect(() => {
		if (auth.isLoading || estado !== 'inicio') return;
		if (!auth.isAuthenticated) {
			goto(loginUrl($page.url.pathname), { replaceState: true });
			return;
		}
		carregar();
	});

	// Só entram as inscrições que já têm formulário montado e em que a pessoa está
	// numa situação que dá direito a responder. Evento com formulário: a inscrição
	// fica "em andamento" até enviar e vira "Inscrito" depois. Correção pedida
	// reabre o formulário até o prazo da correção.
	const STATUS_COM_FORMULARIO = [
		'Sorteado',
		'Indicado',
		'Formulário Enviado',
		'Inscrição em andamento',
		'Inscrito',
		'Correção pedida'
	];

	const comFormulario = $derived(inscricoes.filter((i) => i.temFormulario && STATUS_COM_FORMULARIO.includes(i.status)));
	const correcao = (i: MinhaInscricao) => i.status === 'Correção pedida';
	const pendentes = $derived(comFormulario.filter((i) => correcao(i) || !i.formularioEnviado));
	const enviados = $derived(comFormulario.filter((i) => !correcao(i) && i.formularioEnviado));
	// Inscrições que ainda não chegaram a ter formulário, mostradas só como contexto.
	const outras = $derived(inscricoes.filter((i) => !comFormulario.includes(i) && i.status !== 'Desistência'));

	const prazoDe = (i: MinhaInscricao) => (correcao(i) ? i.prazoCorrecao : i.prazoFormulario) ?? null;

	function dataBr(iso?: string | null): string {
		if (!iso) return '';
		const [y, m, d] = iso.slice(0, 10).split('-');
		return `${d}/${m}/${y}`;
	}

	function diasRestantes(iso: string | null): number | null {
		if (!iso) return null;
		const fim = new Date(iso.slice(0, 10) + 'T23:59:59');
		const hoje = new Date();
		hoje.setHours(0, 0, 0, 0);
		return Math.floor((fim.getTime() - hoje.getTime()) / 86400000);
	}

	function textoPrazo(i: MinhaInscricao): string {
		const prazo = prazoDe(i);
		const dias = diasRestantes(prazo);
		if (dias === null) return 'Sem prazo definido.';
		const ate = `até ${dataBr(prazo)}`;
		if (dias < 0) return `O prazo terminou em ${dataBr(prazo)}.`;
		if (dias === 0) return `Último dia: responda ${ate}.`;
		if (dias === 1) return `Falta 1 dia: responda ${ate}.`;
		return `Faltam ${dias} dias: responda ${ate}.`;
	}

	function urgente(i: MinhaInscricao): boolean {
		const dias = diasRestantes(prazoDe(i));
		return dias !== null && dias <= 2;
	}

	const real = (v: number) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

	function textoPagamento(i: MinhaInscricao): string | null {
		const p = i.pagamento;
		const pagoEm = p?.pagoEm ?? i.pagoEm;
		if (pagoEm) return `Pago em ${dataBr(pagoEm)}.`;
		if (i.isPago) return 'Inscrição paga.';
		if (i.status === 'Suplente') return 'Suplente só paga se for chamado para uma vaga.';
		if (p?.precisaPagar && p.valor > 0) {
			return `Pague ${real(p.valor)} diretamente com a organização${p.prazo ? ` até ${dataBr(p.prazo)}` : ''}.`;
		}
		return null;
	}

	function textoOutra(i: MinhaInscricao): string {
		if (i.perdeuPrazo || i.status === 'Prazo Perdido') return 'O prazo terminou e a vaga foi repassada.';
		if (i.status === 'Cancelada pela organização' || i.status === 'Recusada') return 'Esta inscrição foi encerrada pela organização.';
		if (i.status === 'Retirado do sorteio') return 'Você não concorre mais neste sorteio.';
		if (i.naoSorteado) return 'Você não foi sorteado desta vez.';
		if (i.status === 'Suplente') return 'Você está na lista de suplentes: o formulário abre se você for chamado(a) para uma vaga.';
		if (!i.temFormulario) return 'A organização ainda não montou o formulário.';
		return 'O formulário abre quando você for sorteado ou indicado.';
	}
</script>

<svelte:head>
	<title>Meus formulários | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

<div class="pagina">
	<CabecalhoSite />

	<main class="folha">
		<a href="/dashboard" class="voltar">← Voltar para o Início</a>
		<div class="titulo">
			<h1>Meus formulários</h1>
			<p>A ficha de cada atividade em que você foi sorteado ou indicado. Suplente recebe a ficha quando é chamado para uma vaga.</p>
		</div>

		{#if estado === 'inicio' || estado === 'carregando'}
			<div class="carregando" aria-busy="true"><span class="fm-spinner"></span>Carregando seus formulários...</div>
		{:else if estado === 'erro'}
			<section class="cartao vazio" role="alert">
				<p class="vazio-titulo">Não foi possível carregar seus formulários.</p>
				<p class="vazio-texto">Verifique sua conexão com a internet e tente novamente.</p>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
			</section>
		{:else if comFormulario.length === 0 && outras.length === 0}
			<section class="cartao vazio">
				<img src={logoAsas} alt="" class="brasao" />
				<p class="vazio-titulo">Nenhum formulário por enquanto.</p>
				<p class="vazio-texto">Quando você for sorteado, indicado ou chamado da fila de suplentes, a ficha aparece aqui.</p>
				<a href="/dashboard" class="fm-btn fm-btn--contorno fm-btn--grande">Ver as atividades</a>
			</section>
		{:else}
			{#if pendentes.length > 0}
				<section class="grupo">
					<h2 class="grupo-titulo">Para responder <span>{pendentes.length}</span></h2>
					{#each pendentes as i (i.id)}
						<article class="cartao item pendente" class:urgente={urgente(i)}>
							<div class="item-textos">
								<div class="item-nome">
									<strong>{i.activity?.nome ?? 'Atividade'}</strong>
									<span class="etiqueta" class:etiqueta--aviso={correcao(i)}>{i.status}</span>
								</div>
								{#if i.activity?.categoria}<span class="item-sub">{i.activity.categoria}</span>{/if}
								<span class="item-prazo" class:item-prazo--urgente={urgente(i)}>{textoPrazo(i)}</span>
								{#if correcao(i) && i.motivoOrganizacao}
									<span class="item-obs">A organização pediu: “{i.motivoOrganizacao}”</span>
								{/if}
							</div>
							<a href="/dashboard/formulario/{i.id}" class="fm-btn fm-btn--ouro fm-btn--grande">
								{correcao(i) ? 'Corrigir respostas' : 'Responder'}
							</a>
						</article>
					{/each}
				</section>
			{/if}

			{#if enviados.length > 0}
				<section class="grupo">
					<h2 class="grupo-titulo">Já enviados <span>{enviados.length}</span></h2>
					{#each enviados as i (i.id)}
						{@const pagamento = textoPagamento(i)}
						<article class="cartao item enviado">
							<div class="item-textos">
								<div class="item-nome">
									<strong>{i.activity?.nome ?? 'Atividade'}</strong>
									<span class="etiqueta etiqueta--ok">✓ Enviado</span>
								</div>
								{#if i.activity?.categoria}<span class="item-sub">{i.activity.categoria}</span>{/if}
								{#if pagamento}<span class="item-prazo">{pagamento}</span>{/if}
							</div>
							<a href="/dashboard/formulario/{i.id}" class="fm-btn fm-btn--contorno fm-btn--grande">Ver respostas</a>
						</article>
					{/each}
				</section>
			{/if}

			{#if outras.length > 0}
				<section class="grupo">
					<h2 class="grupo-titulo">Outras inscrições <span>{outras.length}</span></h2>
					{#each outras as i (i.id)}
						<article class="cartao item inativo">
							<div class="item-textos">
								<div class="item-nome">
									<strong>{i.activity?.nome ?? 'Atividade'}</strong>
									<span class="etiqueta etiqueta--neutra">{i.naoSorteado ? 'Não sorteado' : i.status}</span>
								</div>
								<span class="item-prazo">{textoOutra(i)}</span>
							</div>
							{#if i.activity}
								<a href="/dashboard/atividades/{i.activity.id}" class="fm-btn fm-btn--grande">Ver a atividade</a>
							{/if}
						</article>
					{/each}
				</section>
			{/if}
		{/if}
	</main>
</div>

<style>
	/* As cores do painel (formularios.css), sem a classe .fm: ela tem margem
	   negativa feita para o painel e puxaria a página (e o cabeçalho) para cima. */
	.pagina {
		--ouro: #b69e55;
		--ouro-claro: #e2ce8a;
		--ouro-texto: #7a6528;
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
		--campo-borda: #a39363;
		--anel-foco: rgba(182, 158, 85, 0.45);
		--linha: #efe7d3;
		--cabecalho-tabela: #fbf6e6;
		--aviso-bg: #fbf0d6;
		--aviso-bd: #ebd39c;
		--aviso-tx: #6a480c;
		--aviso-ponto: #c08a1e;
		--cartao-aberto-anel: 0 0 0 3px rgba(182, 158, 85, 0.3);
		--icone-tipo-bg: #f1e8cf;
		--icone-tipo-tx: #6b5822;
		--selo-cond-bg: #e6ebf5;
		--selo-cond-bd: #c4cfe6;
		--selo-cond-tx: #21376a;
		--opcional-bg: #efebe1;
		--chave-on: #1b2f5e;
		--chave-off: #c9c3b2;
		--toast-bg: #0f1d3d;
		--vitral: linear-gradient(90deg, #1b2f5e, #1f6b6e 40%, #b69e55 70%, #9b2c2c);
		min-height: 100vh;
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
		background: var(--marfim);
	}

	.pagina button {
		font-family: inherit;
	}
	.folha {
		display: flex;
		flex-direction: column;
		gap: 22px;
		width: 100%;
		max-width: 1200px;
		box-sizing: border-box;
		margin: 0 auto;
		padding: 22px 48px 64px;
	}
	.voltar {
		align-self: flex-start;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
	}
	.titulo {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.titulo h1 {
		margin: 0;
		font: 600 48px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.titulo p {
		margin: 0;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.cartao {
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
		box-shadow: 0 14px 28px -24px rgba(15, 29, 61, 0.45);
	}
	.grupo {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.grupo-titulo {
		display: flex;
		align-items: center;
		gap: 10px;
		margin: 6px 0 0;
		font: 600 26px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.grupo-titulo span {
		font: 700 13px 'Source Sans 3', sans-serif;
		color: var(--ouro-texto);
		background: var(--cabecalho-tabela);
		border: 1px solid var(--borda);
		border-radius: 999px;
		padding: 2px 9px;
	}
	.item {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 20px;
		align-items: center;
		padding: 20px 24px;
		border-left: 5px solid var(--azul);
	}
	.item.pendente {
		border-left-color: var(--ouro);
	}
	.item.urgente {
		border-left-color: var(--vermelho);
	}
	.item.inativo {
		border-left-color: var(--borda);
	}
	.item-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.item-nome {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}
	.item-nome strong {
		font: 600 28px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.item-sub {
		font-size: 15px;
		color: var(--tinta-3);
	}
	.item-prazo {
		font-size: 17px;
		color: var(--tinta);
	}
	.item-prazo--urgente {
		color: var(--vermelho);
		font-weight: 600;
	}
	.item-obs {
		font-size: 15px;
		color: var(--tinta-3);
	}
	.etiqueta {
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		padding: 3px 9px;
		border-radius: 999px;
		white-space: nowrap;
		color: var(--selo-cond-tx);
		background: var(--selo-cond-bg);
		border: 1px solid var(--selo-cond-bd);
	}
	.etiqueta--aviso {
		color: var(--aviso-tx);
		background: var(--aviso-bg);
		border-color: var(--aviso-bd);
	}
	.etiqueta--ok {
		color: #fff;
		background: var(--azul);
		border-color: var(--azul);
	}
	.etiqueta--neutra {
		color: var(--tinta-3);
		background: var(--opcional-bg);
		border-color: var(--borda-suave);
	}
	.vazio {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 10px;
		padding: 56px 24px;
	}
	.vazio-titulo {
		margin: 0;
		font: 600 28px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.vazio-texto {
		margin: 0;
		max-width: 520px;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.brasao {
		width: 110px;
		opacity: 0.55;
		filter: sepia(1) saturate(0.6);
	}
	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		font-size: 17px;
		color: var(--tinta-3);
	}
</style>

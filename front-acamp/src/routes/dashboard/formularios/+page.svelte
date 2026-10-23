<script lang="ts">
	/**
	 * "Meus Formulários" — lista as inscrições que têm formulário para responder.
	 * Entra pelo menu lateral do painel; daqui o campista abre a tela de resposta.
	 */
	import { onMount } from 'svelte';
	import { auth } from '$lib/stores/auth.svelte';
	import { getMinhasInscricoes, type MinhaInscricao } from '$lib/services/inscricaoService';

	let loading = $state(true);
	let error = $state('');
	let inscricoes: MinhaInscricao[] = $state([]);

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			window.location.href = '/login';
		}
	});

	onMount(async () => {
		const res = await getMinhasInscricoes();
		if (res.error) error = res.error;
		else inscricoes = res.inscricoes;
		loading = false;
	});

	// Só entram as inscrições cujo acampamento já tem formulário montado e em que
	// a pessoa está numa situação que dá direito a responder.
	const STATUS_COM_FORMULARIO = ['Sorteado', 'Indicado', 'Suplente', 'Formulário Enviado'];

	const comFormulario = $derived(
		inscricoes.filter((i) => i.temFormulario && STATUS_COM_FORMULARIO.includes(i.status))
	);

	const pendentes = $derived(comFormulario.filter((i) => !i.formularioEnviado));
	const enviados = $derived(comFormulario.filter((i) => i.formularioEnviado));

	// Inscrições que ainda não chegaram a ter formulário, mostradas só como contexto.
	const aguardando = $derived(
		inscricoes.filter(
			(i) => !comFormulario.includes(i) && !['Desistência'].includes(i.status)
		)
	);

	function formatarPrazo(iso?: string | null): string {
		if (!iso) return '';
		const [data, hora] = iso.split('T');
		const [y, m, d] = data.split('-');
		return `${d}/${m}/${y} às ${hora}`;
	}

	function diasRestantes(iso?: string | null): number | null {
		if (!iso) return null;
		return Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000);
	}

	function textoPrazo(i: MinhaInscricao): string {
		const dias = diasRestantes(i.prazoFormulario);
		if (dias === null) return 'Sem prazo definido';
		if (dias < 0) return `Prazo encerrado em ${formatarPrazo(i.prazoFormulario)}`;
		if (dias === 0) return `Último dia — até ${formatarPrazo(i.prazoFormulario)}`;
		if (dias === 1) return `Falta 1 dia — até ${formatarPrazo(i.prazoFormulario)}`;
		return `Faltam ${dias} dias — até ${formatarPrazo(i.prazoFormulario)}`;
	}

	function urgente(i: MinhaInscricao): boolean {
		const dias = diasRestantes(i.prazoFormulario);
		return dias !== null && dias <= 2;
	}
</script>

<svelte:head>
	<title>Meus Formulários | Acampamento São Miguel</title>
</svelte:head>

<div class="page">
	<a href="/dashboard" class="back-link">&larr; Voltar ao painel</a>
	<h2>Meus Formulários</h2>
	<p class="subtitle">
		A ficha de inscrição de cada acampamento em que você foi selecionado ou está como suplente.
	</p>

	{#if loading}
		<div class="status-state">
			<div class="spinner"></div>
			<p>Carregando...</p>
		</div>
	{:else if error}
		<div class="status-state error">{error}</div>
	{:else if comFormulario.length === 0 && aguardando.length === 0}
		<div class="vazio">
			<div class="vazio-icone">📝</div>
			<h3>Nenhum formulário por enquanto</h3>
			<p>Quando você for sorteado, indicado ou entrar na lista de suplentes, a ficha aparece aqui.</p>
			<a href="/dashboard" class="btn-secundario">Ver acampamentos abertos</a>
		</div>
	{:else}
		{#if pendentes.length > 0}
			<section class="grupo">
				<h3 class="grupo-titulo">Para responder ({pendentes.length})</h3>
				{#each pendentes as i}
					<a class="cartao" class:cartao--urgente={urgente(i)} href="/dashboard/formulario/{i.id}">
						<div class="cartao-topo">
							<span class="cartao-nome">{i.activity?.nome ?? 'Acampamento'}</span>
							<span class="etiqueta etiqueta--{i.status === 'Suplente' ? 'suplente' : 'vaga'}">
								{i.status}
							</span>
						</div>
						<p class="cartao-prazo" class:cartao-prazo--urgente={urgente(i)}>
							{textoPrazo(i)}
						</p>
						{#if i.status === 'Suplente'}
							<p class="cartao-obs">
								Suplente também responde: só é chamado para uma vaga quem já entregou a ficha.
							</p>
						{/if}
						<span class="cartao-acao">Responder formulário →</span>
					</a>
				{/each}
			</section>
		{/if}

		{#if enviados.length > 0}
			<section class="grupo">
				<h3 class="grupo-titulo">Já enviados ({enviados.length})</h3>
				{#each enviados as i}
					<a class="cartao cartao--pronto" href="/dashboard/formulario/{i.id}">
						<div class="cartao-topo">
							<span class="cartao-nome">{i.activity?.nome ?? 'Acampamento'}</span>
							<span class="etiqueta etiqueta--ok">✓ Enviado</span>
						</div>
						<p class="cartao-prazo">
							{#if i.isPago}
								Inscrição paga.
							{:else if i.status === 'Suplente'}
								Suplentes não pagam a taxa.
							{:else}
								Falta o pagamento da inscrição.
							{/if}
						</p>
						<span class="cartao-acao">Ver ou corrigir respostas →</span>
					</a>
				{/each}
			</section>
		{/if}

		{#if aguardando.length > 0}
			<section class="grupo">
				<h3 class="grupo-titulo">Outras inscrições</h3>
				{#each aguardando as i}
					<div class="cartao cartao--inativo">
						<div class="cartao-topo">
							<span class="cartao-nome">{i.activity?.nome ?? 'Inscrição'}</span>
							<span class="etiqueta etiqueta--neutra">{i.status}</span>
						</div>
						<p class="cartao-prazo">
							{#if i.perdeuPrazo}
								O prazo do formulário terminou.
							{:else if !i.temFormulario}
								A organização ainda não montou o formulário deste acampamento.
							{:else}
								O formulário abre quando você for selecionado.
							{/if}
						</p>
					</div>
				{/each}
			</section>
		{/if}
	{/if}
</div>

<style>
	.page {
		max-width: 820px;
		margin: 0 auto;
		padding: 2rem 1.5rem 4rem;
	}

	.back-link {
		display: inline-block;
		margin-bottom: 1rem;
		color: #1c8b6b;
		text-decoration: none;
		font-weight: 600;
		font-size: 0.9rem;
	}

	h2 {
		margin: 0 0 0.3rem 0;
		color: #1a1a1a;
	}

	.subtitle {
		color: #777;
		margin: 0 0 2rem 0;
	}

	.status-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 1rem;
		padding: 3rem 1rem;
		color: #666;
		text-align: center;
	}

	.status-state.error {
		color: #b91c1c;
	}

	.spinner {
		width: 32px;
		height: 32px;
		border: 4px solid rgba(111, 222, 194, 0.3);
		border-top-color: #6fdec2;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}

	.vazio {
		background: #fff;
		border: 1px solid #eaeaea;
		border-radius: 14px;
		padding: 3rem 2rem;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 0.6rem;
		text-align: center;
	}

	.vazio-icone {
		font-size: 2.6rem;
	}

	.vazio h3 {
		margin: 0;
		color: #222;
	}

	.vazio p {
		margin: 0;
		color: #666;
		max-width: 44ch;
		line-height: 1.5;
	}

	.btn-secundario {
		margin-top: 0.8rem;
		background: #6fdec2;
		color: #115843;
		padding: 0.65rem 1.6rem;
		border-radius: 999px;
		font-weight: 700;
		font-size: 0.9rem;
		text-decoration: none;
	}

	.grupo {
		margin-bottom: 2.2rem;
	}

	.grupo-titulo {
		font-size: 0.78rem;
		font-weight: 800;
		text-transform: uppercase;
		letter-spacing: 0.06em;
		color: #9a9a9a;
		margin: 0 0 0.8rem 0;
	}

	.cartao {
		display: block;
		background: #fff;
		border: 1px solid #eaeaea;
		border-left: 5px solid #6fdec2;
		border-radius: 12px;
		padding: 1.1rem 1.3rem;
		margin-bottom: 0.9rem;
		text-decoration: none;
		color: inherit;
		transition:
			box-shadow 0.15s ease,
			transform 0.15s ease;
	}

	a.cartao:hover {
		box-shadow: 0 6px 18px rgba(17, 88, 67, 0.1);
		transform: translateY(-1px);
	}

	.cartao--urgente {
		border-left-color: #f59e0b;
	}

	.cartao--pronto {
		border-left-color: #34d399;
	}

	.cartao--inativo {
		border-left-color: #e5e7eb;
		background: #fafafa;
	}

	.cartao-topo {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.8rem;
		flex-wrap: wrap;
	}

	.cartao-nome {
		font-weight: 700;
		color: #1a1a1a;
		font-size: 1.02rem;
	}

	.cartao-prazo {
		margin: 0.35rem 0 0;
		color: #666;
		font-size: 0.88rem;
	}

	.cartao-prazo--urgente {
		color: #b45309;
		font-weight: 600;
	}

	.cartao-obs {
		margin: 0.3rem 0 0;
		color: #888;
		font-size: 0.82rem;
		line-height: 1.45;
	}

	.cartao-acao {
		display: inline-block;
		margin-top: 0.7rem;
		color: #1c8b6b;
		font-weight: 700;
		font-size: 0.88rem;
	}

	.etiqueta {
		font-size: 0.7rem;
		font-weight: 800;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		padding: 0.2rem 0.6rem;
		border-radius: 999px;
		white-space: nowrap;
	}

	.etiqueta--vaga {
		background: #ecfdf5;
		color: #065f46;
		border: 1px solid #a7f3d0;
	}

	.etiqueta--suplente {
		background: #eff6ff;
		color: #1e40af;
		border: 1px solid #bfdbfe;
	}

	.etiqueta--ok {
		background: #6fdec2;
		color: #115843;
	}

	.etiqueta--neutra {
		background: #f3f4f6;
		color: #6b7280;
		border: 1px solid #e5e7eb;
	}

	@media (max-width: 520px) {
		.page {
			padding: 1.5rem 1rem 3rem;
		}
	}
</style>

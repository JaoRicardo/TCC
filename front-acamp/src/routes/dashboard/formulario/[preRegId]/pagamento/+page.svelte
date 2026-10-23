<script lang="ts">
	/**
	 * Tela de retorno do Mercado Pago (back_urls). O Mercado Pago manda o campista
	 * para cá depois do checkout, com ?resultado=sucesso|pendente|falha.
	 *
	 * Esse retorno é só navegação: ele NÃO é prova de pagamento e pode ser aberto
	 * digitando a URL. Quem confirma é o webhook no backend, então aqui a tela
	 * consulta a situação real da inscrição algumas vezes até o webhook chegar.
	 */
	import { onMount, onDestroy } from 'svelte';
	import { page } from '$app/stores';
	import { auth } from '$lib/stores/auth.svelte';
	import { getSituacaoPagamento, type SituacaoPagamento } from '$lib/services/formularioService';

	const preRegId = $page.params.preRegId as string;
	const resultado = $derived($page.url.searchParams.get('resultado') ?? 'pendente');

	let situacao: SituacaoPagamento | null = $state(null);
	let carregando = $state(true);
	let erro = $state('');
	let tentativas = $state(0);
	let timer: ReturnType<typeof setTimeout> | null = null;

	const MAX_TENTATIVAS = 10;

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			window.location.href = '/login';
		}
	});

	async function consultar() {
		const res = await getSituacaoPagamento(preRegId);
		carregando = false;

		if (res.error) {
			erro = res.error;
			return;
		}

		situacao = res.data ?? null;
		tentativas += 1;

		// Enquanto o webhook não confirmar, tenta de novo com intervalo crescente.
		if (!situacao?.pago && resultado !== 'falha' && tentativas < MAX_TENTATIVAS) {
			timer = setTimeout(consultar, 3000);
		}
	}

	onMount(consultar);
	onDestroy(() => {
		if (timer) clearTimeout(timer);
	});

	function formatarValor(v: number): string {
		return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
	}
</script>

<svelte:head>
	<title>Pagamento da inscrição</title>
</svelte:head>

<div class="page">
	<a href="/dashboard" class="back-link">&larr; Voltar ao painel</a>
	<h2>Pagamento da inscrição</h2>

	{#if carregando}
		<div class="card center">
			<div class="spinner"></div>
			<p>Consultando seu pagamento...</p>
		</div>
	{:else if erro}
		<div class="card center">
			<div class="icone">⚠️</div>
			<p>{erro}</p>
			<a href={`/dashboard/formulario/${preRegId}`} class="btn-primary">Voltar ao formulário</a>
		</div>
	{:else if situacao?.pago}
		<div class="card center sucesso">
			<div class="icone">✅</div>
			<h3>Pagamento confirmado!</h3>
			<p>
				Recebemos {formatarValor(situacao.valor)}{situacao.metodo ? ` via ${situacao.metodo}` : ''}.
				Sua inscrição está completa.
			</p>
			<a href="/dashboard" class="btn-primary">Ir para o painel</a>
		</div>
	{:else if resultado === 'falha'}
		<div class="card center">
			<div class="icone">❌</div>
			<h3>O pagamento não foi concluído</h3>
			<p>Nada foi cobrado. Você pode tentar de novo pelo formulário da sua inscrição.</p>
			<a href={`/dashboard/formulario/${preRegId}`} class="btn-primary">Tentar novamente</a>
		</div>
	{:else}
		<div class="card center">
			<div class="icone">⏳</div>
			<h3>Pagamento em processamento</h3>
			<p>
				{#if tentativas >= MAX_TENTATIVAS}
					A confirmação ainda não chegou. Pagamentos por Pix e boleto podem levar alguns minutos
					(boleto, até alguns dias). Você pode fechar esta página — o painel é atualizado sozinho
					quando o Mercado Pago confirmar.
				{:else}
					Estamos aguardando a confirmação do Mercado Pago. Isso costuma levar poucos segundos.
				{/if}
			</p>
			<a href="/dashboard" class="btn-primary">Ir para o painel</a>
		</div>
	{/if}
</div>

<style>
	.page {
		max-width: 600px;
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
		margin: 0 0 1.5rem 0;
		color: #1a1a1a;
	}

	.card {
		background: #fff;
		border: 1px solid #eaeaea;
		border-radius: 12px;
		padding: 2.5rem 2rem;
	}

	.card.center {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 0.7rem;
		text-align: center;
	}

	.card.sucesso {
		border-color: #a7f3d0;
		background: #f8fffc;
	}

	.card h3 {
		margin: 0;
		color: #222;
	}

	.card p {
		margin: 0;
		color: #666;
		font-size: 0.94rem;
		max-width: 46ch;
		line-height: 1.5;
	}

	.icone {
		font-size: 2.4rem;
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

	.btn-primary {
		margin-top: 0.8rem;
		display: inline-block;
		background: #6fdec2;
		color: #115843;
		border: none;
		padding: 0.7rem 1.8rem;
		border-radius: 999px;
		font-weight: 700;
		font-size: 0.92rem;
		text-decoration: none;
	}
</style>

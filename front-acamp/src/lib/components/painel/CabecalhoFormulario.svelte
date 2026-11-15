<script lang="ts">
	/**
	 * Topo do formulário no painel, igual no editor e na aba Respostas: título,
	 * "vale para todos os acampamentos…" e as abas Perguntas | Respostas. Cada
	 * aba é uma tela (endereço próprio); a aba Respostas só aparece para quem
	 * tem a permissão.
	 */
	import type { Snippet } from 'svelte';
	import { enderecoDasRespostas, enderecoDoEditor, type TipoInscricao } from '$lib/formularios';

	let {
		categoryId,
		categoria,
		tipo,
		titulo,
		aba,
		perguntas,
		respostas = null,
		lado
	}: {
		categoryId: number;
		categoria: string;
		tipo: TipoInscricao;
		titulo: string;
		aba: 'perguntas' | 'respostas';
		perguntas: number;
		/** Quantas pessoas enviaram; null esconde a aba (sem permissão). */
		respostas?: number | null;
		/** À direita do título (ex.: "Todas as alterações salvas"). */
		lado?: Snippet;
	} = $props();
</script>

<div class="topo">
	<div class="topo-linha">
		<div class="topo-textos">
			<span class="fm-sobretitulo">Formulário de acampamento</span>
			<h1 class="fm-h1">{titulo}</h1>
			<p class="fm-sub">Vale para todos os acampamentos {categoria}, ano após ano.</p>
		</div>
		{#if lado}{@render lado()}{/if}
	</div>
	<nav class="abas" aria-label="Partes do formulário">
		<a
			class="aba"
			class:aba--ativa={aba === 'perguntas'}
			href={enderecoDoEditor(categoryId, tipo)}
			aria-current={aba === 'perguntas' ? 'page' : undefined}
		>
			Perguntas<span class="contador">{perguntas}</span>
		</a>
		{#if respostas !== null}
			<a
				class="aba"
				class:aba--ativa={aba === 'respostas'}
				href={enderecoDasRespostas(categoryId, tipo)}
				aria-current={aba === 'respostas' ? 'page' : undefined}
			>
				Respostas<span class="contador">{respostas}</span>
			</a>
		{/if}
	</nav>
</div>

<style>
	.topo {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	.topo-linha {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 20px;
		flex-wrap: wrap;
	}

	.topo-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.abas {
		display: flex;
		gap: 6px;
		border-bottom: 1px solid var(--borda);
	}

	.aba {
		display: flex;
		align-items: center;
		gap: 8px;
		height: 48px;
		padding: 0 18px;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta-3);
		text-decoration: none;
		border-bottom: 3px solid transparent;
		margin-bottom: -1px;
		border-radius: 8px 8px 0 0;
	}

	.aba:hover:not(.aba--ativa) {
		color: var(--azul);
		background: var(--cabecalho-tabela);
	}

	.aba--ativa {
		font-weight: 700;
		color: var(--azul);
		border-bottom-color: var(--ouro);
	}

	.contador {
		font-size: 14px;
		font-weight: 700;
		color: var(--icone-tipo-tx);
		background: var(--icone-tipo-bg);
		border-radius: 999px;
		padding: 1px 9px;
	}
</style>

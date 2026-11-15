<script lang="ts">
	/**
	 * Lista dos formulários de acampamento: uma linha por categoria, com o
	 * formulário de Campista e o de Servo. Cada célula diz quantas perguntas o
	 * formulário tem e se ele está em uso (acampamento com o prazo aberto) —
	 * inclusive o caso perigoso de estar em uso e vazio.
	 */
	import { onMount } from 'svelte';
	import '$lib/styles/formularios.css';
	import { getResumoFormularios, type ResumoFormulario } from '$lib/services/perguntaService';
	import { diaEMes, enderecoDoEditor, nomeCurto, tituloDoFormulario } from '$lib/formularios';

	let formularios: ResumoFormulario[] = $state([]);
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	/** Uma linha por categoria, na ordem que o servidor manda (Mirim → Discipulados). */
	let linhas = $derived.by(() => {
		const porCategoria = new Map<number, { categoria: string; campista?: ResumoFormulario; servo?: ResumoFormulario }>();
		for (const f of formularios) {
			const linha = porCategoria.get(f.category_id) ?? { categoria: f.categoria };
			if (f.subscription_type === 'Campista') linha.campista = f;
			else linha.servo = f;
			porCategoria.set(f.category_id, linha);
		}
		return [...porCategoria.values()];
	});

	async function carregar() {
		estado = 'carregando';
		try {
			const res = await getResumoFormularios();
			if (res.error || !res.data) {
				estado = 'erro';
				return;
			}
			formularios = res.data;
			estado = 'pronto';
		} catch {
			estado = 'erro';
		}
	}

	onMount(carregar);
</script>

<svelte:head>
	<title>Formulários | Painel da Comunidade</title>
</svelte:head>

{#snippet celula(f: ResumoFormulario | undefined)}
	{#if f}
		{@const titulo = tituloDoFormulario(f.categoria, f.subscription_type)}
		<div class="celula" class:celula--vazia={f.perguntas === 0}>
			<div class="celula-info">
				{#if f.perguntas > 0}
					<span class="celula-numeros">
						<strong>{f.perguntas} {f.perguntas === 1 ? 'pergunta' : 'perguntas'}</strong>
						· {f.secoes.length} {f.secoes.length === 1 ? 'seção' : 'seções'}
					</span>
					{#if f.uso}
						<span class="celula-uso">
							<span class="selo-uso">Em uso · {nomeCurto(f.uso.nome)}</span>
							Prazo {diaEMes(f.uso.prazo)} · {f.uso.enviados} de {f.uso.total} enviaram
						</span>
					{:else}
						<span class="celula-nota">Nenhum acampamento aberto agora.</span>
					{/if}
				{:else}
					<span class="celula-vazio">Vazio</span>
					{#if f.uso}
						<span class="faixa-perigo">{nomeCurto(f.uso.nome)} está aberto: ninguém perde a vaga pelo prazo</span>
					{:else}
						<span class="celula-nota">Sem perguntas, ninguém perde a vaga pelo prazo.</span>
					{/if}
				{/if}
			</div>
			<a
				class="fm-btn fm-btn--contorno"
				href={enderecoDoEditor(f.category_id, f.subscription_type)}
				aria-label="{f.perguntas > 0 ? 'Editar' : 'Montar'} {titulo}"
			>
				{f.perguntas > 0 ? 'Editar' : 'Montar'}
			</a>
		</div>
	{/if}
{/snippet}

<div class="fm">
	<div class="cabecalho">
		<h1 class="fm-h1">Formulários</h1>
		<p class="fm-sub">
			Perguntas que sorteados, indicados e suplentes respondem depois de confirmados. Cada formulário vale para
			todos os acampamentos da categoria, ano após ano.
		</p>
	</div>

	{#if estado === 'erro'}
		<section class="fm-cartao fm-centro" role="alert">
			<span class="fm-erro-icone">!</span>
			<p class="fm-centro-titulo">Não foi possível carregar os formulários.</p>
			<p class="fm-sub">Verifique sua conexão com a internet e tente novamente.</p>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
		</section>
	{:else}
		<section class="fm-cartao tabela-cartao">
			<div class="tabela-titulo">
				<h2 class="fm-h2">Acampamentos</h2>
				<p>Um formulário por categoria, separado para campistas e servos.</p>
			</div>

			{#if estado === 'carregando'}
				<div class="carregando" role="status"><span class="fm-spinner"></span>Carregando formulários...</div>
				{#each Array(6) as _}
					<div class="linha" aria-hidden="true">
						<span class="fm-osso" style="height:22px;width:60%;align-self:center"></span>
						<span class="fm-osso fm-osso--claro" style="height:74px;border-radius:12px"></span>
						<span class="fm-osso fm-osso--claro" style="height:74px;border-radius:12px"></span>
					</div>
				{/each}
			{:else}
				<table>
					<thead>
						<tr class="linha linha--cabecalho">
							<th scope="col">Categoria</th>
							<th scope="col">Campista</th>
							<th scope="col">Servo</th>
						</tr>
					</thead>
					<tbody>
						{#each linhas as linha}
							<tr class="linha">
								<th scope="row" class="categoria">{linha.categoria}</th>
								<td>{@render celula(linha.campista)}</td>
								<td>{@render celula(linha.servo)}</td>
							</tr>
						{/each}
					</tbody>
				</table>
			{/if}
		</section>
	{/if}
</div>

<style>
	.cabecalho {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.cabecalho .fm-sub {
		max-width: 780px;
	}

	.tabela-cartao {
		overflow: hidden;
	}

	.tabela-titulo {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 20px 24px 16px;
	}

	.tabela-titulo p {
		margin: 0;
		font-size: 16px;
		color: var(--tinta-3);
	}

	table {
		width: 100%;
		border-collapse: collapse;
	}

	.linha {
		display: grid;
		grid-template-columns: 180px minmax(0, 1fr) minmax(0, 1fr);
		gap: 18px;
		align-items: stretch;
		padding: 14px 24px;
		border-bottom: 1px solid var(--linha);
	}

	.linha--cabecalho {
		padding: 12px 24px;
		background: var(--cabecalho-tabela);
		border-top: 1px solid var(--borda-suave);
		border-bottom: 1px solid var(--borda-suave);
	}

	.linha--cabecalho th {
		text-align: left;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.linha td,
	.linha th {
		padding: 0;
	}

	.categoria {
		align-self: center;
		text-align: left;
		font: 600 26px/1.1 'Cormorant Garamond', serif;
		color: var(--tinta);
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 24px;
		font-size: 17px;
		color: var(--tinta-3);
		border-top: 1px solid var(--borda-suave);
		border-bottom: 1px solid var(--linha);
	}

	.celula {
		height: 100%;
		box-sizing: border-box;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 14px 16px;
		border-radius: 12px;
		background: #ffffff;
		border: 1px solid var(--borda-suave);
	}

	.celula--vazia {
		background: transparent;
		border: 1px dashed #cdb878;
	}

	.celula-info {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}

	.celula-numeros {
		font-size: 16px;
		color: var(--tinta-2);
	}

	.celula-numeros strong {
		font-size: 19px;
		color: var(--tinta);
	}

	.celula-vazio {
		font-size: 18px;
		font-weight: 700;
		color: var(--tinta-3);
	}

	.celula-nota {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.celula-uso {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		font-size: 15px;
		color: var(--selo-cond-tx);
	}

	.selo-uso {
		font-weight: 700;
		background: var(--selo-cond-bg);
		border: 1px solid var(--selo-cond-bd);
		border-radius: 999px;
		padding: 2px 10px;
	}

	.faixa-perigo {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		font-weight: 600;
		color: var(--aviso-tx);
		background: var(--aviso-bg);
		border: 1px solid var(--aviso-bd);
		border-radius: 8px;
		padding: 5px 10px;
	}

	.faixa-perigo::before {
		content: '';
		flex: 0 0 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--aviso-ponto);
	}
</style>

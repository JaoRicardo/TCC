<script lang="ts">
	/**
	 * Janela "Indicar vaga": busca a pessoa (aguardando sorteio, não sorteada ou
	 * na fila de suplentes), escolhe o motivo e avisa ANTES de confirmar quando
	 * o cônjuge vai junto ou quando a pessoa sai da fila.
	 */
	import { apiFetch } from '$lib/api';
	import Janela from '$lib/components/painel/Janela.svelte';
	import { casaBusca, situacaoDe, type Inscricao } from '$lib/inscritos';

	let {
		atividadeId,
		inscricoes,
		metodos,
		feito,
		inicial,
		onfechar,
		onfeito
	}: {
		atividadeId: number;
		inscricoes: Inscricao[];
		metodos: { id: number; method: string; description: string }[];
		feito: { Campista: boolean; Servo: boolean };
		inicial: Inscricao | null;
		onfechar: () => void;
		/** Indicou: a tela recarrega e mostra o aviso. */
		onfeito: (mensagem: string) => void;
	} = $props();

	/** Descrições do handoff; sem elas, a do cadastro. */
	const DESCRICAO: Record<string, string> = {
		Base: 'Indicado pelo coordenador de setor',
		Forania: 'Indicado pelo pároco',
		Conselho: 'Indicado pelo conselho'
	};

	let busca = $state('');
	// Só o valor de quando a janela abre (depois, a pessoa escolhe outra).
	// svelte-ignore state_referenced_locally
	let escolhida = $state<Inscricao | null>(inicial);
	let metodoId = $state<number | null>(null);
	let tentou = $state(false);
	let enviando = $state(false);
	let erro = $state('');

	const candidatas = $derived(
		inscricoes.filter((i) => {
			const sit = situacaoDe(i, false, feito);
			return sit === 'insc' || sit === 'nao' || sit === 'sup';
		})
	);
	const visiveis = $derived(candidatas.filter((i) => casaBusca(i.user.name, i.user.cpf, busca)));

	function info(i: Inscricao): string {
		const sit = situacaoDe(i, false, feito);
		const onde = sit === 'sup' ? `Suplente (${i.substitute_position}º)` : sit === 'nao' ? 'Não sorteado' : 'Aguardando sorteio';
		return `${i.subscription_type} · ${onde} · CPF ${i.user.cpf}`;
	}

	const conjuge = $derived.by(() => {
		if (!escolhida?.conjuge) return null;
		return inscricoes.find((i) => i.id === escolhida!.conjuge!.id) ?? null;
	});
	const ehSuplente = $derived(!!escolhida && situacaoDe(escolhida, false, feito) === 'sup');
	const metodo = $derived(metodos.find((m) => m.id === metodoId) ?? null);

	async function confirmar() {
		tentou = true;
		if (!escolhida || !metodoId || enviando) return;
		enviando = true;
		erro = '';
		try {
			const res = await apiFetch(`/activities/${atividadeId}/indicate`, {
				method: 'POST',
				body: JSON.stringify({ pre_registration_id: escolhida.id, selection_method_id: metodoId })
			});
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				erro = data.error ? `Não foi possível indicar: ${data.error}` : 'Não foi possível indicar. Nada mudou: tente de novo.';
				return;
			}
			const nomes = data.conjuge_indicado && conjuge ? `${escolhida.user.name} e ${conjuge.user.name} foram indicados` : `${escolhida.user.name} foi indicado(a)`;
			onfeito(`${nomes} (${metodo?.method}).`);
		} catch {
			erro = 'Não foi possível indicar. Nada mudou: tente de novo.';
		} finally {
			enviando = false;
		}
	}
</script>

<Janela idTitulo="t-indicar" largura={640} faixa="linear-gradient(90deg,#B69E55,#E2CE8A)" travada={enviando} {onfechar}>
	<div>
		<h2 id="t-indicar" class="fm-janela-titulo">Indicar vaga</h2>
		<p class="ins-sub">
			A pessoa indicada tem vaga garantida fora do sorteio. Dá para indicar quem está aguardando o sorteio ou na fila de suplentes.
		</p>
	</div>

	<div class="campo">
		<label for="busca-indicar" class="rotulo">Quem vai ser indicado(a)</label>
		<div class="busca">
			<svg class="lupa" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
			<input id="busca-indicar" type="search" class="fm-campo" placeholder="Buscar por nome ou CPF" bind:value={busca} autocomplete="off" />
		</div>
		<div class="opcoes" role="radiogroup" aria-label="Pessoa">
			{#each visiveis as i (i.id)}
				<button
					type="button"
					role="radio"
					aria-checked={escolhida?.id === i.id}
					class="opcao"
					class:sel={escolhida?.id === i.id}
					onclick={() => (escolhida = i)}
				>
					<span class="anel" aria-hidden="true"></span>
					<span class="opcao-txt"><strong>{i.user.name}</strong><span>{info(i)}</span></span>
				</button>
			{:else}
				<span class="ins-ajuda">{candidatas.length ? 'Ninguém com essa busca.' : 'Ninguém aguardando o sorteio ou na fila.'}</span>
			{/each}
		</div>
		{#if tentou && !escolhida}<p class="ins-erro">Escolha quem vai ser indicado(a).</p>{/if}
	</div>

	<fieldset class="campo">
		<legend class="rotulo">Motivo</legend>
		<div class="motivos" role="radiogroup" aria-label="Motivo">
			{#each metodos as m (m.id)}
				<button type="button" role="radio" aria-checked={metodoId === m.id} class="motivo" class:sel={metodoId === m.id} onclick={() => (metodoId = m.id)}>
					<span class="anel" aria-hidden="true"></span>
					<span class="opcao-txt"><strong>{m.method}</strong><span>{DESCRICAO[m.method] ?? m.description}</span></span>
				</button>
			{/each}
		</div>
		{#if tentou && !metodoId}<p class="ins-erro">Escolha o motivo da indicação.</p>{/if}
	</fieldset>

	{#if escolhida && ehSuplente}
		<p class="ins-aviso amb">
			<span
				>{conjuge ? `${escolhida.user.name.split(' ')[0]} e ${conjuge.user.name.split(' ')[0]} saem` : `${escolhida.user.name} sai`} da fila de
				suplentes ({escolhida.substitute_position}º).</span
			>
		</p>
	{/if}
	{#if escolhida && conjuge}
		<p class="ins-aviso azul">
			<span><strong>{conjuge.user.name}</strong>, cônjuge de {escolhida.user.name}, também será indicado(a), com o mesmo motivo.</span>
		</p>
	{/if}
	{#if erro}<p class="ins-aviso verm" role="alert"><span>{erro}</span></p>{/if}

	<div class="fm-janela-acoes">
		<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={onfechar} disabled={enviando}>Cancelar</button>
		<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={confirmar} disabled={enviando}>
			{enviando ? 'Indicando...' : 'Confirmar indicação'}
		</button>
	</div>
</Janela>

<style>
	.campo {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		border: none;
		min-width: 0;
	}
	.rotulo {
		padding: 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}
	legend.rotulo {
		margin-bottom: 8px;
	}
	.busca {
		position: relative;
	}
	.busca .fm-campo {
		padding-left: 44px;
	}
	.lupa {
		position: absolute;
		left: 15px;
		top: 50%;
		transform: translateY(-50%);
		width: 18px;
		height: 18px;
		fill: none;
		stroke: var(--tinta-3);
		stroke-width: 2;
		stroke-linecap: round;
	}
	.opcoes {
		display: flex;
		flex-direction: column;
		gap: 6px;
		max-height: 260px;
		overflow: auto;
	}
	.opcao,
	.motivo {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 10px 14px;
		text-align: left;
		background: #ffffff;
		border: 1.5px solid var(--borda);
		border-radius: 10px;
		cursor: pointer;
		font-family: 'Source Sans 3', sans-serif;
	}
	.motivo {
		align-items: flex-start;
		padding: 12px 14px;
		border-radius: 12px;
	}
	.opcao.sel,
	.motivo.sel {
		background: #eef1f7;
		border-color: var(--azul);
	}
	.anel {
		flex: 0 0 20px;
		height: 20px;
		box-sizing: border-box;
		border: 2px solid #a39363;
		border-radius: 50%;
		background: #ffffff;
	}
	.motivo .anel {
		flex-basis: 18px;
		height: 18px;
		margin-top: 2px;
	}
	.sel .anel {
		border: 6px solid var(--azul);
	}
	.opcao-txt {
		display: flex;
		flex-direction: column;
		gap: 1px;
		font-size: 14px;
		line-height: 1.35;
		color: var(--tinta-3);
	}
	.opcao-txt strong {
		font-size: 16px;
		color: var(--tinta);
	}
	.motivos {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 10px;
	}
</style>

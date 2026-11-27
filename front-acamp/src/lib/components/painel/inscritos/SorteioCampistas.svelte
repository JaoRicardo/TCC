<script lang="ts">
	/**
	 * Aba "Sorteio de campistas": vagas do cadastro (só leitura), vagas abertas
	 * com "Chamar próximo", indicados, quem concorre (antes) ou o resultado
	 * (depois), as saídas e "Refazer ou apagar o resultado" com o bloqueio.
	 */
	import { pode } from '$lib/permissoes';
	import { dma } from '$lib/pagamentos';
	import {
		NOME_DO_GRUPO,
		SAIU,
		SIT,
		motivoDe,
		plural,
		situacaoDe,
		textoDasVagas,
		unidades,
		type Grupo,
		type Inscricao,
		type PessoaDoSorteio,
		type SorteioCampistas,
		type UnidadeChamada
	} from '$lib/inscritos';

	let {
		s,
		inscricoes,
		atividadeId,
		onchamar,
		onindicar,
		ondesfazer,
		onrealizar,
		onrefazer,
		onapagar
	}: {
		s: SorteioCampistas;
		/** Só as inscrições de campista. */
		inscricoes: Inscricao[];
		atividadeId: number;
		onchamar: (u: UnidadeChamada) => void;
		onindicar: () => void;
		ondesfazer: (i: Inscricao) => void;
		onrealizar: () => void;
		onrefazer: () => void;
		onapagar: () => void;
	} = $props();

	const porUsuario = $derived(new Map(inscricoes.map((i) => [i.user.id, i])));
	const conjugeDe = (userId: number) => porUsuario.get(userId)?.conjuge?.user_id ?? null;
	const ehHomem = (userId: number) => porUsuario.get(userId)?.user.sex === 'M';
	const juntar = <T extends { user_id: number; nome: string }>(lista: T[]) => unidades(lista, s.casais, conjugeDe, ehHomem);

	const GRUPOS = $derived<Grupo[]>(s.casais ? ['Casado'] : ['Homem', 'Mulher']);
	const vagasTxt = $derived(textoDasVagas(s.vagas, s.casais));

	const dataBr = (x: string | null) => (x ? dma(x) : 'a definir');

	/** Formulário e pagamento de uma unidade (casal: os dois). */
	function tagDe(pessoas: { user_id: number }[]): { texto: string; tom: string } {
		const insc = pessoas.map((p) => porUsuario.get(p.user_id)).filter(Boolean) as Inscricao[];
		const pago = insc.length > 0 && insc.every((i) => i.pagamento?.situacao === 'pago');
		const form = insc.length > 0 && insc.every((i) => i.formularioEnviado);
		if (form && pago) return { texto: 'Formulário enviado · pago', tom: 'ok' };
		if (form) return { texto: 'Formulário enviado', tom: 'ok' };
		if (pago) return { texto: 'Pago · sem formulário', tom: 'esp' };
		return { texto: 'Sem formulário', tom: 'esp' };
	}
	function extraDe(pessoas: { user_id: number }[]): string {
		const i = porUsuario.get(pessoas[0].user_id);
		if (!i) return '';
		if (i.chamadoEm) return `Chamada da fila em ${dma(i.chamadoEm).slice(0, 5)}`;
		if (i.status === 'Correção pedida') return 'Correção pedida';
		return '';
	}

	const doGrupo = (lista: PessoaDoSorteio[], g: Grupo) => lista.filter((p) => p.categoria === g);

	const comVaga = $derived(
		GRUPOS.map((g) => ({
			g,
			itens: juntar(doGrupo(s.sorteados, g)).map((u) => ({
				...u,
				rot: u.pessoas.some((p) => p.promovido) ? 'Chamado' : `${u.pessoas[0].posicao}º`
			}))
		}))
	);
	const fila = $derived(
		GRUPOS.map((g) => ({
			g,
			itens: juntar([...doGrupo(s.suplentes, g)].sort((a, b) => a.posicao - b.posicao)).map((u, n) => ({ ...u, rot: `${n + 1}º` }))
		}))
	);
	const naoSorteados = $derived(juntar(s.nao_sorteados.filter((p) => !p.foraDaArea)));
	const naoConcorreram = $derived(juntar(s.nao_sorteados.filter((p) => p.foraDaArea)));
	const naoConcorrem = $derived(juntar(s.nao_concorrem));

	function motivoDaCidade(pessoas: { user_id: number; cidade?: string | null }[]): string {
		const cidade = pessoas[0].cidade ?? porUsuario.get(pessoas[0].user_id)?.cidade ?? null;
		const onde = cidade ? `Mora em ${cidade}.` : 'Não tem cidade no cadastro.';
		return `${onde} Só concorre quem mora em Primavera do Leste ou Poxoréu.`;
	}

	const abertas = $derived(
		s.realizado
			? GRUPOS.filter((g) => (s.vagas_abertas?.[g] ?? 0) > 0).map((g) => {
					const n = s.vagas_abertas[g];
					const proximo = fila.find((f) => f.g === g)?.itens[0];
					return { g, n, proximo };
				})
			: []
	);

	const indicados = $derived(juntar(s.indicados));
	const concorremTxt = $derived.by(() => {
		if (s.casais) {
			const casais = Math.ceil(s.concorrem.Casado / 2);
			return `${plural(casais, 'casal', 'casais')} (${plural(s.concorrem.Casado, 'pessoa', 'pessoas')})`;
		}
		return `${plural(s.concorrem.Homem, 'homem', 'homens')} e ${plural(s.concorrem.Mulher, 'mulher', 'mulheres')}`;
	});

	/** Saídas: quem saiu por prazo, desistiu, foi cancelado, recusado ou retirado. */
	const saidas = $derived.by(() => {
		const saiu = inscricoes.filter((i) => SAIU.includes(situacaoDe(i, false, { Campista: s.realizado, Servo: false })));
		return juntar(saiu.map((i) => ({ user_id: i.user.id, nome: i.user.name, insc: i }))).map((u) => {
			const i = u.pessoas[0].insc;
			const sit = situacaoDe(i, false, { Campista: s.realizado, Servo: false });
			return { nome: u.nome, tipo: SIT[sit][0], data: i.saiuEm ? dma(i.saiuEm) : '—', motivo: motivoDe(i, sit) };
		});
	});

	const bloqueado = $derived(s.bloqueio.formularios + s.bloqueio.pagos > 0);
	const bloqueioTxt = $derived(
		[
			s.bloqueio.formularios ? `${plural(s.bloqueio.formularios, 'pessoa já enviou', 'pessoas já enviaram')} o formulário` : '',
			s.bloqueio.pagos ? `${s.bloqueio.pagos} já ${s.bloqueio.pagos === 1 ? 'pagou' : 'pagaram'}` : ''
		]
			.filter(Boolean)
			.join(' e ')
	);

	function situacaoDoIndicado(pessoas: { user_id: number }[]): string {
		return tagDe(pessoas).texto;
	}
</script>

<div class="ins-topo">
	<div>
		<h2 class="fm-h2">Sorteio de campistas</h2>
		{#if s.realizado}
			<p class="ins-sub">
				{#if s.feito_em}Feito em <strong>{dataBr(s.feito_em)}</strong>{:else}Feito{/if}
				(previsto para {dataBr(s.previsto)}). O sorteio é sempre manual.
			</p>
		{:else}
			<p class="ins-sub">
				Previsto para <strong>{dataBr(s.previsto)}</strong>. Ainda não foi feito: o site mostra “Aguardando sorteio” até você
				sortear aqui.
			</p>
		{/if}
	</div>
	<div class="vagas-cadastro">
		<span class="ins-rot">Vagas do cadastro</span>
		<strong>{vagasTxt}</strong>
		{#if pode('acampamentos')}
			<a href="/admin/acampamentos/editar/{atividadeId}">Mudar em Editar acampamento</a>
		{/if}
	</div>
</div>

{#if abertas.length > 0}
	<div class="ins-caixa ins-caixa--ambar" role="region" aria-label="Vagas abertas">
		<span class="abertas-tit">Vagas abertas: chame o próximo da fila</span>
		{#each abertas as a (a.g)}
			<div class="aberta">
				<span class="aberta-txt">
					<strong>
						{NOME_DO_GRUPO[a.g]} · {s.casais
							? a.n === 1
								? '1 vaga aberta (1 casal)'
								: `${a.n} vagas abertas (${a.n} casais)`
							: plural(a.n, 'vaga aberta', 'vagas abertas')}
					</strong>
					{#if a.proximo}
						<span>
							Próximo da fila: <strong>{a.proximo.nome}</strong> (1º da fila)
						</span>
					{/if}
				</span>
				{#if a.proximo}
					{@const prox = a.proximo}
					<button
						type="button"
						class="fm-btn fm-btn--ouro"
						onclick={() =>
							onchamar({
								tipo: 'Campista',
								grupo: a.g,
								fila: NOME_DO_GRUPO[a.g],
								nome: prox.nome,
								pessoas: prox.pessoas.length,
								formulario: prox.pessoas.every((p) => p.formularioEnviado)
							})}>Chamar próximo</button
					>
				{:else}
					<span class="sem-prox">Não há suplente na fila deste grupo.</span>
				{/if}
			</div>
		{/each}
		<p class="ins-ajuda nota-ambar">
			“Chamar próximo” chama sempre o primeiro da fila do mesmo grupo. Ele ganha 3 dias para enviar o formulário e pagar, sem passar do
			primeiro dia do acampamento.
		</p>
	</div>
{/if}

<div class="ins-caixa">
	<div class="indic-topo">
		<span class="indic-txt">
			<strong>Indicados · {s.casais ? plural(indicados.length, 'casal', 'casais') : indicados.length}</strong>
			<span>Vaga garantida fora do sorteio. Não contam nas vagas e continuam valendo se o sorteio for refeito.</span>
		</span>
		<button type="button" class="ins-acao" onclick={onindicar}>
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z" /></svg>
			Indicar
		</button>
	</div>
	{#each indicados as u (u.pessoas[0].user_id)}
		{@const insc = porUsuario.get(u.pessoas[0].user_id)}
		<div class="indicado">
			<span class="indicado-nome">{u.nome}</span>
			<span class="motivo-pill">{u.pessoas[0].metodo}</span>
			<span class="indicado-sit">{situacaoDoIndicado(u.pessoas)}</span>
			{#if insc}
				<button type="button" class="ins-acao2 perigo curto" onclick={() => ondesfazer(insc)}>
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
					Desfazer
				</button>
			{/if}
		</div>
	{:else}
		<span class="ins-ajuda">Nenhuma indicação.</span>
	{/each}
</div>

{#if !s.realizado}
	<div class="ins-caixa ins-caixa--creme">
		<span class="ins-rot">Quem concorre</span>
		<p class="ins-texto-grande">
			Concorrem <strong>{concorremTxt}</strong> para <strong>{vagasTxt}</strong>. O sorteio escolhe os titulares e o mesmo número de
			suplentes, em ordem.
		</p>
		{#each naoConcorrem as u (u.pessoas[0].user_id)}
			<p class="ins-aviso azul pequeno">
				<span><strong>{u.nome}</strong> {u.pessoas.length > 1 ? 'não concorrem' : 'não concorre'}. {motivoDaCidade(u.pessoas)}</span>
			</p>
		{/each}
		<div><button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={onrealizar}>Realizar sorteio de campistas</button></div>
	</div>
{:else}
	<div class="ins-colunas">
		<div class="ins-col" style="--ponto:#2E7D4F">
			<span class="ins-col-tit">Com vaga</span>
			{#each comVaga as g (g.g)}
				<span class="ins-rot grupo-rot">{NOME_DO_GRUPO[g.g]} · {g.itens.length}</span>
				{#each g.itens as u (u.pessoas[0].user_id)}
					{@const tag = tagDe(u.pessoas)}
					{@const extra = extraDe(u.pessoas)}
					<div class="ins-item">
						<span class="ins-pos">{u.rot}</span>
						<span class="ins-item-corpo">
							<span class="ins-item-nome">{u.nome}</span>
							<span class="ins-pill ins-pill--mini {tag.tom} alinhar">{tag.texto}</span>
							{#if extra}<span class="ins-item-extra">{extra}</span>{/if}
						</span>
					</div>
				{:else}
					<span class="ins-ajuda">Ninguém.</span>
				{/each}
			{/each}
		</div>
		<div class="ins-col" style="--ponto:#C08A1E">
			<span class="ins-col-tit">Fila de suplentes</span>
			{#each fila as g (g.g)}
				<span class="ins-rot grupo-rot">{NOME_DO_GRUPO[g.g]} · {g.itens.length}</span>
				{#each g.itens as u (u.pessoas[0].user_id)}
					{@const tag = tagDe(u.pessoas)}
					<div class="ins-item">
						<span class="ins-pos">{u.rot}</span>
						<span class="ins-item-corpo">
							<span class="ins-item-nome">{u.nome}</span>
							<!-- Suplente na fila não responde o formulário: só mostra se já pagou (adiantado). -->
							{#if tag.texto !== 'Sem formulário'}<span class="ins-pill ins-pill--mini {tag.tom} alinhar">{tag.texto}</span>{/if}
						</span>
					</div>
				{:else}
					<span class="ins-ajuda">Fila vazia.</span>
				{/each}
			{/each}
		</div>
		<div class="ins-col" style="--ponto:#8A8C99">
			<span class="ins-col-tit">Não sorteados</span>
			{#if naoSorteados.length}
				<p class="corrido">{naoSorteados.map((u) => u.nome).join(', ')}.</p>
			{:else}
				<span class="ins-ajuda">Ninguém: todos os que concorreram têm vaga ou estão na fila.</span>
			{/if}
			{#if naoConcorreram.length}
				<span class="ins-rot grupo-rot espaco">Não concorreram</span>
				{#each naoConcorreram as u (u.pessoas[0].user_id)}
					<div class="nao-conc">
						<span class="ins-item-nome">{u.nome}</span>
						<span class="nao-conc-motivo">{motivoDaCidade(u.pessoas)}</span>
					</div>
				{/each}
			{/if}
		</div>
	</div>
{/if}

{#if saidas.length}
	<div class="saidas">
		<span class="ins-rot">Saíram</span>
		{#each saidas as x, n (n)}
			<div class="ins-saida">
				<strong class="saida-nome">{x.nome}</strong>
				<span class="ins-pill ins-pill--mini can alinhar">{x.tipo}</span>
				<span class="saida-data">{x.data}</span>
				<span class="saida-motivo">{x.motivo}</span>
			</div>
		{/each}
	</div>
{/if}

{#if s.realizado}
	<div class="ins-refazer">
		<span class="refazer-txt">
			<strong>Refazer ou apagar o resultado</strong>
			{#if bloqueado}
				<span id="bloq-campistas" class="bloq">
					<strong>Bloqueado:</strong>
					{bloqueioTxt}. Depois disso, o sorteio não pode ser refeito nem apagado.
				</span>
			{:else}
				<span>Ninguém com vaga ou na fila enviou o formulário ou pagou. Ainda dá para sortear de novo ou apagar o resultado.</span>
			{/if}
		</span>
		<span class="refazer-botoes">
			<button
				type="button"
				class="fm-btn fm-btn--contorno"
				disabled={bloqueado}
				aria-describedby={bloqueado ? 'bloq-campistas' : undefined}
				onclick={onrefazer}>Sortear de novo</button
			>
			<button
				type="button"
				class="fm-btn fm-btn--perigo"
				disabled={bloqueado}
				aria-describedby={bloqueado ? 'bloq-campistas' : undefined}
				onclick={onapagar}>Apagar resultado</button
			>
		</span>
	</div>
{/if}

<style>
	.vagas-cadastro {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 2px;
		padding: 10px 16px;
		background: var(--cabecalho-tabela);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.vagas-cadastro strong {
		font-size: 17px;
		color: var(--azul);
	}
	.vagas-cadastro a {
		font-size: 14px;
		font-weight: 600;
		color: var(--azul);
	}
	.abertas-tit {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 17px;
		font-weight: 700;
		color: #6a480c;
	}
	.abertas-tit::before {
		content: '';
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: #c08a1e;
	}
	.aberta {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding: 12px 16px;
		background: var(--pergaminho);
		border: 1px solid #ebd39c;
		border-radius: 10px;
	}
	.aberta-txt {
		display: flex;
		flex-direction: column;
		gap: 2px;
		font-size: 15px;
		color: var(--tinta-2);
	}
	.aberta-txt > strong {
		font-size: 17px;
		color: var(--tinta);
	}
	.sem-prox {
		font-size: 15px;
		font-weight: 600;
		color: #6a480c;
	}
	.nota-ambar {
		font-size: 14px;
		color: #6a480c;
	}
	.indic-topo {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
	}
	.indic-txt {
		display: flex;
		flex-direction: column;
		gap: 2px;
		font-size: 15px;
		color: var(--tinta-3);
	}
	.indic-txt strong {
		font-size: 17px;
		color: var(--tinta);
	}
	.indicado {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 10px 14px;
		background: #ffffff;
		border: 1px solid var(--linha);
		border-radius: 10px;
	}
	.indicado-nome {
		flex: 1;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}
	.motivo-pill {
		padding: 2px 10px;
		font-size: 13px;
		font-weight: 700;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 999px;
	}
	.indicado-sit {
		font-size: 14px;
		color: var(--tinta-3);
	}
	.curto {
		height: 34px;
	}
	.pequeno {
		font-size: 15px;
	}
	.grupo-rot {
		margin-top: 4px;
	}
	.espaco {
		margin-top: 10px;
	}
	.alinhar {
		align-self: flex-start;
	}
	.corrido {
		margin: 0;
		font-size: 16px;
		line-height: 1.5;
		color: var(--tinta-2);
	}
	.nao-conc {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 10px 12px;
		background: #f1ece0;
		border-radius: 10px;
	}
	.nao-conc-motivo {
		font-size: 14px;
		color: var(--tinta-3);
	}
	.saidas {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.saida-nome {
		font-size: 16px;
		color: var(--tinta);
	}
	.saida-data {
		font-size: 14px;
		color: var(--tinta-3);
	}
	.saida-motivo {
		font-size: 15px;
		color: var(--tinta-2);
	}
	.refazer-txt {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 640px;
		font-size: 15px;
		color: var(--tinta-3);
	}
	.refazer-txt > strong {
		font-size: 16px;
		color: var(--tinta);
	}
	.bloq {
		color: #842424;
	}
	.refazer-botoes {
		display: flex;
		gap: 10px;
	}
</style>

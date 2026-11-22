<script lang="ts">
	/**
	 * Aba "Sorteio de servos": fora do sorteio (indicados, já na equipe, servo
	 * geral), quem concorre ou o resultado por setor, vagas abertas por setor,
	 * as saídas e "Refazer ou apagar o resultado" com o bloqueio.
	 */
	import { pode } from '$lib/permissoes';
	import { dma } from '$lib/pagamentos';
	import { SAIU, SIT, motivoDe, plural, situacaoDe, type Inscricao, type SorteioServos, type UnidadeChamada } from '$lib/inscritos';

	let {
		s,
		inscricoes,
		atividadeId,
		onchamar,
		onrealizar,
		onrefazer,
		onapagar
	}: {
		s: SorteioServos;
		/** Só as inscrições de servo. */
		inscricoes: Inscricao[];
		atividadeId: number;
		onchamar: (u: UnidadeChamada) => void;
		onrealizar: () => void;
		onrefazer: () => void;
		onapagar: () => void;
	} = $props();

	const porUsuario = $derived(new Map(inscricoes.map((i) => [i.user.id, i])));
	const dataBr = (x: string | null) => (x ? dma(x) : 'a definir');

	function tagDe(userId: number): { texto: string; tom: string } {
		const i = porUsuario.get(userId);
		const pago = i?.pagamento?.situacao === 'pago';
		if (i?.formularioEnviado && pago) return { texto: 'Formulário enviado · pago', tom: 'ok' };
		if (i?.formularioEnviado) return { texto: 'Formulário enviado', tom: 'ok' };
		if (pago) return { texto: 'Pago · sem formulário', tom: 'esp' };
		return { texto: 'Sem formulário', tom: 'esp' };
	}

	const vagasNoSorteio = $derived(s.setores.reduce((t, x) => t + x.vacancies, 0));

	const naoSorteados = $derived(s.nao_sorteados.filter((p) => !p.foraDaArea));
	const naoConcorreram = $derived(s.realizado ? s.nao_sorteados.filter((p) => p.foraDaArea) : s.nao_concorrem);

	function motivoDaCidade(userId: number, cidade?: string | null): string {
		const c = cidade ?? porUsuario.get(userId)?.cidade ?? null;
		return `${c ? `Mora em ${c}.` : 'Não tem cidade no cadastro.'} Só concorre quem mora em Primavera do Leste ou Poxoréu.`;
	}

	const saidas = $derived(
		inscricoes
			.map((i) => ({ i, sit: situacaoDe(i, false, { Campista: false, Servo: s.realizado }) }))
			.filter((x) => SAIU.includes(x.sit))
			.map(({ i, sit }) => ({ nome: i.user.name, tipo: SIT[sit][0], data: i.saiuEm ? dma(i.saiuEm) : '—', motivo: motivoDe(i, sit) }))
	);

	const bloqueado = $derived(s.bloqueio.formularios + s.bloqueio.pagos > 0);
	const bloqueioTxt = $derived(
		[
			s.bloqueio.formularios ? `${plural(s.bloqueio.formularios, 'pessoa já enviou', 'pessoas já enviaram')} o formulário` : '',
			s.bloqueio.pagos ? `${s.bloqueio.pagos} já ${s.bloqueio.pagos === 1 ? 'pagou' : 'pagaram'}` : ''
		]
			.filter(Boolean)
			.join(' e ')
	);
</script>

<div class="ins-topo">
	<div>
		<h2 class="fm-h2">Sorteio de servos</h2>
		{#if s.realizado}
			<p class="ins-sub">
				{#if s.feito_em}Feito em <strong>{dataBr(s.feito_em)}</strong>{:else}Feito{/if}
				(previsto para {dataBr(s.previsto)}). Completa as vagas que faltam em cada setor.
			</p>
		{:else}
			<p class="ins-sub">Previsto para <strong>{dataBr(s.previsto)}</strong>. Ainda não foi feito.</p>
		{/if}
	</div>
	{#if pode('equipes')}
		<a href="/admin/equipes?atividade={atividadeId}" class="ins-acao">
			<svg viewBox="0 0 24 24" aria-hidden="true"
				><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path
					d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"
				/></svg
			>
			Ver a equipe deste acampamento
		</a>
	{/if}
</div>

{#if s.setores.length === 0}
	<div class="sem-setores">
		<p class="sem-setores-tit">Nenhum setor com vagas de sorteio.</p>
		<p class="ins-ajuda">Sem vagas nos setores, não há sorteio de servos. Ligue os setores e ajuste as vagas deste acampamento em Equipes de Servos.</p>
		{#if pode('equipes')}<a href="/admin/equipes?atividade={atividadeId}" class="fm-btn fm-btn--contorno">Ir para Equipes de Servos</a>{/if}
	</div>
{:else}
	<div class="ins-caixa">
		<strong class="fora-tit">Fora do sorteio</strong>
		<div class="fora">
			<div class="fora-col">
				<span class="ins-rot">Indicados</span>
				{#each s.indicados as x (x.user_id)}
					<span class="fora-item"><strong>{x.nome}</strong> · {x.metodo}</span>
				{:else}
					<span class="ins-ajuda">Ninguém.</span>
				{/each}
			</div>
			<div class="fora-col">
				<span class="ins-rot">Já na equipe</span>
				{#each s.alocados_manualmente as x (x.user_id)}
					<span class="fora-item"><strong>{x.nome}</strong> · {x.lider ? `Líder · ${x.setor}` : x.setor}</span>
				{:else}
					<span class="ins-ajuda">Ninguém.</span>
				{/each}
			</div>
			<div class="fora-col">
				<span class="ins-rot">Servo geral</span>
				{#each s.servo_geral as x (x.user_id)}
					<span class="fora-item"><strong>{x.nome}</strong></span>
				{:else}
					<span class="ins-ajuda">Ninguém.</span>
				{/each}
			</div>
		</div>
	</div>

	{#if !s.realizado}
		<div class="ins-caixa ins-caixa--creme">
			<span class="ins-rot">Quem concorre</span>
			<p class="ins-texto-grande">
				Concorrem <strong
					>{plural(s.concorrem, 'servo', 'servos')} para {plural(vagasNoSorteio, 'vaga', 'vagas')} em {plural(
						s.setores.length,
						'setor',
						'setores'
					)}</strong
				>. Cada setor sorteia as vagas que faltam e o mesmo número de suplentes.
			</p>
			{#each naoConcorreram as x (x.user_id)}
				<p class="ins-aviso azul pequeno"><span><strong>{x.nome}</strong> não concorre. {motivoDaCidade(x.user_id, (x as { cidade?: string | null }).cidade)}</span></p>
			{/each}
			<div><button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={onrealizar}>Realizar sorteio de servos</button></div>
		</div>
	{/if}

	<div class="setores">
		{#each s.setores as setor (setor.id)}
			{@const aberta = s.realizado && setor.vagas_abertas > 0}
			<div class="setor" class:setor--aberta={aberta}>
				<div class="setor-cabeca">
					<strong class="setor-nome">{setor.sector_name}</strong>
					<span class="ins-ajuda pequeno">
						{plural(setor.vagas_total, 'vaga', 'vagas')} · {setor.na_equipe} já na equipe · {setor.vacancies} no sorteio
					</span>
				</div>
				{#if setor.sorteados.length}
					<span class="ins-rot">Com vaga</span>
					{#each setor.sorteados as x, n (x.user_id)}
						{@const tag = tagDe(x.user_id)}
						<div class="ins-item centro">
							<span class="ins-pos">{n + 1}º</span>
							<span class="ins-item-nome cresce">{x.nome}</span>
							<span class="ins-pill ins-pill--mini {tag.tom}">{tag.texto}</span>
						</div>
					{/each}
				{/if}
				{#if setor.suplentes.length}
					<span class="ins-rot">Fila</span>
					{#each setor.suplentes as x, n (x.user_id)}
						{@const tag = tagDe(x.user_id)}
						<div class="ins-item centro">
							<span class="ins-pos">{n + 1}º</span>
							<span class="ins-item-nome cresce">{x.nome}</span>
							<span class="ins-pill ins-pill--mini {tag.tom}">{tag.texto}</span>
						</div>
					{/each}
				{/if}
				{#if aberta}
					<div class="setor-aberta">
						<strong>{plural(setor.vagas_abertas, 'vaga aberta', 'vagas abertas')}</strong>
						{#if setor.suplentes.length}
							{@const prox = setor.suplentes[0]}
							<button
								type="button"
								class="fm-btn fm-btn--ouro"
								onclick={() =>
									onchamar({
										tipo: 'Servo',
										setor: setor.id,
										fila: setor.sector_name,
										nome: prox.nome,
										pessoas: 1,
										formulario: !!porUsuario.get(prox.user_id)?.formularioEnviado
									})}>Chamar {prox.nome}</button
							>
						{:else}
							<span class="sem-prox">Não há suplente na fila.</span>
						{/if}
					</div>
				{/if}
			</div>
		{/each}
	</div>

	{#if s.realizado && (naoSorteados.length || naoConcorreram.length)}
		<div class="lista-simples">
			{#if naoSorteados.length}
				<span class="ins-rot">Não sorteados</span>
				<p class="corrido">{naoSorteados.map((x) => x.nome).join(', ')}.</p>
			{/if}
			{#if naoConcorreram.length}
				<span class="ins-rot">Não concorreram</span>
				{#each naoConcorreram as x (x.user_id)}
					<p class="corrido"><strong>{x.nome}</strong>: {motivoDaCidade(x.user_id)}</p>
				{/each}
			{/if}
		</div>
	{/if}
{/if}

{#if saidas.length}
	<div class="lista-simples">
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

{#if s.realizado && s.setores.length}
	<div class="ins-refazer">
		<span class="refazer-txt">
			<strong>Refazer ou apagar o resultado</strong>
			{#if bloqueado}
				<span id="bloq-servos" class="bloq"
					><strong>Bloqueado:</strong> {bloqueioTxt}. Depois disso, o sorteio não pode ser refeito nem apagado.</span
				>
			{:else}
				<span>Ninguém com vaga ou na fila enviou o formulário ou pagou.</span>
			{/if}
		</span>
		<span class="refazer-botoes">
			<button
				type="button"
				class="fm-btn fm-btn--contorno"
				disabled={bloqueado}
				aria-describedby={bloqueado ? 'bloq-servos' : undefined}
				onclick={onrefazer}>Sortear de novo</button
			>
			<button
				type="button"
				class="fm-btn fm-btn--perigo"
				disabled={bloqueado}
				aria-describedby={bloqueado ? 'bloq-servos' : undefined}
				onclick={onapagar}>Apagar resultado</button
			>
		</span>
	</div>
{/if}

<style>
	.sem-setores {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 10px;
		padding: 40px 16px;
		text-align: center;
		background: var(--cabecalho-tabela);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
	}
	.sem-setores-tit {
		margin: 0;
		font: 600 26px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.fora-tit {
		font-size: 17px;
		color: var(--tinta);
	}
	.fora {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 14px;
	}
	.fora-col {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.fora-item {
		font-size: 16px;
		color: var(--tinta);
	}
	.pequeno {
		font-size: 14px;
	}
	.setores {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 16px;
	}
	.setor {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 16px 18px;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
	}
	.setor--aberta {
		border-color: #ebd39c;
	}
	.setor-cabeca {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.setor-nome {
		font: 600 24px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.centro {
		align-items: center;
	}
	.cresce {
		flex: 1;
	}
	.setor-aberta {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		flex-wrap: wrap;
		padding: 10px 12px;
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		border-radius: 10px;
	}
	.setor-aberta strong {
		font-size: 15px;
		color: #6a480c;
	}
	.sem-prox {
		font-size: 15px;
		font-weight: 600;
		color: #6a480c;
	}
	.lista-simples {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.corrido {
		margin: 0;
		font-size: 16px;
		line-height: 1.5;
		color: var(--tinta-2);
	}
	.alinhar {
		justify-self: start;
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

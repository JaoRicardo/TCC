import type { AcampamentoPorAcontecer } from '$lib/categorias';
import { datasDaAtividade, faseDaAtividade, type Fase } from '$lib/faseAtividade';

const p2 = (n: number) => String(n).padStart(2, '0');

/** "15 a 17/01/2027", "30/12/2026 a 02/01/2027", "15/01/2027" ou "Data a definir". */
export function dataDoAcampamento(a: AcampamentoPorAcontecer): string {
	if (!a.inicioEvento) return 'Data a definir';
	const i = new Date(a.inicioEvento);
	const f = a.fimEvento ? new Date(a.fimEvento) : i;
	const completa = (x: Date) => `${p2(x.getDate())}/${p2(x.getMonth() + 1)}/${x.getFullYear()}`;
	if (i.toDateString() === f.toDateString()) return completa(i);
	if (i.getFullYear() !== f.getFullYear()) return `${completa(i)} a ${completa(f)}`;
	if (i.getMonth() !== f.getMonth()) return `${p2(i.getDate())}/${p2(i.getMonth() + 1)} a ${completa(f)}`;
	return `${p2(i.getDate())} a ${completa(f)}`;
}

/** A fase, com as mesmas regras do selo do site e da Gestão de Atividades. */
export const faseDoAcampamento = (a: AcampamentoPorAcontecer): Fase => faseDaAtividade(datasDaAtividade(a), new Date());

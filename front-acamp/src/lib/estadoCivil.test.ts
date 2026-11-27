import { describe, expect, it } from 'vitest';
import { estadoCivilNoGenero } from './estadoCivil';

describe('estadoCivilNoGenero', () => {
	it('mulher: Casado vira Casada, Solteiro vira Solteira', () => {
		expect(estadoCivilNoGenero('Casado', 'F')).toBe('Casada');
		expect(estadoCivilNoGenero('Solteiro', 'F')).toBe('Solteira');
		expect(estadoCivilNoGenero('Viúvo', 'F')).toBe('Viúva');
	});

	it('homem ou sem sexo no cadastro: fica como veio do banco', () => {
		expect(estadoCivilNoGenero('Casado', 'M')).toBe('Casado');
		expect(estadoCivilNoGenero('Casado', null)).toBe('Casado');
	});

	it('os que não têm gênero não mudam', () => {
		expect(estadoCivilNoGenero('União estável', 'F')).toBe('União estável');
		expect(estadoCivilNoGenero('Segunda união', 'F')).toBe('Segunda união');
	});
});

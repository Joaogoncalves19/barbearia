// Antes dos testes de navegador: contas ficticias (um conjunto por projeto) com
// a senha aleatoria desta execucao. O comando recusa rodar fora de local/testing.
import { execFileSync } from 'node:child_process';

export default function globalSetup() {
    execFileSync('php', ['artisan', 'app:e2e-accounts', '--suffix=celular', '--suffix=desktop', '--suffix=temas'], {
        env: process.env,
        stdio: 'inherit',
    });
}

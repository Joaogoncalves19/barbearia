<?php

namespace App\Console\Commands;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Cria o PRIMEIRO proprietario (bootstrap). Os demais usuarios sao criados
 * pelo painel.
 *
 * Seguranca:
 * - so funciona enquanto nao existe nenhum proprietario (nao serve para
 *   criar "portas dos fundos" depois);
 * - a senha nunca vai na linha de comando (fica no historico do shell):
 *   e digitada sem eco, ou gerada aleatoriamente com --generate-password e
 *   mostrada uma unica vez; em ambos os casos a troca e obrigatoria no 1o
 *   acesso;
 * - em producao/homologacao exige confirmacao interativa (nao roda em
 *   script sem --force).
 */
class CreateOwner extends Command
{
    protected $signature = 'app:create-owner
        {--name= : Nome da pessoa}
        {--username= : Usuario para entrar (minusculas, numeros, . - _)}
        {--email= : E-mail (opcional; serve para recuperar a senha)}
        {--generate-password : Gera uma senha provisoria aleatoria em vez de pedir}
        {--force : Permite rodar sem confirmacao interativa em producao/homologacao}';

    protected $description = 'Cria o primeiro proprietario do sistema (so quando ainda nao existe nenhum)';

    public function handle(): int
    {
        if (User::query()->where('role', StaffRole::Owner->value)->exists()) {
            $this->error('Já existe um proprietário. Novos usuários são criados pelo painel (Usuários).');

            return self::FAILURE;
        }

        if (app()->environment(['production', 'homologacao']) && ! $this->option('force')
            && ! ($this->input->isInteractive() && $this->confirm('Ambiente '.app()->environment().'. Criar o primeiro proprietário agora?'))) {
            $this->error('Cancelado. Em produção/homologação rode de forma interativa (ou com --force, conscientemente).');

            return self::FAILURE;
        }

        $dados = [
            'name' => $this->option('name') ?? $this->ask('Nome'),
            'username' => User::normalizeUsername($this->option('username') ?? $this->ask('Usuário para entrar')),
            'email' => User::normalizeEmail($this->option('email') ?? $this->ask('E-mail (opcional, Enter para pular)')),
        ];

        $gerada = (bool) $this->option('generate-password');
        $senha = $gerada ? Str::password(16) : (string) $this->secret('Senha provisória (não aparece ao digitar)');
        $confirmacao = $gerada ? $senha : (string) $this->secret('Repita a senha');

        $validador = Validator::make($dados + ['password' => $senha, 'password_confirmation' => $confirmacao], [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($dados, $senha): User {
            $user = new User;
            $user->fill($dados);
            $user->forceFill([
                'password' => $senha,
                'role' => StaffRole::Owner,
                'is_active' => true,
                'must_change_password' => true,
                'password_changed_at' => now(),
            ])->save();

            AuditTrail::record('user.bootstrap_owner', $user, null, 'Primeiro proprietário criado pelo comando app:create-owner.');

            return $user;
        });

        $this->info("Proprietário criado: {$user->name} (usuário: {$user->username}).");
        if ($gerada) {
            $this->warn('Senha provisória (mostrada só agora): '.$senha);
        }
        $this->line('Entre em /painel/entrar. A troca da senha é obrigatória no primeiro acesso.');

        return self::SUCCESS;
    }
}

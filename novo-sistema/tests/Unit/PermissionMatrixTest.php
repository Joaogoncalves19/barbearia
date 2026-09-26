<?php

namespace Tests\Unit;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Tests\TestCase;

/**
 * Regras de permissao sem tocar no banco (usuario montado em memoria).
 */
class PermissionMatrixTest extends TestCase
{
    private function usuario(StaffRole $role, bool $ativo = true): User
    {
        $u = new User;
        $u->role = $role;
        $u->is_active = $ativo;

        return $u;
    }

    public function test_todo_papel_tem_entrada_na_matriz(): void
    {
        foreach (StaffRole::cases() as $role) {
            $this->assertArrayHasKey($role->value, config('permissions.roles'), "Papel {$role->value} sem entrada na matriz");
        }
    }

    public function test_toda_habilidade_dos_papeis_esta_declarada(): void
    {
        $declaradas = array_keys(config('permissions.abilities'));
        foreach (config('permissions.roles') as $papel => $habilidades) {
            foreach ($habilidades as $h) {
                $this->assertTrue($h === '*' || in_array($h, $declaradas, true), "Papel {$papel} usa habilidade nao declarada: {$h}");
            }
        }
    }

    public function test_curinga_do_proprietario_nao_concede_habilidade_inexistente(): void
    {
        $dono = $this->usuario(StaffRole::Owner);
        $this->assertTrue($dono->hasPermission('users.manage'));
        $this->assertFalse($dono->hasPermission('qualquer.coisa'));
    }

    public function test_usuario_inativo_nao_tem_permissao_alguma(): void
    {
        $this->assertFalse($this->usuario(StaffRole::Owner, ativo: false)->hasPermission('panel.access'));
    }

    public function test_papel_nao_concede_o_que_nao_lista(): void
    {
        $recepcao = $this->usuario(StaffRole::Reception);
        $this->assertTrue($recepcao->hasPermission('panel.access'));
        $this->assertFalse($recepcao->hasPermission('users.manage'));
        $this->assertFalse($recepcao->hasPermission('system.health.view'));
    }

    public function test_papel_sem_mapeamento_nao_tem_permissao(): void
    {
        config(['permissions.roles.reception' => null]);
        $this->assertFalse($this->usuario(StaffRole::Reception)->hasPermission('panel.access'));
    }
}

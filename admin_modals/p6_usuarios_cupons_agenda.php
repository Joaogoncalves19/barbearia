<div id="modal-usuario" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-user-shield"></i></div> <span>Adicionar/Editar Usuário</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_usuario">
            <input type="hidden" name="current_username" value="">
            <div class="form-group"><label>Usuário</label><input type="text" name="new_username" class="modern-input" required></div>
            <div class="form-group"><label>Perfil de acesso</label><select name="new_role" class="status-select" required><?php foreach (obterPerfisAdmin() as $roleId => $roleInfo): ?><option value="<?= htmlspecialchars($roleId) ?>"><?= htmlspecialchars($roleInfo['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Nova Senha</label><input type="password" name="new_password" class="modern-input" placeholder="Obrigatória para novos usuários" minlength="8" pattern="(?=.*[A-Za-zÀ-ÿ])(?=.*\d).{8,}" title="No mínimo 8 caracteres, com pelo menos uma letra e um número."><small class="hint" style="display:block; margin-top:6px; color:#64748b; font-size:.82rem;">No mínimo 8 caracteres, com pelo menos <strong>uma letra</strong> e <strong>um número</strong>.</small></div>
            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar</button>
        </form>
    </div>
</div>

<div id="modal-cupom" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-purple"><i class="fa fa-ticket"></i></div> <span>Adicionar/Editar Cupom</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_cupom">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="usos_atuais" value="0">
            <div class="form-group"><label>Código</label><input type="text" name="codigo" class="modern-input" style="text-transform:uppercase;" required></div>
            <div class="two-cols">
                <div class="form-group">
                    <label>Tipo de Desconto</label>
                    <select name="tipo_desconto" id="cupom_tipo_desconto" class="modern-input">
                        <option value="percentual">Percentual (%)</option>
                        <option value="fixo">Valor fixo (R$)</option>
                    </select>
                </div>
                <div class="form-group" id="cupom-campo-perc">
                    <label>Desconto (%)</label>
                    <input type="number" name="desconto_percentual" min="1" max="100" class="modern-input">
                </div>
                <div class="form-group" id="cupom-campo-fixo" style="display:none;">
                    <label>Valor do Desconto (R$)</label>
                    <input type="number" name="valor_desconto" min="0" step="0.01" class="modern-input" placeholder="Ex: 15.00">
                </div>
            </div>
            <div class="two-cols">
                <div class="form-group"><label>Usos Máximos</label><input type="number" name="usos_maximos" min="1" class="modern-input" required></div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="ativo" class="modern-input">
                        <option value="1">Ativo</option>
                        <option value="0">Desativado</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Data de Validade</label><input type="date" name="data_validade" class="modern-input" required></div>
            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar</button>
        </form>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('cupom_tipo_desconto');
    if (!sel) return;
    function sync() {
        var fixo = sel.value === 'fixo';
        var cPerc = document.getElementById('cupom-campo-perc');
        var cFixo = document.getElementById('cupom-campo-fixo');
        if (cPerc) cPerc.style.display = fixo ? 'none' : '';
        if (cFixo) cFixo.style.display = fixo ? '' : 'none';
    }
    sel.addEventListener('change', sync);
    // Sincroniza ao abrir o modal (cria ou edita)
    document.querySelectorAll('[data-modal-target="#modal-cupom"]').forEach(function (b) {
        b.addEventListener('click', function () { setTimeout(sync, 0); });
    });
})();
</script>

<div id="modal-reagendamento" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-calendar-days"></i></div> <span>Reagendar Horário</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_reagendamento">
            <input type="hidden" name="reagendar_agendamento_id" id="reagendar_agendamento_id">
            <input type="hidden" id="reagendar_barbeiro_id">
            <input type="hidden" id="reagendar_servicos_ids">
            <div class="form-group"><label>Nova Data</label><input type="date" name="reagendar_data" id="reagendar_data" class="modern-input" required></div>
            <div class="form-group"><label>Novo Horário</label><div class="horario-grid" id="reagendar_horarios-container">Selecione uma nova data.</div><input type="hidden" name="reagendar_horario" id="reagendar_horario" required></div>
            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar Novo Horário</button>
        </form>
    </div>
</div>

<div id="modal-voucher-editar" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-purple"><i class="fa fa-gift"></i></div> <span>Editar Voucher</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_voucher_editado">
            <input type="hidden" name="voucher_id" id="edit_voucher_id">
            <div class="form-group"><label for="data_validade">Data de Validade</label><input type="date" name="data_validade" id="edit_data_validade" class="modern-input"></div>
            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar Alterações</button>
        </form>
    </div>
</div>

<div id="modal-bloqueio-horarios" class="modal-overlay">
    <div class="modal-content">
        <div class="modern-modal-header">
            <h3 id="bloqueio-barbeiro-nome">
                <div class="modern-modal-icon icon-red"><i class="fa fa-calendar-times"></i></div>
                Gerenciar Agenda
            </h3>
            <button class="modal-close">&times;</button>
        </div>
        
        <form method="POST" id="form-bloqueio-horarios">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" id="action_bloqueio" value="salvar_bloqueios">
            <input type="hidden" name="bloqueio_barbeiro_id" id="bloqueio_barbeiro_id">
            <input type="hidden" name="bloqueio_data_selecionada" id="bloqueio_data_selecionada">
            
            <input type="hidden" name="bloqueio_mes_atual" id="bloqueio_mes_atual">
            <input type="hidden" name="bloqueio_ano_atual" id="bloqueio_ano_atual">
            <input type="hidden" name="modo_mes" id="modo_mes">
            
            <div class="bloqueio-layout">
                <div class="bloqueio-col">
                    <div class="bloqueio-col-title" style="margin-bottom: 10px;">
                        <span>1. Selecione o Dia</span>
                    </div>
                    <div style="display:flex; gap:5px; margin-bottom: 15px; flex-wrap: wrap;">
                        <button type="button" class="btn-selecionar-todos" id="btn-bloquear-mes" style="background:#fee2e2; color:#ef4444; border-color:#fca5a5; font-size: 0.75rem; flex: 1;" title="Bloquear todos os horários deste mês"><i class="fa fa-lock"></i> Fechar Mês</button>
                        <button type="button" class="btn-selecionar-todos" id="btn-desbloquear-mes" style="background:#dcfce7; color:#166534; border-color:#bbf7d0; font-size: 0.75rem; flex: 1;" title="Liberar todos os bloqueios deste mês"><i class="fa fa-unlock"></i> Liberar Mês</button>
                    </div>
                    <div id="bloqueio-calendario" class="modern-calendar"></div>
                </div>
                
                <div class="bloqueio-col">
                    <div class="bloqueio-col-title">
                        <span>2. Horários Fechados</span>
                        <button type="button" class="btn-selecionar-todos" id="btn-toggle-todos-bloqueios" style="display: none;">Bloquear Todo o Dia</button>
                    </div>
                    <p style="font-size: 0.85rem; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
                        Marque os horários de <strong style="color: #ef4444;">vermelho</strong> para bloqueá-los (almoço, folga, imprevistos). Horários agendados estão riscados.
                    </p>
                    
                    <div class="bloqueio-horarios-panel">
                        <div class="horario-grid" id="bloqueio-horarios-container">
                            <div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 40px 10px; font-style: italic;">
                                <i class="fa fa-hand-pointer" style="font-size: 2.5rem; margin-bottom: 15px; opacity: 0.3; display: block;"></i>
                                Selecione um dia no calendário ao lado para ver os horários.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bloqueio-footer">
                <button type="button" class="btn-refresh" onclick="document.querySelector('#modal-bloqueio-horarios .modal-close').click()">Cancelar</button>
                <button type="submit" class="btn-primary" style="margin: 0; width: auto; padding: 12px 30px; border-radius: 8px; background-color: #ef4444; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);"><i class="fa fa-save"></i> Salvar Seleção do Dia</button>
            </div>
        </form>
    </div>
</div>


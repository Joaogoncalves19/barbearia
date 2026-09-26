<div id="modal-notificacoes" class="modal-overlay">
    <div class="modal-content" style="max-width: 480px; width: 90%; padding: 30px; background: var(--card-bg);">
        <button class="modal-close" style="background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; position: absolute; top: 20px; right: 20px; cursor: pointer; color: #64748b; transition: all 0.3s;"><i class="fa fa-times"></i></button>
        <h3 style="margin-top: 0; margin-bottom: 25px; color: var(--text-main); display: flex; align-items: center; gap: 12px; font-size: 1.5rem; font-weight: 800;"><div style="background: #e0f2fe; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i class="fa fa-bell" style="color: var(--app-accent);"></i></div> Notificações</h3>
        <div style="max-height: 400px; overflow-y: auto; padding-right: 5px;">
            <ul class="notificacoes-lista" style="list-style: none; padding: 0; margin: 0;">
            <?php if(empty($notificacoes)): ?>
                <li style="text-align: center; display: block; color: #94a3b8; background: #f8fafc; border: 2px dashed #e2e8f0; padding: 40px 20px; border-radius: 16px; font-weight: 500;">Nenhuma notificação no momento.</li>
            <?php else: ?>
                <?php foreach($notificacoes as $n): ?>
                <li class="<?= $n['status'] ?>" style="padding: 18px; border: 1px solid var(--border-soft); display: flex; flex-direction: column; gap: 8px; border-radius: 14px; margin-bottom: 10px; transition: background 0.2s; <?= $n['status'] === 'nao_lida' ? 'background: #f0f9ff; border-left: 4px solid var(--app-accent);' : 'background: #fff;' ?>">
                    <span class="msg" style="font-size: 1rem; color: var(--text-main); line-height: 1.5; font-weight: 600;"><?= htmlspecialchars($n['mensagem']) ?></span>
                    <span class="date" style="font-size: 0.8rem; color: #94a3b8; text-align: left; font-weight: 700;"><i class="fa fa-clock" style="margin-right: 5px;"></i> <?= htmlspecialchars(date('d/m/Y H:i', strtotime($n['timestamp']))) ?></span>
                </li>
                <?php endforeach; ?>
            <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div id="modal-cropper" class="modal-overlay">
    <div class="modal-content" style="max-width: 400px; width: 90%; padding: 25px; background: var(--card-bg); text-align: center;">
        <h3 style="margin-top:0; font-size: 1.3rem; font-weight: 800; color: var(--text-main); margin-bottom: 15px;">Ajustar Foto do Perfil</h3>
        <div style="max-height: 350px; overflow: hidden; margin-bottom: 20px; border-radius: 12px;">
            <img id="image-to-crop" src="" style="max-width: 100%; display: block;">
        </div>
        <button type="button" id="btn-crop-confirm" class="btn-primary" style="width:100%; padding:14px; border-radius:12px; font-weight:800; font-size: 1.05rem;"><i class="fa fa-crop-alt"></i> Confirmar Corte</button>
        <button type="button" id="btn-crop-cancel" class="btn" style="width:100%; padding:14px; margin-top:10px; background:#f1f5f9; color:#64748b; border:none; border-radius:12px; font-weight:700;">Cancelar</button>
    </div>
</div>

<div id="modal-detalhes-agendamento" class="modal-overlay"><div class="modal-content" id="detalhes-content" style="max-width: 500px; padding: 30px; background: var(--card-bg);"></div></div>

<div id="modal-reagendamento" class="modal-overlay"><div class="modal-content" style="max-width: 480px; padding: 30px; background: var(--card-bg);"><button class="modal-close" style="background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; position: absolute; top: 20px; right: 20px; cursor: pointer;"><i class="fa fa-times"></i></button><h3 style="margin-top:0; font-size: 1.5rem; font-weight: 800; color: var(--text-main);">Reagendar Horário</h3><form id="form-reagendamento" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="action" value="reagendar_agendamento"><input type="hidden" name="reagendar_agendamento_id" id="reagendar_agendamento_id"><input type="hidden" id="reagendar_barbeiro_id"><input type="hidden" id="reagendar_servicos_ids"><div class="form-group" style="margin-bottom: 20px;"><label>Nova Data</label><input type="date" name="reagendar_data" id="reagendar_data" min="<?= date('Y-m-d') ?>" required></div><div class="form-group" style="margin-bottom: 25px;"><label>Novo Horário</label><div class="horario-grid" id="reagendar_horarios-container" style="margin-top:10px; display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 10px;"></div><input type="hidden" name="reagendar_horario" id="reagendar_horario" required><div class="erro" id="reagendar_erro_horario" style="color:#ef4444; font-size:0.9rem; margin-top:8px; font-weight: 600;"></div></div><button type="submit" class="btn-primary" style="width:100%; padding:16px; border-radius:14px; font-weight:800; font-size: 1.05rem; box-shadow: 0 10px 20px -5px rgba(0,0,0,0.2);">Confirmar Reagendamento</button></form></div></div>

<div id="modal-avaliacao" class="modal-overlay"><div class="modal-content" style="max-width: 450px; text-align: center; padding: 40px 30px; background: var(--card-bg);"><button class="modal-close" style="background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; position: absolute; top: 20px; right: 20px; cursor: pointer;"><i class="fa fa-times"></i></button><div style="background: #fffbeb; width: 70px; height: 70px; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;"><i class="fa fa-star" style="font-size: 2.5rem; color: var(--app-accent);"></i></div><h3 style="margin-top:0; font-size: 1.5rem; font-weight: 800; color: var(--text-main);">Avaliar Atendimento</h3><p style="color:var(--text-muted); font-size:1.05rem; margin-bottom:25px; font-weight: 500;">Como foi sua experiência com nosso profissional?</p><form id="form-avaliacao" method="post" action="salvar_avaliacao.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="agendamento_id" id="avaliacao_agendamento_id"><input type="hidden" name="barbeiro_id" id="avaliacao_barbeiro_id"><div class="form-group" style="margin-bottom: 25px;"><div class="rating-stars" style="display:flex; justify-content:center; flex-direction: row-reverse; gap:15px; font-size: 2rem;"><input type="radio" name="rating" id="star5" value="5" style="display:none;"><label for="star5" class="fa fa-star" style="cursor:pointer; color:#cbd5e1; transition:color 0.2s;"></label><input type="radio" name="rating" id="star4" value="4" style="display:none;"><label for="star4" class="fa fa-star" style="cursor:pointer; color:#cbd5e1; transition:color 0.2s;"></label><input type="radio" name="rating" id="star3" value="3" style="display:none;"><label for="star3" class="fa fa-star" style="cursor:pointer; color:#cbd5e1; transition:color 0.2s;"></label><input type="radio" name="rating" id="star2" value="2" style="display:none;"><label for="star2" class="fa fa-star" style="cursor:pointer; color:#cbd5e1; transition:color 0.2s;"></label><input type="radio" name="rating" id="star1" value="1" style="display:none;"><label for="star1" class="fa fa-star" style="cursor:pointer; color:#cbd5e1; transition:color 0.2s;"></label></div></div><div class="form-group" style="text-align:left; margin-bottom: 25px;"><label>Deixe um comentário (opcional)</label><textarea name="comment" rows="4" placeholder="Conte o que achou do serviço..."></textarea></div><button type="submit" class="btn-primary" style="width:100%; padding:16px; border-radius:14px; font-weight:800; font-size: 1.05rem;">Enviar Avaliação</button></form></div></div>

<div id="modal-excluir-conta" class="modal-overlay"><div class="modal-content" style="max-width: 470px; padding: 34px 30px; background: var(--card-bg);">
    <button class="modal-close" style="background: var(--surface-3); border: none; width: 36px; height: 36px; border-radius: 50%; position: absolute; top: 20px; right: 20px; cursor: pointer; color: var(--text-muted);"><i class="fa fa-times"></i></button>
    <div style="background: color-mix(in srgb, #ef4444, transparent 88%); width: 68px; height: 68px; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin-bottom: 18px;"><i class="fa fa-user-slash" style="font-size: 2rem; color: #dc2626;"></i></div>
    <h3 style="margin: 0 0 10px; font-size: 1.45rem; font-weight: 800; color: var(--text-main);">Excluir conta permanentemente</h3>
    <p style="color: var(--text-muted); font-size: 0.98rem; margin: 0 0 22px; line-height: 1.55;">Essa ação <strong style="color:#dc2626;">não pode ser desfeita</strong>. Para confirmar, informe sua senha e digite <strong>EXCLUIR</strong> no campo abaixo.</p>
    <form id="form-excluir-conta" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="excluir_conta">
        <div class="form-group" style="margin-bottom: 18px; text-align: left;">
            <label>Sua senha</label>
            <input type="password" name="senha_confirma" autocomplete="current-password" required>
        </div>
        <div class="form-group" style="margin-bottom: 24px; text-align: left;">
            <label>Digite <strong>EXCLUIR</strong> para confirmar</label>
            <input type="text" name="confirmacao_texto" autocomplete="off" placeholder="EXCLUIR" required style="text-transform: uppercase; letter-spacing: 0.05em;">
        </div>
        <button type="submit" style="width:100%; padding:15px; border-radius:14px; font-weight:800; font-size:1.05rem; background:#dc2626; color:#fff; border:none; cursor:pointer;"><i class="fa fa-trash-can" style="margin-right:8px;"></i> Excluir minha conta</button>
        <button type="button" class="modal-close" style="width:100%; padding:13px; margin-top:10px; background:var(--surface-3); color:var(--text-muted); border:none; border-radius:12px; font-weight:700; cursor:pointer;">Cancelar</button>
    </form>
</div></div>

<div id="modal-alterar-senha" class="modal-overlay"><div class="modal-content" style="max-width: 450px; padding: 30px; background: var(--card-bg);"><button class="modal-close" style="background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; position: absolute; top: 20px; right: 20px; cursor: pointer;"><i class="fa fa-times"></i></button><h3 style="margin-top:0; font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 25px;"><i class="fa fa-key" style="color: var(--app-accent); margin-right: 10px;"></i> Alterar Senha</h3><form id="form-alterar-senha" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="action" value="alterar_senha"><div class="form-group" style="margin-bottom: 20px;"><label>Senha Atual</label><input type="password" name="senha_atual" required></div><div class="form-group" style="margin-bottom: 20px;"><label>Nova Senha</label><input type="password" name="nova_senha" required></div><div class="form-group" style="margin-bottom: 25px;"><label>Confirmar Nova Senha</label><input type="password" name="confirma_nova_senha" required></div><button type="submit" class="btn-primary" style="width:100%; padding:16px; border-radius:14px; font-weight:800; font-size: 1.05rem;">Salvar Nova Senha</button></form></div></div>

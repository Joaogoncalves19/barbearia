<div id="dados" class="tabcontent">
    <h3 class="tabcontent-title"><i class="fa fa-user-edit" style="color: var(--app-accent);"></i> Meus Dados Pessoais</h3>
    
    <div style="background-color: var(--surface-2); border: 1px solid var(--border-soft); color: var(--text-muted); padding: 20px 25px; border-radius: 16px; margin-bottom: 30px; font-size: 0.95rem; display: flex; align-items: flex-start; gap: 15px;">
        <i class="fa fa-shield-alt" style="color: #64748b; font-size: 1.5rem; margin-top: 2px;"></i> 
        <div>
            <strong style="color: var(--text-main); font-size: 1rem;">Segurança e Integridade</strong><br>
            Alguns dados como <strong>E-mail</strong>, <strong>CPF</strong> e <strong>Data de Nascimento</strong> não podem ser editados por aqui para garantir a segurança da conta e a integridade do seu histórico. Entre em contato conosco caso precise alterar.
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="form-perfil">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="salvar_perfil">
        <input type="hidden" name="foto_perfil_base64" id="foto_perfil_base64">
        
        <div style="margin-bottom: 40px; display: flex; justify-content: center;">
            <label for="upload-foto" style="cursor: pointer; display: inline-block; position: relative;">
                <img src="<?= htmlspecialchars($foto_perfil) ?>" id="preview-foto" alt="Foto" style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 5px solid white; box-shadow: 0 10px 25px rgba(0,0,0,0.1); transition: transform 0.3s;">
                <div style="position: absolute; bottom: 5px; right: 5px; background: var(--text-main); color: white; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 4px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: background 0.3s; font-size: 1.2rem;">
                    <i class="fa fa-camera"></i>
                </div>
            </label>
            <input type="file" name="foto_perfil" id="upload-foto" style="display: none;" accept="image/*">
        </div>
        
        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
            <div class="form-group">
                <label><i class="fa fa-user" style="color: #cbd5e1; margin-right: 5px;"></i> Nome Completo</label>
                <input type="text" name="nome" value="<?= htmlspecialchars($clienteAtual['nome']) ?>" required>
            </div>

            <div class="form-group">
                <label><i class="fa fa-phone" style="color: #cbd5e1; margin-right: 5px;"></i> Telefone / WhatsApp</label>
                <input type="tel" name="telefone" value="<?= htmlspecialchars($clienteAtual['telefone']) ?>" required>
            </div>
            
            <div class="form-group">
                <label><i class="fa fa-envelope" style="color: #cbd5e1; margin-right: 5px;"></i> E-mail <span style="font-size: 0.8rem; color: #94a3b8; font-weight: normal;">(Não editável)</span></label>
                <input type="email" value="<?= htmlspecialchars($clienteAtual['email']) ?>" disabled style="cursor: not-allowed; opacity: 0.7; background-color: var(--surface-3); border-color: var(--border-soft);">
            </div>
            
            <div class="form-group">
                <label><i class="fa fa-calendar" style="color: #cbd5e1; margin-right: 5px;"></i> Data de Nascimento <span style="font-size: 0.8rem; color: #94a3b8; font-weight: normal;">(Não editável)</span></label>
                <input type="text" value="<?= !empty($clienteAtual['data_nascimento']) ? date('d/m/Y', strtotime($clienteAtual['data_nascimento'])) : 'Não informada' ?>" disabled style="cursor: not-allowed; opacity: 0.7; background-color: var(--surface-3); border-color: var(--border-soft);">
            </div>

            <div class="form-group">
                <label><i class="fa fa-id-card" style="color: #cbd5e1; margin-right: 5px;"></i> CPF <span style="font-size: 0.8rem; color: #94a3b8; font-weight: normal;">(Não editável)</span></label>
                <input type="text" value="<?= htmlspecialchars($clienteAtual['cpf'] ?? '') ?>" disabled style="cursor: not-allowed; opacity: 0.7; background-color: var(--surface-3); border-color: var(--border-soft);">
            </div>
        </div>
        
        <div style="text-align: right; margin-top: 35px; border-top: 1px solid var(--border-soft); padding-top: 25px;">
            <button type="submit" class="btn-primary" style="padding: 16px 40px; border-radius: 14px; font-weight: 800; font-size: 1.1rem; box-shadow: 0 10px 20px -5px rgba(0,0,0,0.2);"><i class="fa fa-save" style="margin-right: 8px;"></i> Salvar Alterações</button>
        </div>
    </form>
    
    <div style="margin-top: 40px; padding: 30px; background: var(--surface-2); border-radius: 20px; border: 1px solid var(--border-soft); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
        <div>
            <h4 style="margin: 0 0 8px; color: var(--text-main); font-size: 1.2rem; font-weight: 800;"><i class="fa fa-lock" style="color: #64748b; margin-right: 8px;"></i> Segurança da Conta</h4>
            <p style="margin: 0; color: var(--text-muted); font-size: 1rem; font-weight: 500;">Altere sua senha periodicamente para manter sua conta protegida.</p>
        </div>
        <button class="btn" data-modal-target="#modal-alterar-senha" style="background: var(--card-bg); border: 1px solid var(--border-strong); color: var(--text-main); border-radius: 12px; padding: 12px 25px; font-weight: 700; box-shadow: 0 2px 5px rgba(0,0,0,0.02);"><i class="fa fa-key" style="margin-right: 8px;"></i> Alterar Senha</button>
    </div>

    <div class="danger-zone" style="margin-top: 25px; padding: 30px; background: color-mix(in srgb, #ef4444, transparent 94%); border: 1px solid color-mix(in srgb, #ef4444, transparent 70%); border-radius: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
        <div style="max-width: 560px;">
            <h4 style="margin: 0 0 8px; color: #dc2626; font-size: 1.2rem; font-weight: 800;"><i class="fa fa-triangle-exclamation" style="margin-right: 8px;"></i> Excluir minha conta</h4>
            <p style="margin: 0; color: var(--text-muted); font-size: 0.98rem; font-weight: 500; line-height: 1.5;">
                Esta ação é <strong>permanente e irreversível</strong>. Seus dados pessoais serão apagados e você deixará de receber nossas comunicações. O histórico financeiro é mantido de forma anônima, conforme a LGPD.
            </p>
        </div>
        <button class="btn" data-modal-target="#modal-excluir-conta" style="background: #dc2626; border: none; color: #fff; border-radius: 12px; padding: 13px 26px; font-weight: 800; box-shadow: 0 8px 18px -8px rgba(220,38,38,0.6); white-space: nowrap;"><i class="fa fa-user-slash" style="margin-right: 8px;"></i> Excluir conta</button>
    </div>
</div>
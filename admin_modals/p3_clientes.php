<div id="modal-cliente" class="modal-overlay">
    <div class="modal-content" style="max-width: 750px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3>
                <div class="modern-modal-icon"><i class="fa fa-user"></i></div>
                <span>Adicionar/Editar Cliente</span>
            </h3>
            <button class="modal-close">&times;</button>
        </div>
        
        <form method="post" enctype="multipart/form-data" style="padding: 25px; max-height: 75vh; overflow-y: auto;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_cliente">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="foto_perfil_atual" value="">
            
            <h4 class="section-title"><i class="fa fa-address-card"></i> Dados Pessoais</h4>
            <div class="two-cols">
                <div class="form-group">
                    <label>Nome Completo</label>
                    <input type="text" name="nome" required class="modern-input" placeholder="Ex: Maria Silva">
                </div>
                <div class="form-group">
                    <label>CPF</label>
                    <input type="text" name="cpf" id="cpf-modal" class="modern-input" placeholder="000.000.000-00">
                </div>
                <div class="form-group">
                    <label>Data de Nascimento</label>
                    <input type="date" name="data_nascimento" class="modern-input">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Foto de Perfil (max 2MB)</label>
                    <input type="file" name="foto_perfil" accept="image/jpeg, image/png" style="padding: 12px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; width: 100%; box-sizing: border-box; cursor: pointer;">
                </div>
            </div>

            <h4 class="section-title"><i class="fa fa-address-book"></i> Contato e Acesso</h4>
            <div class="two-cols">
                <div class="form-group">
                    <label>Telefone / WhatsApp</label>
                    <input type="tel" name="telefone" required class="modern-input" placeholder="(99) 99999-9999">
                </div>
                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" name="email" required class="modern-input" placeholder="cliente@email.com">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Nova Senha</label>
                    <input type="password" name="nova_senha" class="modern-input" placeholder="Obrigatório para novo cadastro, deixe em branco para não alterar">
                </div>
            </div>

            <button type="submit" class="btn-primary">
                <i class="fa fa-save"></i> Salvar Cliente
            </button>
        </form>
    </div>
</div>

<div id="modal-cliente-detalhes" class="modal-overlay">
    <div class="modal-content" style="max-width: 900px; padding: 0; overflow: hidden; background: #f8fafc;">
        <div class="modern-modal-header">
            <h3>
                <div class="modern-modal-icon"><i class="fa fa-user-circle"></i></div>
                Perfil e Histórico do Cliente
                <span id="cliente-detalhes-nome" style="display:none;"></span>
            </h3>
            <button class="modal-close">&times;</button>
        </div>
        <div id="cliente-detalhes-content" style="max-height: 75vh; overflow-y: auto; padding: 30px;">
            <!-- Renderizado via AJAX -->
        </div>
    </div>
</div>


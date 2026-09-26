<div id="modal-servico" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-cut"></i></div> <span>Adicionar/Editar Serviço</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_servico">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="nome" class="modern-input" required>
            </div>
            <div class="form-group">
                <label>Valor (R$)</label>
                <input type="number" name="valor" step="0.01" class="modern-input" required>
            </div>
            <div class="form-group">
                <label>Duração (em slots de 30 min)</label>
                <input type="number" name="slots" min="1" value="1" class="modern-input" required>
                <p style="font-size: 0.8em; color: #6c757d; margin-top: 5px;">
                    Ex: 1 = 30 min, 2 = 60 min, 3 = 90 min.
                </p>
            </div>

            <div class="form-group">
                <label>Categoria</label>
                <select name="categoria_id" class="status-select">
                    <option value="">Sem Categoria</option>
                    <?php
                    $categoriasOrdenadas = $categoriasArr;
                    uasort($categoriasOrdenadas, fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
                    foreach ($categoriasOrdenadas as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['id']) ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <span>Descrição (site)</span>
                    <button type="button" id="btn-ia-descricao-servico" class="btn-ia-descricao"><i class="fa fa-wand-magic-sparkles"></i> Gerar com IA</button>
                </label>
                <textarea name="descricao" id="servico-descricao" class="modern-input" rows="2" placeholder="Texto de venda exibido na página inicial (opcional)"></textarea>
            </div>

            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar</button>
        </form>
    </div>
</div>

<div id="modal-categoria" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-tags"></i></div> <span>Adicionar/Editar Categoria</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_categoria">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label>Nome da Categoria</label>
                <input type="text" name="nome" class="modern-input" required>
            </div>
            <div class="form-group">
                <label>Ordem de Exibição</label>
                <input type="number" name="ordem" value="10" class="modern-input">
                <p style="font-size: 0.8em; color: #6c757d; margin-top: 5px;">
                    Números menores aparecem primeiro.
                </p>
            </div>
            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar Categoria</button>
        </form>
    </div>
</div>

<div id="modal-plano" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-purple"><i class="fa fa-crown"></i></div> <span>Configurar Plano de Assinatura</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" action="admin.php" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_plano">
            <input type="hidden" name="id" value="">

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; color: #475569; margin-bottom: 8px;">Nome do Plano</label>
                <div class="input-icon">
                    <i class="fa fa-star"></i>
                    <input type="text" name="nome" required placeholder="Ex: Clube da Barba" style="padding-left: 45px; width: 100%; box-sizing: border-box;">
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; color: #475569; margin-bottom: 8px;">Valor da Assinatura Mensal (R$)</label>
                <div class="input-icon">
                    <i class="fa fa-dollar-sign"></i>
                    <input type="number" name="valor" step="0.01" required placeholder="0.00" style="padding-left: 45px; width: 100%; box-sizing: border-box;">
                </div>
            </div>
            
            <div class="form-group">
                <label style="display: block; font-weight: 600; color: #475569; margin-bottom: 5px;">Quais serviços serão ilimitados?</label>
                <p style="font-size:0.85em; color:#64748b; margin-bottom:15px;">Marque os serviços que o assinante deste plano não precisará pagar ao agendar.</p>
                
                <div style="max-height: 250px; overflow-y: auto; border: 1px solid #cbd5e1; padding: 15px; border-radius: 12px; background: #f8fafc;">
                    <?php if (empty($servicosArr)): ?>
                        <p style="color: #ef4444; font-size: 0.9rem; margin: 0;">Cadastre serviços individuais primeiro.</p>
                    <?php else: ?>
                        <?php foreach ($servicosArr as $s): ?>
                            <label style="display: flex; align-items: center; background: white; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 8px; cursor: pointer; transition: 0.2s;" onmouseover="this.style.borderColor='#cbd5e1'" onmouseout="this.style.borderColor='#e2e8f0'">
                                <input type="checkbox" name="plano_servicos_ids[]" value="<?= $s['id'] ?>" id="plano_sv_<?= $s['id'] ?>" style="width: 18px; height: 18px; margin-right: 15px; accent-color: #8b5cf6;">
                                <div style="flex: 1;">
                                    <span style="font-weight: 600; color: #1e293b; display: block;"><?= htmlspecialchars($s['nome']) ?></span>
                                    <span style="font-size: 0.8rem; color: #64748b;">Preço normal: R$ <?= $s['valor'] ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn-modern-add">
                <i class="fa fa-save"></i> Salvar Plano
            </button>
        </form>
    </div>
</div>



<div id="modal-add-produto" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-amber"><i class="fa fa-shopping-cart"></i></div> <span>Adicionar à Conta</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" action="admin.php" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="adicionar_produto">
            <input type="hidden" name="agendamento_id" id="produto_agendamento_id">

            <div class="form-group">
                <label for="produto_id">Selecione o Produto (Em Estoque)</label>
                <div class="custom-select-wrapper" style="max-width: 100%; margin-bottom: 15px;">
                    <i class="fa fa-box"></i>
                    <select name="produto_id" id="produto_id" required>
                        <option value="">-- Escolha um produto --</option>
                        <?php if(!empty($produtosArr)): ?>
                            <?php foreach($produtosArr as $p): ?>
                                <?php if((int)$p['quantidade'] > 0): ?>
                                    <option value="<?= htmlspecialchars($p['id']) ?>">
                                        <?= htmlspecialchars($p['nome']) ?> - R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?> (Estoque: <?= $p['quantidade'] ?>)
                                    </option>
                                <?php else: ?>
                                    <option value="" disabled>
                                        <?= htmlspecialchars($p['nome']) ?> - ESGOTADO
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>Nenhum produto cadastrado no estoque.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="qtd_vendida">Quantidade Vendida</label>
                <input type="number" name="qtd_vendida" min="1" value="1" class="modern-input" required>
            </div>
            
            <button type="submit" class="btn-primary">
                <i class="fa fa-plus"></i> Lançar Venda
            </button>
        </form>
    </div>
</div>

<div id="modal-produto" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-amber"><i class="fa fa-box-open"></i></div> <span>Configurar Produto</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" action="admin.php" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_produto">
            <input type="hidden" name="id" value="">

            <div class="form-group">
                <label>Nome do Produto</label>
                <input type="text" name="nome" class="modern-input" required placeholder="Ex: Pomada Efeito Matte 100g">
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label>Valor de Venda (R$)</label>
                    <input type="number" name="valor" step="0.01" class="modern-input" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Quantidade (Estoque Inicial)</label>
                    <input type="number" name="quantidade" min="0" class="modern-input" required placeholder="Ex: 10">
                </div>
            </div>

            <div class="two-cols">
                <div class="form-group">
                    <label>Preço de custo (R$)</label>
                    <input type="number" name="custo" step="0.01" min="0" class="modern-input" value="0" placeholder="Quanto você paga">
                    <small style="display:block; margin-top:5px; color:#94a3b8; font-size:.72rem;">Usado para calcular o lucro e o CMV no financeiro.</small>
                </div>
                <div class="form-group">
                    <label>Ponto de reposição (mín.)</label>
                    <input type="number" name="estoque_minimo" min="0" class="modern-input" value="5" placeholder="Ex: 5">
                    <small style="display:block; margin-top:5px; color:#94a3b8; font-size:.72rem;">Alerta quando o estoque atingir esse valor.</small>
                </div>
            </div>

            <div class="form-group">
                <label>Categoria (Opcional)</label>
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
            
            <button type="submit" class="btn-primary">
                <i class="fa fa-save"></i> Salvar Produto
            </button>
        </form>
    </div>
</div>


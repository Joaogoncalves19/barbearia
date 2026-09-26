<div id="modal-agendamento-manual" class="modal-overlay">
    <div class="modal-content" style="max-width: 800px; padding: 0; overflow: hidden; background: #fff;">
        <div class="modern-modal-header">
            <h3>
                <div class="modern-modal-icon"><i class="fa fa-calendar-plus"></i></div>
                <span>Adicionar Agendamento Manual</span>
            </h3>
            <button class="modal-close">&times;</button>
        </div>
        
        <form method="post" style="padding: 25px; max-height: 75vh; overflow-y: auto;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_agendamento_manual">
            
            <h4 class="section-title" style="margin-top:0;"><i class="fa fa-user"></i> Cliente</h4>
            
            <div class="form-group">
                <label>Buscar Cliente Cadastrado (Opcional)</label>
                <div class="custom-dropdown-container">
                    <input type="text" id="busca_cliente_input" class="modern-input" placeholder="Digite o nome ou telefone para buscar..." autocomplete="off">
                    <input type="hidden" name="manual_cliente_id" id="manual_cliente_id">
                    
                    <ul id="lista_clientes_dropdown" class="custom-dropdown-list">
                        <li data-id="" data-nome="" data-telefone="" style="background: #f8fafc; color: var(--secondary-color); font-weight: bold;">
                            <div><i class="fa fa-user-plus" style="margin-right: 5px;"></i> Novo Cliente / Sem Cadastro</div>
                        </li>
                        <?php foreach($clientesArr as $id => $c): ?>
                            <li data-id="<?= htmlspecialchars($id) ?>" data-nome="<?= htmlspecialchars($c['nome']) ?>" data-telefone="<?= htmlspecialchars($c['telefone']) ?>">
                                <strong><?= htmlspecialchars($c['nome']) ?></strong>
                                <small><?= htmlspecialchars($c['telefone']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="two-cols">
                <div class="form-group">
                    <label>Nome do Cliente</label>
                    <input type="text" name="manual_nome" id="manual_nome" required class="modern-input" placeholder="Ex: Carlos Silva">
                </div>
                <div class="form-group">
                    <label>Telefone / WhatsApp</label>
                    <input type="tel" name="manual_telefone" id="manual_telefone" placeholder="(99) 99999-9999" required class="modern-input">
                </div>
            </div>

            <h4 class="section-title"><i class="fa fa-cut"></i> Serviços e Profissional</h4>
            
            <div class="form-group">
                <label>Profissional (Barbeiro)</label>
                <select name="manual_barbeiro" id="manual_barbeiro" required class="status-select">
                    <option value="">Selecione o Profissional...</option>
                    <?php foreach($barbeirosArr as $id => $b): ?>
                        <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($b['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Selecione os Serviços (Clique para selecionar)</label>
                <div id="servicos-container-modal" style="max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; padding: 15px; border-radius: 12px; background: #f8fafc;">
                    
                    <?php if (empty($servicosArr) && empty($combosArr)): ?>
                        <p style="margin:0; text-align: center; color: #64748b;"><i class="fa fa-info-circle"></i> Nenhum serviço cadastrado.</p>
                    <?php else: ?>
                        
                        <h5 style="margin: 0 0 12px 0; color: #475569; font-size: 0.95rem;">Serviços Individuais</h5>
                        <div class="checkbox-grid" style="margin-bottom: 20px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                            <?php foreach ($servicosArr as $id => $s): 
                                $slots_servico = (int)($s['slots'] ?? 1); if ($slots_servico <= 0) $slots_servico = 1;
                            ?>
                                <button type="button" class="servico-btn-modal" 
                                        data-id="<?= htmlspecialchars($id) ?>" 
                                        data-slots="<?= $slots_servico ?>">
                                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($s['nome']) ?></div>
                                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 4px;">R$ <?= number_format($s['valor'], 2, ',', '.') ?> &bull; <?= $slots_servico * 30 ?> min</div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (!empty($combosArr)): ?>
                            <h5 style="margin: 0 0 12px 0; color: #475569; font-size: 0.95rem;">Combos Promocionais</h5>
                            <div class="checkbox-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                                <?php foreach ($combosArr as $id => $c): 
                                    $slots_combo = 0;
                                    $sids_do_combo = explode(',', $c['servicos_ids']);
                                    foreach ($sids_do_combo as $sid_combo) {
                                        $sid_limpo = trim($sid_combo);
                                        if (isset($servicosArr[$sid_limpo])) {
                                            $slots_combo += (int)($servicosArr[$sid_limpo]['slots'] ?? 1);
                                        } else {
                                            $slots_combo += 1;
                                        }
                                    }
                                    if ($slots_combo == 0) $slots_combo = 1;
                                ?>
                                    <button type="button" class="servico-btn-modal combo-btn" 
                                            data-id="<?= htmlspecialchars($id) ?>" 
                                            data-slots="<?= $slots_combo ?>">
                                        <div style="font-weight: 600; color: #b45309;"><i class="fa fa-star" style="font-size:0.8em;"></i> <?= htmlspecialchars($c['nome']) ?></div>
                                        <div style="font-size: 0.8rem; color: #92400e; margin-top: 4px;">R$ <?= number_format($c['valor'], 2, ',', '.') ?> &bull; <?= $slots_combo * 30 ?> min</div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    
                    <?php endif; ?>
                </div>
                <input type="hidden" name="manual_servicos" id="manual_servicos" required>
            </div>

            <h4 class="section-title"><i class="fa fa-clock"></i> Data e Horário</h4>

            <div class="form-group">
                <label>Data do Agendamento</label>
                <input type="date" name="manual_data" id="manual_data" required class="modern-input" style="width: 50%;">
            </div>
            
            <div class="form-group">
                <label>Horários Disponíveis (Automático)</label>
                <div class="horario-grid" id="manual_horarios-container" style="background: #f8fafc; padding: 25px 20px; border-radius: 12px; border: 1px dashed #cbd5e1; text-align: center; color: #94a3b8; font-style: italic;">
                    <i class="fa fa-mouse-pointer" style="display:block; font-size: 2rem; margin-bottom: 10px; opacity:0.3;"></i>
                    Selecione o profissional, a data e os serviços acima para liberar os horários.
                </div>
                <input type="hidden" name="manual_horario" id="manual_horario" required>
            </div>

            <button type="submit" class="btn-primary">
                <i class="fa fa-save"></i> Agendar Agora
            </button>
        </form>
    </div>
</div>

<div id="modal-combo" class="modal-overlay">
    <div class="modal-content" style="padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-amber"><i class="fa fa-layer-group"></i></div> <span>Adicionar/Editar Combo</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_combo">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label>Nome do Combo</label>
                <input type="text" name="nome" class="modern-input" required>
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
                <label>Serviços (selecione 2 ou mais)</label>
                <div id="servicos-para-combo-container">
                    <?php foreach ($servicosArr as $id => $s): ?>
                        <button type="button" class="servico-btn-modal" data-id="<?= htmlspecialchars($id) ?>">
                            <?= htmlspecialchars($s['nome']) ?> - R$<?= htmlspecialchars($s['valor']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="combo_servicos_ids" id="combo_servicos_ids" required>
            </div>
            <div class="form-group">
                <label>Valor Promocional do Combo (R$)</label>
                <input type="number" name="valor" step="0.01" class="modern-input" required>
            </div>
            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar Combo</button>
        </form>
    </div>
</div>


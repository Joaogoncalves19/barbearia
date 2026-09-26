<div id="modal-almoco" class="modal-overlay">
    <div class="modal-content">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-hamburger" style="color: #F59E0B;"></i> Pausa de Almoço</h3>
            <p class="modal-subtitle-premium">Bloqueio automático diário de 1 hora.</p>
        </div>
        
        <form method="post" action="barbeiro_actions.php" class="saas-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="salvar_config_almoco">
            
            <div class="form-group">
                <label class="modern-label">Status do Bloqueio</label>
                <div class="select-modern-wrapper">
                    <select name="status_almoco" class="modern-select" required>
                        <option value="ativo" <?= ($config_almoco_barbeiro['status'] ?? '') == 'ativo' ? 'selected' : '' ?>>Ativado (Bloquear horário todos os dias)</option>
                        <option value="inativo" <?= ($config_almoco_barbeiro['status'] ?? '') == 'inativo' ? 'selected' : '' ?>>Desativado (Horários sempre livres)</option>
                    </select>
                    <i class="fa fa-chevron-down select-icon"></i>
                </div>
            </div>
            
            <div class="form-group">
                <label class="modern-label">Horário de Início da Pausa</label>
                <div class="select-modern-wrapper">
                    <select name="horario_almoco" class="modern-select" required>
                        <?php foreach ($horarios_para_almoco as $horario): ?>
                            <option value="<?= htmlspecialchars($horario) ?>" <?= ($config_almoco_barbeiro['horario'] ?? '') == $horario ? 'selected' : '' ?>><?= htmlspecialchars($horario) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa fa-clock select-icon"></i>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary" style="background: #F59E0B; width: 100%;">
                    <i class="fa fa-save"></i> Salvar Configuração de Pausa
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-cliente-detalhes" class="modal-overlay">
    <div class="modal-content">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-user-circle" style="color: #10B981;"></i> Ficha do Cliente</h3>
            <p class="modal-subtitle-premium">Histórico, preferências e análise comportamental.</p>
        </div>
        
        <div id="cliente-detalhes-content" style="max-height: 70vh; overflow-y: auto; padding-right: 5px;">
            </div>
    </div>
</div>

<div id="modal-add-produto" class="modal-overlay">
    <div class="modal-content">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-shopping-cart" style="color: #8B5CF6;"></i> Vender Produto</h3>
            <p class="modal-subtitle-premium">Adicione itens do estoque à comanda deste atendimento.</p>
        </div>
        
        <form method="post" action="barbeiro_actions.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="adicionar_produto">
            <input type="hidden" name="agendamento_id" id="produto_agendamento_id_barbeiro">
            
            <div class="form-group">
                <label class="modern-label">Selecione o Produto</label>
                <div class="select-modern-wrapper">
                    <select name="produto_id" class="modern-select" required>
                        <option value="">-- Escolher produto do estoque --</option>
                        <?php if(!empty($produtosArr)): foreach($produtosArr as $p): if((int)$p['quantidade'] > 0): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>"><?= htmlspecialchars($p['nome']) ?> - R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?> (Estoque: <?= $p['quantidade'] ?>)</option>
                        <?php else: ?><option value="" disabled><?= htmlspecialchars($p['nome']) ?> - ESGOTADO</option><?php endif; endforeach; else: ?><option value="" disabled>Nenhum produto cadastrado.</option><?php endif; ?>
                    </select>
                    <i class="fa fa-box select-icon"></i>
                </div>
            </div>
            
            <div class="form-group">
                <label class="modern-label">Quantidade</label>
                <input type="number" name="qtd_vendida" min="1" value="1" class="modern-input" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary" style="background: #8B5CF6; width: 100%;">
                    <i class="fa fa-plus-circle"></i> Lançar na Comanda
                </button>
            </div>
        </form>
    </div>
</div>

<?php
    // Especialidades do barbeiro logado (serviços e combos que ele realiza)
    $bm_espec = array_filter(array_map('trim', explode(',', $barbeiro_atual['servicos_ids'] ?? '')));
    $bm_servicos = array_filter($servicosArr, fn($id) => in_array($id, $bm_espec, true), ARRAY_FILTER_USE_KEY);
    $bm_combos   = array_filter($combosArr, fn($id) => in_array($id, $bm_espec, true), ARRAY_FILTER_USE_KEY);
?>
<div id="modal-agendamento-manual" class="modal-overlay">
    <div class="modal-content" style="max-width: 640px;">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-calendar-plus" style="color: #6366F1;"></i> Novo Agendamento Manual</h3>
            <p class="modal-subtitle-premium">Encaixe um cliente diretamente na sua agenda.</p>
        </div>

        <form method="post" action="barbeiro_actions.php" id="bm-form" class="saas-form" data-barbeiro-id="<?= htmlspecialchars($barbeiro_id) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="agendamento_manual">
            <input type="hidden" name="manual_email" id="bm_email" value="">

            <div class="form-group">
                <label class="modern-label">Buscar cliente cadastrado (opcional)</label>
                <div class="custom-dropdown-container" style="position: relative;">
                    <input type="text" id="bm_cliente_input" class="modern-input" placeholder="Digite nome ou telefone..." autocomplete="off">
                    <input type="hidden" name="manual_cliente_id" id="bm_cliente_id">
                    <ul id="bm_cliente_list" class="bm-dropdown-list">
                        <li data-id="" data-nome="" data-telefone="" class="bm-dd-novo"><i class="fa fa-user-plus"></i> Novo cliente / sem cadastro</li>
                        <?php foreach ($clientesArr as $cid => $c): ?>
                            <li data-id="<?= htmlspecialchars($cid) ?>" data-nome="<?= htmlspecialchars($c['nome'] ?? '') ?>" data-telefone="<?= htmlspecialchars($c['telefone'] ?? '') ?>">
                                <strong><?= htmlspecialchars($c['nome'] ?? '') ?></strong>
                                <small><?= htmlspecialchars($c['telefone'] ?? '') ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="bm-two">
                <div class="form-group">
                    <label class="modern-label">Nome do cliente</label>
                    <input type="text" name="manual_nome" id="bm_nome" class="modern-input" required placeholder="Ex: Carlos Silva">
                </div>
                <div class="form-group">
                    <label class="modern-label">Telefone / WhatsApp</label>
                    <input type="tel" name="manual_telefone" id="bm_telefone" class="modern-input" required placeholder="(99) 99999-9999">
                </div>
            </div>

            <div class="form-group">
                <label class="modern-label">Serviços (toque para selecionar)</label>
                <?php if (empty($bm_servicos) && empty($bm_combos)): ?>
                    <p style="color: var(--text-muted); font-size: .85rem; margin: 0;">Você ainda não tem serviços habilitados no seu perfil.</p>
                <?php else: ?>
                    <div id="bm_servicos_container" class="comanda-chips">
                        <?php foreach ($bm_servicos as $sid => $s): ?>
                            <button type="button" class="comanda-chip bm-servico" data-id="<?= htmlspecialchars($sid) ?>">
                                <?= htmlspecialchars($s['nome']) ?> <b>R$ <?= number_format((float)$s['valor'], 2, ',', '.') ?></b>
                            </button>
                        <?php endforeach; ?>
                        <?php foreach ($bm_combos as $cid => $c): ?>
                            <button type="button" class="comanda-chip bm-servico" data-id="<?= htmlspecialchars($cid) ?>">
                                <i class="fa fa-star" style="color:#f59e0b;"></i> <?= htmlspecialchars($c['nome']) ?> <b>R$ <?= number_format((float)$c['valor'], 2, ',', '.') ?></b>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <input type="hidden" name="manual_servicos" id="bm_servicos" required>
            </div>

            <div class="bm-two">
                <div class="form-group">
                    <label class="modern-label">Data</label>
                    <input type="date" name="manual_data" id="bm_data" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label class="modern-label">Horário</label>
                    <input type="text" class="modern-input" value="Selecione abaixo" disabled style="opacity:.7;">
                </div>
            </div>

            <div class="form-group">
                <label class="modern-label">Horários do dia</label>
                <div id="bm_horarios" class="bm-times bm-times-hint">Selecione os serviços e a data para ver os horários.</div>
                <input type="hidden" name="manual_horario" id="bm_horario" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary" style="background: #6366F1; width: 100%;">
                    <i class="fa fa-check"></i> Agendar na minha agenda
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-reagendar" class="modal-overlay">
    <div class="modal-content" style="max-width: 560px;">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-calendar-alt" style="color: #6366F1;"></i> Reagendar Atendimento</h3>
            <p class="modal-subtitle-premium">Escolha uma nova data e horário.</p>
        </div>

        <div class="rg-atual">
            <div><span>Cliente</span><strong id="rg_cliente">—</strong></div>
            <div><span>Data atual</span><strong id="rg_data_atual">—</strong></div>
            <div><span>Serviços</span><strong id="rg_servicos_nomes">—</strong></div>
        </div>

        <form method="post" action="barbeiro_actions.php" id="rg-form" class="saas-form" data-barbeiro-id="<?= htmlspecialchars($barbeiro_id) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="reagendar_agendamento">
            <input type="hidden" name="agendamento_id" id="rg_agendamento_id">
            <input type="hidden" id="rg_servicos_ids">

            <div class="form-group">
                <label class="modern-label">Nova data</label>
                <input type="date" name="reagendar_data" id="rg_data" class="modern-input" required>
            </div>

            <div class="form-group">
                <label class="modern-label">Novo horário</label>
                <div id="rg_horarios" class="bm-times bm-times-hint">Selecione a nova data para ver os horários.</div>
                <input type="hidden" name="reagendar_horario" id="rg_horario" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary" style="background: #6366F1; width: 100%;">
                    <i class="fa fa-check"></i> Salvar reagendamento
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-comanda" class="modal-overlay">
    <div class="modal-content" style="max-width: 560px;">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-receipt" style="color: #0EA5E9;"></i> Comanda do Atendimento</h3>
            <p class="modal-subtitle-premium"><strong id="comanda-cliente" style="color: var(--text-main);"></strong> <span id="comanda-hora"></span></p>
        </div>

        <div id="comanda-loading" style="padding: 30px; text-align: center; color: var(--text-muted);">
            <i class="fa fa-spinner fa-spin"></i> Carregando comanda...
        </div>

        <form method="post" action="barbeiro_actions.php" id="form-comanda" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="fechar_comanda">
            <input type="hidden" name="agendamento_id" id="comanda-agendamento-id">

            <div class="comanda-itens" id="comanda-itens"></div>

            <div class="form-group" id="comanda-extras-wrap" style="margin-top: 16px;">
                <label class="modern-label"><i class="fa fa-plus-circle"></i> Adicionar serviço extra</label>
                <div id="comanda-extras-chips" class="comanda-chips"></div>
            </div>

            <div class="comanda-two" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 8px;">
                <div class="form-group">
                    <label class="modern-label">Gorjeta (R$)</label>
                    <input type="number" step="0.01" min="0" name="gorjeta" id="comanda-gorjeta" class="modern-input" value="0">
                </div>
                <div class="form-group">
                    <label class="modern-label">Forma de pagamento</label>
                    <div class="select-modern-wrapper">
                        <select name="forma_pagamento" id="comanda-forma" class="modern-select">
                            <option value="">Não informado</option>
                            <option value="dinheiro">Dinheiro</option>
                            <option value="pix">PIX</option>
                            <option value="debito">Cartão de débito</option>
                            <option value="credito">Cartão de crédito</option>
                            <option value="outro">Outro</option>
                        </select>
                        <i class="fa fa-money-bill-wave select-icon"></i>
                    </div>
                </div>
            </div>

            <div class="comanda-total-row">
                <span>Total a cobrar</span>
                <strong id="comanda-total">R$ 0,00</strong>
            </div>

            <div class="form-actions" style="margin-top: 16px;">
                <button type="submit" class="btn-primary" style="background: #0EA5E9; width: 100%;">
                    <i class="fa fa-check-double"></i> Fechar comanda e concluir
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-ia-whatsapp" class="modal-overlay">
    <div class="modal-content">
        <button class="modal-close">&times;</button>
        <div class="modal-header-premium">
            <h3 class="modal-title-premium"><i class="fa fa-magic" style="color: #3B82F6;"></i> Assistente IA</h3>
            <p class="modal-subtitle-premium">Gere mensagens perfeitas para <strong id="ia-nome-cliente" style="color: var(--text-main);"></strong>.</p>
        </div>
        
        <div class="form-group">
            <label class="modern-label">Qual o objetivo da mensagem?</label>
            <div class="select-modern-wrapper" style="margin-bottom: 12px;">
                <select id="ia-motivo" class="modern-select" required>
                    <option value="Estou com um atraso de 10 a 15 minutos, pedir desculpas.">Avisar que estou atrasado (10-15 min)</option>
                    <option value="Lembrar o cliente do agendamento de hoje para ele não faltar.">Lembrete de agendamento de hoje</option>
                    <option value="Agradecer a visita de hoje e pedir para ele deixar uma avaliação no sistema.">Agradecer visita e pedir avaliação</option>
                    <option value="Outro assunto (personalizado)">Digitar assunto personalizado...</option>
                </select>
                <i class="fa fa-comment-dots select-icon"></i>
            </div>
            <input type="text" id="ia-motivo-custom" class="modern-input" placeholder="Ex: Avisar que ele esqueceu as chaves na bancada..." style="display: none;">
        </div>
        
        <input type="hidden" id="ia-telefone-cliente">
        
        <button type="button" id="btn-gerar-ia" class="btn-primary" style="background: linear-gradient(135deg, #3B82F6, #8B5CF6); width: 100%; margin-top: 8px;">
            <i class="fa fa-wand-magic-sparkles"></i> Gerar Mensagem Mágica
        </button>

        <div id="ia-resultado-box" style="display: none; margin-top: 24px; background: var(--bg-hover); border: 1px solid var(--border-light); border-radius: var(--radius-md); padding: 20px;">
            <label class="modern-label" style="font-size: 0.8rem; margin-bottom: 8px;">Mensagem Pronta:</label>
            <textarea id="ia-texto-gerado" class="modern-input" style="height: 140px; resize: none; margin-bottom: 16px;"></textarea>
            <a href="#" id="btn-enviar-whatsapp-ia" target="_blank" class="btn-primary" style="background: #10B981; width: 100%; text-decoration: none;">
                <i class="fab fa-whatsapp"></i> Enviar pelo WhatsApp
            </a>
        </div>
    </div>
</div>
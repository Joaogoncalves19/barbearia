<div id="modal-barbeiro" class="modal-overlay">
    <div class="modal-content" style="max-width: 750px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3>
                <div class="modern-modal-icon"><i class="fa fa-user-tie"></i></div>
                <span>Adicionar/Editar Profissional</span>
            </h3>
            <button class="modal-close">&times;</button>
        </div>
        
        <form method="post" enctype="multipart/form-data" style="padding: 25px; max-height: 75vh; overflow-y: auto;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="salvar_barbeiro">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="foto_atual" value="">
            
            <h4 class="section-title"><i class="fa fa-id-card"></i> Informações Principais</h4>
            <div class="two-cols">
                <div class="form-group">
                    <label>Nome Completo</label>
                    <input type="text" name="nome" required class="modern-input" placeholder="Ex: João Silva">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="status-select">
                        <option value="ativo">Ativo (Aparece na Agenda)</option>
                        <option value="inativo">Inativo (Oculto aos clientes)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 10px;">
                <label>Foto de Perfil (max 2MB)</label>
                <input type="file" name="foto" accept="image/jpeg, image/png" style="padding: 12px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; width: 100%; box-sizing: border-box; cursor: pointer;">
            </div>

            <h4 class="section-title"><i class="fa fa-cut"></i> Especialidades</h4>
            <div id="servicos-container-modal-checkbox">
                 <?php if (empty($servicosArr) && empty($combosArr)): ?>
                    <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; border: 1px solid #ffeeba;">
                        <i class="fa fa-exclamation-triangle"></i> Nenhum serviço ou combo cadastrado. Adicione-os primeiro na aba "Serviços".
                    </div>
                <?php else: ?>
                    
                    <h5 style="margin: 0 0 12px 0; color: #64748b; font-size: 0.95rem;">Serviços Individuais</h5>
                    <div class="checkbox-grid" style="margin-bottom: 25px;">
                        <?php foreach ($servicosArr as $servico): ?>
                            <div class="checkbox-card">
                                <input type="checkbox" name="especialidades_ids[]" value="<?= htmlspecialchars($servico['id']) ?>" id="item-barbeiro-<?= htmlspecialchars($servico['id']) ?>">
                                <label for="item-barbeiro-<?= htmlspecialchars($servico['id']) ?>"><?= htmlspecialchars($servico['nome']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!empty($combosArr)): ?>
                        <h5 style="margin: 0 0 12px 0; color: #64748b; font-size: 0.95rem;">Combos Promocionais</h5>
                        <div class="checkbox-grid">
                            <?php foreach ($combosArr as $combo): ?>
                                <div class="checkbox-card" style="border-left: 3px solid #f59e0b;">
                                    <input type="checkbox" name="especialidades_ids[]" value="<?= htmlspecialchars($combo['id']) ?>" id="item-barbeiro-<?= htmlspecialchars($combo['id']) ?>">
                                    <label for="item-barbeiro-<?= htmlspecialchars($combo['id']) ?>"><?= htmlspecialchars($combo['nome']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>

            <h4 class="section-title"><i class="fa fa-lock"></i> Acesso e Pagamento</h4>
            <div class="two-cols">
                <div class="form-group">
                    <label>Usuário de Login</label>
                    <input type="text" name="username" required class="modern-input" placeholder="Ex: joao.barbeiro">
                </div>
                <div class="form-group">
                    <label>Senha de Acesso</label>
                    <input type="password" name="password" class="modern-input" placeholder="Deixe em branco para manter a atual" minlength="8" pattern="(?=.*[A-Za-zÀ-ÿ])(?=.*\d).{8,}" title="No mínimo 8 caracteres, com pelo menos uma letra e um número.">
                    <small class="hint" style="display:block; margin-top:6px; color:#64748b; font-size:.82rem;">Ao definir, use no mínimo 8 caracteres com pelo menos <strong>uma letra</strong> e <strong>um número</strong>. Deixe em branco para manter a senha atual.</small>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 15px;">
                <label>Comissão do Profissional (%)</label>
                <div style="position: relative; max-width: 200px; margin-bottom: 10px;">
                    <i class="fa fa-percent" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                    <input type="number" name="comissao" id="modal_barbeiro_comissao" class="modern-input" placeholder="Ex: 50" min="0" max="100" style="padding-left: 45px;">
                </div>
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; color: #475569; font-size: 0.9rem;">
                    <input type="checkbox" name="comissao_produtos" id="modal_barbeiro_comissao_produtos" value="1" style="width: auto; height: 18px; accent-color: var(--secondary-color);">
                    Aplicar essa comissão também sobre a venda de produtos?
                </label>
            </div>

            <div class="subscription-commission-box">
                <div class="subscription-commission-heading">
                    <span class="subscription-commission-icon"><i class="fa fa-crown"></i></span>
                    <div>
                        <strong>Comissão em atendimentos de assinatura</strong>
                        <small>Defina como este profissional recebe pelos serviços incluídos nos planos.</small>
                    </div>
                </div>
                <div class="two-cols subscription-commission-fields">
                    <div class="form-group">
                        <label for="modal_barbeiro_comissao_assinatura_tipo">Forma de cálculo</label>
                        <select name="comissao_assinatura_tipo" id="modal_barbeiro_comissao_assinatura_tipo" class="status-select">
                            <option value="padrao">Comissão normal sobre o valor de tabela</option>
                            <option value="percentual">Percentual exclusivo sobre o valor de tabela</option>
                            <option value="fixo">Valor fixo por atendimento concluído</option>
                            <option value="nenhuma">Sem comissão para assinatura</option>
                        </select>
                    </div>
                    <div class="form-group" id="comissao-assinatura-valor-group" hidden>
                        <label for="modal_barbeiro_comissao_assinatura_valor" id="comissao-assinatura-valor-label">Percentual exclusivo (%)</label>
                        <input type="number" name="comissao_assinatura_valor" id="modal_barbeiro_comissao_assinatura_valor" class="modern-input" min="0" step="0.01" value="0">
                    </div>
                </div>
                <p class="subscription-commission-hint" id="comissao-assinatura-hint">
                    A comissão normal será calculada sobre o preço de tabela dos serviços, mesmo quando o cliente não pagar por eles no atendimento.
                </p>

                <div class="subscription-commission-preview" aria-live="polite">
                    <span><i class="fa fa-calculator"></i> Exemplo com um serviço de R$ 50,00</span>
                    <strong id="comissao-assinatura-preview">Informe a comissão normal do profissional para visualizar.</strong>
                </div>

                <div class="subscription-commission-guide" aria-label="Explicação das formas de comissão">
                    <div class="subscription-guide-item" data-commission-guide="padrao">
                        <i class="fa fa-percent"></i>
                        <div>
                            <strong>Comissão normal</strong>
                            <p>Usa a mesma porcentagem definida acima para os atendimentos avulsos, mas calcula sobre o preço de tabela do serviço coberto pelo plano.</p>
                        </div>
                    </div>
                    <div class="subscription-guide-item" data-commission-guide="percentual">
                        <i class="fa fa-sliders-h"></i>
                        <div>
                            <strong>Percentual exclusivo</strong>
                            <p>Permite definir uma porcentagem menor ou maior somente para serviços de assinatura. A comissão dos atendimentos avulsos não muda.</p>
                        </div>
                    </div>
                    <div class="subscription-guide-item" data-commission-guide="fixo">
                        <i class="fa fa-coins"></i>
                        <div>
                            <strong>Valor fixo</strong>
                            <p>Paga sempre o mesmo valor uma vez por agendamento de assinatura concluído, independentemente da quantidade ou do preço dos serviços.</p>
                        </div>
                    </div>
                    <div class="subscription-guide-item" data-commission-guide="nenhuma">
                        <i class="fa fa-ban"></i>
                        <div>
                            <strong>Sem comissão</strong>
                            <p>Os serviços cobertos pelo plano não geram comissão. Produtos vendidos continuam seguindo a configuração de produtos acima.</p>
                        </div>
                    </div>
                </div>

                <div class="subscription-commission-note">
                    <i class="fa fa-info-circle"></i>
                    A regra é aplicada somente quando o atendimento de assinatura for concluído.
                </div>
            </div>

            <button type="submit" class="btn-primary">
                <i class="fa fa-save"></i> Salvar Profissional
            </button>
        </form>
    </div>
</div>

<div id="modal-barbeiro-detalhes" class="modal-overlay">
    <div class="modal-content" style="max-width: 900px; padding: 0; overflow: hidden; background: #f8fafc;">
        <div class="modern-modal-header">
            <h3>
                <div class="modern-modal-icon"><i class="fa fa-id-badge"></i></div>
                Perfil Analítico do Profissional
                <span id="barbeiro-detalhes-nome" style="display:none;"></span>
            </h3>
            <button class="modal-close">&times;</button>
        </div>
        <div id="barbeiro-detalhes-content" style="max-height: 75vh; overflow-y: auto; padding: 30px;">
            <!-- Renderizado via AJAX -->
        </div>
    </div>
</div>


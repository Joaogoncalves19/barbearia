<div id="modal-comanda" class="modal-overlay">
    <div class="modal-content" style="max-width: 560px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-blue"><i class="fa fa-receipt"></i></div> <span>Comanda — <span id="comanda-cliente"></span> <span id="comanda-hora" style="color:#94a3b8;font-weight:500;"></span></span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div style="padding: 24px;">
            <div id="comanda-loading" style="padding: 24px; text-align: center; color: #94a3b8;">
                <i class="fa fa-spinner fa-spin"></i> Carregando comanda...
            </div>
            <form method="post" action="admin.php" id="form-comanda" style="display: none;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="fechar_comanda">
                <input type="hidden" name="agendamento_id" id="comanda-agendamento-id">

                <div class="comanda-itens" id="comanda-itens"></div>

                <div class="form-group" id="comanda-extras-wrap" style="margin-top: 16px;">
                    <label><i class="fa fa-plus-circle"></i> Adicionar serviço extra</label>
                    <div id="comanda-extras-chips" class="comanda-chips"></div>
                </div>

                <div class="two-cols">
                    <div class="form-group">
                        <label>Gorjeta (R$)</label>
                        <input type="number" step="0.01" min="0" name="gorjeta" id="comanda-gorjeta" class="modern-input" value="0">
                    </div>
                    <div class="form-group">
                        <label>Forma de pagamento</label>
                        <select name="forma_pagamento" id="comanda-forma" class="status-select">
                            <option value="">Não informado</option>
                            <option value="dinheiro">Dinheiro</option>
                            <option value="pix">PIX</option>
                            <option value="debito">Cartão de débito</option>
                            <option value="credito">Cartão de crédito</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                </div>

                <div class="comanda-total-row">
                    <span>Total a cobrar</span>
                    <strong id="comanda-total">R$ 0,00</strong>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 16px;">
                    <i class="fa fa-check-double"></i> Fechar comanda e concluir
                </button>
            </form>
        </div>
    </div>
</div>

<div id="modal-ausencias" class="modal-overlay">
    <div class="modal-content" style="max-width: 560px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon icon-blue"><i class="fa fa-umbrella-beach"></i></div> <span>Folgas &amp; Férias — <span id="ausencia-barbeiro-nome"></span></span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div style="padding: 24px;">
            <form method="post" action="admin.php" style="margin-bottom: 20px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="salvar_ausencia">
                <input type="hidden" name="ausencia_barbeiro_id" id="ausencia_barbeiro_id" value="">
                <div class="two-cols">
                    <div class="form-group">
                        <label>Início</label>
                        <input type="date" name="ausencia_data_inicio" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Fim</label>
                        <input type="date" name="ausencia_data_fim" class="modern-input" required>
                    </div>
                </div>
                <div class="two-cols">
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="ausencia_tipo" class="status-select">
                            <option value="folga">Folga</option>
                            <option value="ferias">Férias</option>
                            <option value="atestado">Atestado/Afastamento</option>
                            <option value="outro">Indisponível</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Motivo (opcional)</label>
                        <input type="text" name="ausencia_motivo" class="modern-input" placeholder="Ex: Férias anuais">
                    </div>
                </div>
                <button type="submit" class="btn-primary"><i class="fa fa-plus"></i> Adicionar período</button>
            </form>
            <h4 style="margin: 0 0 10px; color: #334155; font-size: .85rem;">Períodos registrados</h4>
            <div id="ausencias-lista"></div>
            <p style="margin-top: 14px; color: #94a3b8; font-size: .72rem;">Nesses dias a agenda do profissional fica indisponível para novos agendamentos.</p>
        </div>
    </div>
</div>


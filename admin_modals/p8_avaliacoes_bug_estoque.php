<div id="modal-responder-avaliacao" class="modal-overlay">
    <div class="modal-content" style="max-width: 600px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-reply"></i></div> <span>Responder Avaliação</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div style="padding: 25px 25px 0;">
            <div style="background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 5px; text-transform: uppercase; font-weight: 700;">Avaliação do Cliente: <span id="modal_review_stars" style="color: #f59e0b; margin-left: 5px;"></span></div>
                <div id="modal_review_text_display" style="font-style: italic; color: #334155; font-size: 0.95rem;"></div>
            </div>
            <form method="post" action="admin.php" style="padding-bottom: 25px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="salvar_resposta_avaliacao">
                <input type="hidden" name="id_avaliacao" id="modal_resposta_id_avaliacao">
                <input type="hidden" id="modal_review_text_hidden">
                <input type="hidden" id="modal_review_rating_hidden">
                <div class="form-group" style="position: relative;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label for="texto_resposta" style="margin: 0;">Sua Resposta Pública</label>
                        <button type="button" id="btn_ia_responder_av" style="background: #f5f3ff; color: #8b5cf6; border: 1px solid #ddd6fe; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: 0.2s;">
                            <i class="fa fa-magic"></i> Gerar Resposta IA
                        </button>
                    </div>
                    <textarea name="texto_resposta" id="modal_texto_resposta" rows="5" class="modern-input" style="resize: vertical;" required placeholder="Escreva sua resposta aqui ou use o botão da IA acima..."></textarea>
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fa fa-paper-plane"></i> Publicar Resposta
                </button>
            </form>
        </div>
    </div>
</div>

<div id="modal-ver-agendamento" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-calendar-check"></i></div> <span>Detalhes do Agendamento</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div id="modal-agendamento-detalhes-content" style="padding: 25px;">
            <p>Carregando...</p>
        </div>
    </div>
</div>

<div id="modal-reportar-bug" class="modal-overlay">
    <div class="modal-content sup-modal" style="max-width: 560px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-headset"></i></div> <span>Central de Suporte</span></h3>
            <button class="modal-close">&times;</button>
        </div>

        <form method="post" action="admin.php" id="form-suporte" style="padding: 24px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="reportar_bug">
            <input type="hidden" name="contexto" id="sup-contexto" value="">

            <p class="sup-intro">
                Fale direto com o desenvolvedor: reporte um problema, sugira uma melhoria ou tire uma dúvida.
            </p>

            <label class="sup-block-label">O que você precisa?</label>
            <div class="sup-types">
                <label class="sup-type">
                    <input type="radio" name="tipo_mensagem" value="Erro / Bug" data-ph="Descreva o problema: em qual tela ocorreu, o que você fez e o que aconteceu de errado." checked>
                    <span class="sup-type-inner">
                        <i class="fa fa-bug"></i>
                        <b>Reportar Bug</b>
                        <small>Algo não funciona</small>
                    </span>
                </label>
                <label class="sup-type">
                    <input type="radio" name="tipo_mensagem" value="Sugestão de Melhoria" data-ph="Conte sua ideia: o que gostaria que o sistema fizesse e como isso ajudaria no seu dia a dia.">
                    <span class="sup-type-inner">
                        <i class="fa fa-lightbulb"></i>
                        <b>Sugestão</b>
                        <small>Ideia de melhoria</small>
                    </span>
                </label>
                <label class="sup-type">
                    <input type="radio" name="tipo_mensagem" value="Dúvida Geral" data-ph="Escreva sua dúvida com o máximo de detalhes para respondermos com precisão.">
                    <span class="sup-type-inner">
                        <i class="fa fa-circle-question"></i>
                        <b>Dúvida</b>
                        <small>Preciso de ajuda</small>
                    </span>
                </label>
            </div>

            <div class="form-group">
                <label class="sup-field-label">E-mail ou WhatsApp para retorno</label>
                <input type="text" name="contato_retorno" class="modern-input" required placeholder="Como podemos te responder?">
            </div>

            <div class="form-group">
                <label class="sup-field-label">Sua mensagem</label>
                <textarea name="mensagem" id="sup-mensagem" class="modern-input" rows="5" required style="resize: vertical;"
                    placeholder="Descreva o problema: em qual tela ocorreu, o que você fez e o que aconteceu de errado."></textarea>
            </div>

            <label class="sup-diag">
                <input type="checkbox" id="sup-incluir-contexto" checked>
                <span>Anexar informações técnicas (tela atual, navegador) para agilizar o diagnóstico</span>
            </label>

            <button type="submit" class="btn-primary sup-submit">
                <i class="fa fa-paper-plane"></i> Enviar para o suporte
            </button>

            <div class="sup-quick">
                <span>Prefere falar direto?</span>
                <div class="sup-quick-links">
                    <a href="mailto:john.goncalves06@gmail.com" class="sup-quick-link"><i class="fa fa-envelope"></i> E-mail</a>
                    <a href="https://instagram.com/ggxuao" target="_blank" rel="noopener noreferrer" class="sup-quick-link"><i class="fa-brands fa-instagram"></i> Instagram</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('form-suporte');
    if (!form) return;
    var textarea = document.getElementById('sup-mensagem');

    // Atualiza o placeholder conforme o tipo escolhido
    form.querySelectorAll('input[name="tipo_mensagem"]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (textarea && r.dataset.ph) textarea.placeholder = r.dataset.ph;
        });
    });

    // Monta o contexto técnico ao enviar (se o usuário permitir)
    form.addEventListener('submit', function () {
        var incluir = document.getElementById('sup-incluir-contexto');
        var campo = document.getElementById('sup-contexto');
        if (!campo) return;
        if (incluir && incluir.checked) {
            var abaAtiva = (document.querySelector('.tabcontent.active') || {}).id || '—';
            campo.value =
                'Página: ' + location.href + '\n' +
                'Aba ativa: ' + abaAtiva + '\n' +
                'Tela: ' + window.screen.width + 'x' + window.screen.height +
                ' (janela ' + window.innerWidth + 'x' + window.innerHeight + ')\n' +
                'Navegador: ' + navigator.userAgent;
        } else {
            campo.value = '';
        }
    });
})();
</script>

<div id="modal-movimentar-estoque" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px; padding: 0; overflow: hidden;">
        <div class="modern-modal-header">
            <h3><div class="modern-modal-icon"><i class="fa fa-exchange-alt"></i></div> <span>Movimentar Estoque</span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="post" action="admin.php" style="padding: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="movimentar_estoque">
            <input type="hidden" name="produto_id" id="mov_produto_id" value="">
            
            <div style="background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px dashed #cbd5e1; margin-bottom: 20px; text-align: center;">
                <strong style="color: #1e293b; font-size: 1.1rem; display: block;" id="mov_produto_nome">-</strong>
            </div>
            <div class="two-cols">
                <div class="form-group">
                    <label>Tipo de Movimentação</label>
                    <select name="tipo_movimentacao" class="modern-input" required>
                        <option value="entrada">Entrada (Adicionar ao estoque)</option>
                        <option value="saida">Saída (Retirar do estoque)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantidade</label>
                    <input type="number" name="quantidade_mov" min="1" value="1" class="modern-input" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Motivo / Observação</label>
                <input type="text" name="motivo" class="modern-input" required placeholder="Ex: Compra de fornecedor, Descarte, Uso interno">
            </div>
            
            <button type="submit" class="btn-primary" style="background: #0ea5e9;">
                <i class="fa fa-save"></i> Registrar Movimentação
            </button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Escuta cliques no botão de movimentar estoque
    document.body.addEventListener('click', function(e) {
        const btnMovimentar = e.target.closest('button[data-type="movimentar_estoque"]');
        if (btnMovimentar) {
            setTimeout(() => {
                document.getElementById('mov_produto_id').value = btnMovimentar.dataset.id || '';
                document.getElementById('mov_produto_nome').innerText = btnMovimentar.dataset.nome || 'Produto Desconhecido';
            }, 50);
        }
    });

    // 2. Escuta cliques para abrir o Modal de Responder Avaliação e injeta os dados do comentário
    document.body.addEventListener('click', function(e) {
        const btnResponder = e.target.closest('button[data-modal-target="#modal-responder-avaliacao"]');
        if (btnResponder) {
            const comment = btnResponder.dataset.review_text || '(Avaliação sem texto)';
            const rating = parseInt(btnResponder.dataset.review_rating) || 5;

            // Preenche o visual
            document.getElementById('modal_review_text_display').innerText = '"' + comment + '"';
            document.getElementById('modal_review_stars').innerText = '★'.repeat(rating) + '☆'.repeat(5 - rating);

            // Preenche os campos ocultos para a IA saber o que responder
            document.getElementById('modal_review_text_hidden').value = comment;
            document.getElementById('modal_review_rating_hidden').value = rating;
        }
    });

    // 3. Lógica do Botão de Inteligência Artificial para Responder Avaliação
    const btnIaResponder = document.getElementById('btn_ia_responder_av');
    if (btnIaResponder) {
        btnIaResponder.addEventListener('click', async function() {
            const comment = document.getElementById('modal_review_text_hidden').value;
            const rating = document.getElementById('modal_review_rating_hidden').value;
            const textarea = document.getElementById('modal_texto_resposta');

            // Feedback visual no botão e na área de texto
            const originalText = btnIaResponder.innerHTML;
            btnIaResponder.disabled = true;
            btnIaResponder.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Gerando...';
            textarea.value = 'A Inteligência Artificial está lendo a avaliação e escrevendo a resposta...';

            try {
                // Envia para o nosso arquivo de integração com a API do Gemini
                const response = await fetch('ajax_gemini.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'responder_avaliacao',
                        comentario: comment,
                        nota: rating
                    })
                });

                const data = await response.json();
                
                if (data.success) {
                    textarea.value = data.resposta;
                } else {
                    textarea.value = '';
                    alert('Erro ao gerar resposta com IA: ' + data.error);
                }
            } catch (error) {
                textarea.value = '';
                alert('Erro de conexão ao tentar gerar a resposta. Tente novamente.');
                console.error(error);
            } finally {
                // Restaura o botão
                btnIaResponder.disabled = false;
                btnIaResponder.innerHTML = originalText;
            }
        });
        
        // Efeito de hover no botão da IA para ficar mais dinâmico
        btnIaResponder.addEventListener('mouseover', function() {
            if(!this.disabled) {
                this.style.background = '#ede9fe';
                this.style.transform = 'translateY(-1px)';
            }
        });
        btnIaResponder.addEventListener('mouseout', function() {
            if(!this.disabled) {
                this.style.background = '#f5f3ff';
                this.style.transform = 'none';
            }
        });
    }

});
</script>

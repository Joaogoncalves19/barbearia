<div id="avaliacoes" class="tabcontent">
    <h3 class="tabcontent-title"><i class="fa fa-comment-dots" style="color: var(--app-accent);"></i> Minhas Avaliações</h3>
    <?php if(empty($minhas_avaliacoes)): ?>
        <div style="text-align:center; padding: 60px 20px; background: var(--surface-2); border-radius: 20px; border: 2px dashed var(--border-strong);">
            <i class="fa fa-star-half-alt" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 20px;"></i>
            <h4 style="margin: 0 0 10px; color: var(--text-main); font-size: 1.2rem;">Nenhuma avaliação ainda</h4>
            <p style="color: var(--text-muted); font-size: 1rem; margin: 0;">Avalie seus agendamentos concluídos para ajudar nossos profissionais.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 20px;">
        <?php foreach(array_reverse($minhas_avaliacoes) as $av): ?>
        <div class="avaliacao-card" style="background: var(--card-bg); border: 1px solid var(--border-soft); border-radius: 20px; padding: 30px; box-shadow: var(--shadow-soft); transition: transform 0.3s; position: relative; overflow: hidden;">
            <div style="display:flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <img src="<?= htmlspecialchars($barbeirosArr[$av['barbeiro_id']]['foto'] ?? 'uploads/default-profile.jpg') ?>" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9;">
                    <div>
                        <strong style="font-size: 1.2rem; color: var(--text-main); font-weight: 800; display: block;"><?= htmlspecialchars($barbeirosArr[$av['barbeiro_id']]['nome'] ?? 'Profissional') ?></strong>
                        <span style="color: #94a3b8; font-size: 0.85rem; font-weight: 600;"><i class="fa fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($av['timestamp'])) ?></span>
                    </div>
                </div>
                <div style="background: #fffbeb; padding: 6px 12px; border-radius: 10px; border: 1px solid #fde68a;">
                    <span style="color: #fbbf24; font-size: 1.1rem; letter-spacing: 2px;"><?= str_repeat('★', $av['rating']) . str_repeat('☆', 5 - $av['rating']) ?></span>
                </div>
            </div>
            <?php if(!empty($av['comment'])): ?>
                <p style="font-style: italic; color: var(--text-muted); margin: 0 0 20px; font-size: 1.05rem; line-height: 1.6;">"<?= htmlspecialchars($av['comment']) ?>"</p>
            <?php else: ?>
                <p style="font-style: italic; color: #cbd5e1; margin: 0 0 20px; font-size: 1rem;">Sem comentário escrito.</p>
            <?php endif; ?>
            
            <?php if(isset($respostasAvaliacoesArr[$av['id']])): 
                $resposta = $respostasAvaliacoesArr[$av['id']]; ?>
            <div style="background: var(--surface-2); padding: 20px; border-radius: 14px; border-left: 4px solid var(--app-accent); position: relative;">
                <i class="fa fa-reply" style="position: absolute; top: 20px; right: 20px; color: #cbd5e1; font-size: 1.5rem;"></i>
                <strong style="color: var(--text-main); font-size: 0.95rem; display: block; margin-bottom: 8px;">Resposta da Barbearia:</strong>
                <p style="margin: 0; color: var(--text-muted); font-size: 1rem; line-height: 1.5;">"<?= htmlspecialchars($resposta['texto_resposta']) ?>"</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
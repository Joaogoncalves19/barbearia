<div id="fidelidade" class="tabcontent">
    <h3 class="tabcontent-title"><i class="fa fa-star" style="color: var(--app-accent);"></i> Programa de Fidelidade</h3>
    
    <?php if($assinaturaAtiva && $assinaturaAtiva['status'] === 'ativo'): ?>
    <div style="background: #fef9c3; color: #854d0e; padding: 20px 25px; border-radius: 16px; border: 1px solid #fde047; margin-bottom: 30px; display: flex; align-items: center; gap: 15px;">
        <i class="fa fa-exclamation-triangle" style="font-size: 2rem; color: #ca8a04;"></i>
        <div>
            <strong style="font-size: 1.1rem;">Aviso Importante:</strong>
            <p style="margin: 5px 0 0; font-size: 0.95rem;">Como você possui uma barbearia por assinatura ativa, seus agendamentos não geram novos pontos de fidelidade (o benefício já está aplicado no seu plano).</p>
        </div>
    </div>
    <?php endif; ?>

    <div style="display: flex; gap: 25px; flex-wrap: wrap; margin-bottom: 40px;">
        <div style="flex: 1; min-width: 250px; background: linear-gradient(135deg, #1e293b, #0f172a); padding: 40px; border-radius: 24px; text-align: center; color: white; box-shadow: 0 15px 30px -10px rgba(15, 23, 42, 0.4); position: relative; overflow: hidden;">
             <i class="fa fa-star" style="position: absolute; font-size: 10rem; opacity: 0.05; right: -20px; top: -20px; transform: rotate(15deg);"></i>
             <h4 style="margin: 0 0 15px; color: #94a3b8; font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Seus Pontos Atuais</h4>
             <div style="font-size: 5rem; font-weight: 900; color: var(--app-accent); line-height: 1; filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.3));"><?= $pontos_fidelidade ?></div>
             
             <?php 
                // Calcula a porcentagem para a barra de progresso (limita em 100%)
                $percentual_fidelidade = min(100, ($pontos_fidelidade / max(1, $pontos_necessarios)) * 100); 
             ?>
             <div style="background: rgba(255,255,255,0.1); border-radius: 10px; height: 12px; width: 100%; overflow: hidden; margin-top: 25px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.3);">
                 <div style="background: var(--app-accent); height: 100%; width: <?= $percentual_fidelidade ?>%; transition: width 0.5s ease-in-out;"></div>
             </div>
             <p style="text-align: right; font-size: 0.85rem; color: #94a3b8; margin: 5px 0 0; font-weight: 600;"><?= $pontos_fidelidade ?> / <?= $pontos_necessarios ?> pontos</p>
        </div>
        <div style="flex: 1.5; min-width: 300px; background: var(--surface-2); padding: 35px; border-radius: 24px; border: 1px solid var(--border-soft);">
            <?php
                $regrasFid = function_exists('getFidelidadeRegras') ? getFidelidadeRegras() : [
                    'modo_ganho' => 'visita', 'pontos_por_visita' => 1, 'real_por_ponto' => 0,
                    'tipo_recompensa' => 'percentual', 'desconto_percentual' => ($config_fidelidade['desconto_percentual'] ?? 50),
                    'valor_desconto_fixo' => 0,
                ];
                if ($regrasFid['modo_ganho'] === 'valor' && $regrasFid['real_por_ponto'] > 0) {
                    $textoGanho = 'Ganhe <strong>1 ponto</strong> a cada <strong>R$ ' . number_format($regrasFid['real_por_ponto'], 2, ',', '.') . '</strong> gastos.';
                } else {
                    $pv = (int)$regrasFid['pontos_por_visita'];
                    $textoGanho = 'Ganhe <strong>' . $pv . ' ponto' . ($pv > 1 ? 's' : '') . '</strong> a cada visita concluída.';
                }
                if ($regrasFid['tipo_recompensa'] === 'valor_fixo') {
                    $textoRecompensa = 'Ganhe <strong>R$ ' . number_format($regrasFid['valor_desconto_fixo'], 2, ',', '.') . ' de desconto</strong> no próximo agendamento!';
                } elseif ($regrasFid['tipo_recompensa'] === 'servico_gratis') {
                    $textoRecompensa = 'Ganhe um <strong>serviço grátis</strong> no próximo agendamento!';
                } else {
                    $textoRecompensa = 'Ganhe <strong>' . (float)$regrasFid['desconto_percentual'] . '% de desconto</strong> no próximo agendamento!';
                }
            ?>
            <h4 style="margin: 0 0 20px; color: var(--text-main); font-size: 1.3rem; font-weight: 800;">Como funciona o Clube?</h4>
            <ul style="list-style: none; padding: 0; margin: 0; color: var(--text-muted); font-size: 1.05rem; font-weight: 500; display: flex; flex-direction: column; gap: 15px;">
                <li style="display: flex; align-items: center; gap: 15px;"><div style="background: #dcfce7; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #16a34a; font-size: 1.2rem;"><i class="fa fa-check"></i></div> <span><?= $textoGanho ?></span></li>
                <li style="display: flex; align-items: center; gap: 15px;"><div style="background: #fef3c7; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #d97706; font-size: 1.2rem;"><i class="fa fa-star"></i></div> Junte <strong><?= $pontos_necessarios ?> pontos</strong>.</li>
                <li style="display: flex; align-items: center; gap: 15px;"><div style="background: #e0f2fe; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #0284c7; font-size: 1.2rem;"><i class="fa fa-gift"></i></div> <span><?= $textoRecompensa ?></span></li>
            </ul>
        </div>
    </div>

    <h4 style="color: var(--text-main); font-size: 1.3rem; font-weight: 800; margin-bottom: 25px;">Extrato de Pontos</h4>
    <?php if(empty($historico_pontos)): ?>
        <div style="text-align: center; color: #94a3b8; padding: 40px; background: var(--surface-2); border-radius: 16px; border: 2px dashed var(--border-soft); font-weight: 500;">Nenhum histórico de pontos encontrado.</div>
    <?php else: ?>
        <ul class="timeline-modern" id="lista-historico-pontos">
            <?php 
            $historico_reverso = array_reverse($historico_pontos);
            foreach($historico_reverso as $index => $h): 
                $pontos = (int)$h['pontos'];
                $sinal = ($pontos > 0) ? '+' : '';
                $classeCor = ($pontos > 0) ? 'positive' : 'negative';
                
                $icone = 'fa-info-circle';
                $classeIcone = 'neutral';
                $desc = strtolower($h['descricao']);
                
                if (strpos($desc, 'agendamento') !== false) { 
                    $icone = 'fa-cut'; $classeIcone = 'positive';
                } elseif (strpos($desc, 'resgate') !== false) {
                    $icone = 'fa-ticket-alt'; $classeIcone = 'negative';
                } elseif (strpos($desc, 'indicou') !== false || strpos($desc, 'indicação') !== false) {
                    $icone = 'fa-user-friends'; $classeIcone = 'positive';
                } elseif (strpos($desc, 'admin') !== false || strpos($desc, 'ajuste') !== false) {
                    $icone = 'fa-user-cog'; $classeIcone = ($pontos > 0) ? 'positive' : 'negative';
                }
            ?>
            <li class="timeline-item historico-pontos-item" style="<?= $index >= 5 ? 'display:none;' : '' ?>">
                <div class="timeline-icon <?= $classeIcone ?>"><i class="fa <?= $icone ?>"></i></div>
                <div class="timeline-content">
                    <div class="timeline-header">
                        <span class="timeline-date"><i class="fa fa-calendar-alt" style="margin-right: 5px;"></i> <?= date('d/m/Y H:i', strtotime($h['timestamp'])) ?></span>
                        <span class="timeline-points <?= $classeCor ?>"><?= $sinal . $pontos ?> pts</span>
                    </div>
                    <p class="timeline-desc"><?= htmlspecialchars($h['descricao']) ?></p>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        
        <?php if(count($historico_pontos) > 5): ?>
        <div style="text-align: center; margin-top: 20px;">
            <button id="btn-carregar-mais-pontos" class="btn" style="background: var(--card-bg); border: 1px solid var(--border-strong); color: var(--text-main); border-radius: 12px; padding: 12px 25px; font-weight: 700; box-shadow: 0 2px 5px rgba(0,0,0,0.02);"><i class="fa fa-chevron-down" style="margin-right: 8px;"></i> Ver mais antigos</button>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
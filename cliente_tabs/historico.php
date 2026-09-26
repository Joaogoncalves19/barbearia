<div id="historico" class="tabcontent active">
    <h3 class="tabcontent-title"><i class="fa fa-history" style="color: var(--app-accent);"></i> Histórico de Agendamentos</h3>
    <?php if (empty($agendamentosHistorico)): ?>
        <div style="text-align:center; padding: 60px 20px; background: var(--surface-2); border-radius: 20px; border: 2px dashed var(--border-strong);">
            <i class="fa fa-calendar-times" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 20px;"></i>
            <h4 style="margin: 0 0 10px; color: var(--text-main); font-size: 1.2rem;">Nenhum agendamento passado</h4>
            <p style="color: var(--text-muted); font-size: 1rem; margin: 0;">Seu histórico de cortes e serviços aparecerá aqui.</p>
        </div>
    <?php else: ?>
        <div class="historico-filtros" style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:22px;">
            <div style="position:relative; flex:1 1 240px; min-width:200px;">
                <i class="fa fa-search" style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text-faint);"></i>
                <input type="text" id="historico-busca" placeholder="Buscar por serviço, barbeiro ou ID..." autocomplete="off"
                       style="width:100%; padding:12px 16px 12px 42px; border-radius:12px; border:1px solid var(--border-strong); background:var(--surface-2); color:var(--text-main); font-family:'Inter',sans-serif; font-size:0.95rem; box-sizing:border-box;">
            </div>
            <select id="historico-filtro-status"
                    style="flex:0 0 auto; padding:12px 16px; border-radius:12px; border:1px solid var(--border-strong); background:var(--surface-2); color:var(--text-main); font-family:'Inter',sans-serif; font-weight:600; font-size:0.95rem; cursor:pointer;">
                <option value="">Todos os status</option>
                <option value="concluido">Concluídos</option>
                <option value="cancelado">Cancelados</option>
                <option value="aprovado">Confirmados</option>
                <option value="pendente">Pendentes</option>
            </select>
        </div>
        <div id="historico-sem-resultados" style="display:none; text-align:center; padding:40px 20px; color:var(--text-muted);">
            <i class="fa fa-filter-circle-xmark" style="font-size:2.5rem; color:var(--text-faint); margin-bottom:12px;"></i>
            <p style="margin:0; font-weight:600;">Nenhum agendamento corresponde à sua busca.</p>
        </div>
        <div id="lista-historico">
        <?php foreach($agendamentosHistorico as $index => $ag):
            $statusClass = 'status-'.($ag['status'] === 'cancelado_pelo_cliente' ? 'cancelado' : $ag['status']);
            $statusFiltro = ($ag['status'] === 'cancelado_pelo_cliente') ? 'cancelado' : $ag['status'];

            $servicosNomes = [];
            foreach(explode(',', $ag['servicos_ids']) as $sid) {
                $sid = trim($sid);
                if(isset($servicosArr[$sid])) $servicosNomes[] = $servicosArr[$sid]['nome'];
                elseif(isset($combosArr[$sid])) $servicosNomes[] = $combosArr[$sid]['nome']." (Combo)";
            }

            $valorTotalAg = 0;
            foreach(explode(',', $ag['servicos_ids']) as $sid) {
                $sid = trim($sid);
                if(isset($servicosArr[$sid])) $valorTotalAg += (float)$servicosArr[$sid]['valor'];
                elseif(isset($combosArr[$sid])) $valorTotalAg += (float)$combosArr[$sid]['valor'];
            }
            if(!empty($ag['produtos_vendidos'])) {
                $prods = json_decode($ag['produtos_vendidos'], true);
                if(is_array($prods)) foreach($prods as $p) $valorTotalAg += (float)$p['valor'];
            }
            
            // ADICIONADO LÓGICA DO PLANO
            if ($ag['tipo_desconto'] === 'adesao_plano' && !empty($ag['plano_provisorio'])) {
                if (isset($planosArr[$ag['plano_provisorio']])) {
                    $valorTotalAg += (float)$planosArr[$ag['plano_provisorio']]['valor'];
                    $servicosNomes[] = "Assinatura: " . $planosArr[$ag['plano_provisorio']]['nome'];
                }
            }

            $desconto = (float)($ag['desconto_aplicado'] ?? 0);
            $valorFinal = $valorTotalAg - $desconto;
            if ($valorFinal < 0) $valorFinal = 0;
        ?>
        <?php
            $buscaHistorico = mb_strtolower(trim(implode(' ', $servicosNomes) . ' ' . ($barbeirosArr[$ag['barbeiro_id']]['nome'] ?? '') . ' ' . $ag['id']));
        ?>
        <div class="appt-card historico-item" data-idx="<?= $index ?>" data-status="<?= htmlspecialchars($statusFiltro) ?>" data-search="<?= htmlspecialchars($buscaHistorico) ?>" style="<?= $index >= 5 ? 'display:none;' : '' ?>">
            <div class="appt-date">
                <span class="appt-day"><?= date('d', strtotime($ag['data'])) ?></span>
                <span class="appt-month"><?= mesAbrevPt((int)date('n', strtotime($ag['data']))) ?></span>
                <div class="appt-year"><?= date('Y', strtotime($ag['data'])) ?></div>
            </div>
            <div class="appt-details">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; flex-wrap: wrap; gap: 10px;">
                    <strong style="font-size:1.2rem; color:var(--text-main); font-weight: 800;"><?= implode(', ', $servicosNomes) ?></strong>
                    <span class="appt-status <?= $statusClass ?>"><?= ucfirst(str_replace(['_', 'cancelado_pelo_cliente'], [' ', 'Cancelado'], $ag['status'])) ?></span>
                </div>
                <div style="font-size: 1rem; color: var(--text-muted); font-weight: 500; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa fa-clock" style="color: #94a3b8;"></i> <?= $ag['hora'] ?> &bull; 
                    <img src="<?= htmlspecialchars($barbeirosArr[$ag['barbeiro_id']]['foto'] ?? 'uploads/default-profile.jpg') ?>" style="width:24px; height:24px; border-radius:50%; object-fit:cover;">
                    <strong><?= $barbeirosArr[$ag['barbeiro_id']]['nome'] ?? 'Barbeiro' ?></strong>
                </div>
                <div style="font-size: 1rem; background: var(--surface-2); padding: 10px 15px; border-radius: 10px; display: inline-block; border: 1px solid var(--border-soft);">
                    <span style="font-weight:800; color:var(--text-main);">R$ <?= number_format($valorFinal, 2, ',', '.') ?></span>
                    <span style="font-size: 0.85rem; color: #94a3b8; margin-left: 10px; font-weight: 600;">ID: <?= htmlspecialchars($ag['id']) ?></span>
                    <?php if($desconto > 0): ?>
                        <span style="color:#10b981; font-size: 0.9rem; margin-left: 15px; font-weight: 700;"><i class="fa fa-tags"></i> Economia: R$ <?= number_format($desconto, 2, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="appt-actions-container">
                <button class="btn btn-detalhes" style="padding: 10px 20px; border-radius: 12px; background: var(--card-bg); color: var(--text-main); border: 1px solid var(--border-strong); font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.02);" data-modal-target="#modal-detalhes-agendamento" data-agendamento-id="<?= $ag['id'] ?>" title="Ver Detalhes"><i class="fa fa-eye"></i> Detalhes</button>
                
                <a href="agendamento?barbeiro=<?= $ag['barbeiro_id'] ?>&servicos=<?= urlencode($ag['servicos_ids']) ?>" class="btn" style="padding: 10px 20px; border-radius: 12px; background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.02); text-align: center; text-decoration: none;" title="Agendar este serviço novamente com o mesmo barbeiro"><i class="fa fa-redo"></i> Repetir</a>
                
                <a href="imprimir_comprovativo_cliente.php?id=<?= $ag['id'] ?>" target="_blank" class="btn" style="padding: 10px 20px; border-radius: 12px; background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.02); text-align: center; text-decoration: none;" title="Baixar Recibo em PDF"><i class="fa fa-file-pdf"></i> Recibo</a>

                <?php if($ag['status'] === 'concluido' && !in_array($ag['id'], $agendamentos_avaliados_ids)): ?>
                    <button class="btn btn-avaliar" style="padding: 10px 20px; border-radius: 12px; background: var(--app-accent); color: white; font-weight: 700; border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.15);" data-modal-target="#modal-avaliacao" data-agendamento-id="<?= $ag['id'] ?>" data-barbeiro-id="<?= $ag['barbeiro_id'] ?>"><i class="fa fa-star"></i> Avaliar</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        
        <?php if(count($agendamentosHistorico) > 5): ?>
        <div style="text-align: center; margin-top: 20px;">
            <button id="btn-carregar-mais-historico" class="btn" style="background: var(--card-bg); border: 1px solid var(--border-strong); color: var(--text-main); border-radius: 12px; padding: 12px 25px; font-weight: 700; box-shadow: 0 2px 5px rgba(0,0,0,0.02);"><i class="fa fa-chevron-down" style="margin-right: 8px;"></i> Ver mais antigos</button>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<div id="indicacao" class="tabcontent">
    <div style="text-align: center; padding: 50px 30px; background: linear-gradient(135deg, #fffbeb, #fef3c7); border-radius: 24px; border: 1px solid #fde68a; box-shadow: 0 10px 30px -10px rgba(251, 191, 36, 0.3); position: relative; overflow: hidden;">
        <i class="fa fa-gift" style="font-size: 6rem; color: var(--app-accent); margin-bottom: 25px; filter: drop-shadow(0 10px 15px rgba(0,0,0, 0.1)); opacity: 0.9;"></i>
        <h3 style="font-size: 2.2rem; color: #92400e; margin: 0 0 15px; font-weight: 900; letter-spacing: -1px;">Indique e Ganhe!</h3>
        <p style="font-size: 1.15rem; color: #b45309; max-width: 600px; margin: 0 auto 30px; font-weight: 500; line-height: 1.6;">Compartilhe seu código exclusivo. Seu amigo ganha <strong><?= $config_indicacao['desconto_novo_cliente'] ?>% de desconto</strong> na primeira visita e você ganha prêmios.</p>
        
        <div style="background: rgba(255,255,255,0.7); padding: 20px 25px; border-radius: 16px; display: inline-block; max-width: 550px; margin: 0 auto 40px; border: 1px solid rgba(253, 230, 138, 0.8); backdrop-filter: blur(5px);">
            <p style="font-size: 1rem; margin: 0; color: #78350f; text-align: left; line-height: 1.5;">
                <strong style="display: block; margin-bottom: 8px; font-size: 1.1rem;"><i class="fa fa-magic" style="color: var(--app-accent); margin-right: 5px;"></i> A Mágica:</strong> Você ganha <strong><?= $config_indicacao['pontos_indicacao'] ?> pontos</strong> no Clube Fidelidade automaticamente assim que seu amigo concluir o primeiro agendamento usando seu código!
            </p>
        </div>
        <br>
        
        <div class="codigo-indicacao" id="btn-copiar-codigo" data-codigo="<?= htmlspecialchars($clienteAtual['codigo_indicacao']) ?>" style="margin: 0 auto; display: inline-flex; background: var(--card-bg); border: 2px dashed var(--app-accent); padding: 20px 40px; border-radius: 16px; font-size: 2rem; font-weight: 900; color: var(--app-accent); align-items: center; gap: 20px; cursor: pointer; transition: all 0.3s; box-shadow: 0 10px 20px rgba(0,0,0,0.05);">
            <span id="texto-codigo" style="letter-spacing: 4px; font-family: monospace;"><?= htmlspecialchars($clienteAtual['codigo_indicacao']) ?></span>
            <div style="background: #fffbeb; width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center;"><i class="fa fa-copy" style="color: var(--app-accent); font-size: 1.2rem;"></i></div>
        </div>
        <p style="font-size: 1rem; color: #92400e; margin-top: 25px; font-weight: 700;"><i class="fa fa-hand-pointer"></i> Clique no código acima para copiar e enviar aos amigos!</p>
    </div>
</div>
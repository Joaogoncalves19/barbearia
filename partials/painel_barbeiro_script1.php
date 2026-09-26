<script>
    const clientesData = <?= json_encode(array_values($clientesArr)) ?>;
    const agendamentosData = <?= json_encode(array_values($agendamentosArr)) ?>;
    const servicosData = <?= json_encode($servicosArr) ?>;
    const combosData = <?= json_encode(array_values($combosArr)) ?>;
    const barbeirosData = <?= json_encode($barbeirosArr) ?>;
    
    const assinaturasJS = {};
    <?php foreach($assinaturasArr as $ass): ?>
        assinaturasJS["<?= $ass['cliente_id'] ?>"] = <?= json_encode($ass) ?>;
    <?php endforeach; ?>
    
    const planosJS = {};
    <?php foreach($planosArr as $p): ?>
        planosJS["<?= $p['id'] ?>"] = <?= json_encode($p) ?>;
    <?php endforeach; ?>
    
    const adminJSData = {
        assinaturasData: assinaturasJS,
        planosData: planosJS,
        combosData: <?= json_encode($combosArr) ?>
    };
    
    const anotacoesData = {};
    <?php foreach($clientesArr as $id => $c): ?>
        <?php if(!empty($c['notas_barbeiro'])): ?>
            anotacoesData["<?= $id ?>"] = <?= json_encode($c['notas_barbeiro']) ?>;
        <?php endif; ?>
    <?php endforeach; ?>
    
    const clientesInfoData = {};
    <?php foreach($clientesArr as $id => $c): ?>
        clientesInfoData["<?= $id ?>"] = <?= json_encode($c['nome']) ?>;
    <?php endforeach; ?>
</script>

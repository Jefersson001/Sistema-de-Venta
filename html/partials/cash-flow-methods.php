<?php if (!empty($currency['methods'])): ?>
<div class="cash-flow-methods" data-cash-flow-methods>
    <p class="cash-flow-methods-title">Por medio de pago</p>
    <table><thead><tr><th scope="col">Medio</th><th scope="col">Ingresos</th><th scope="col">Egresos</th></tr></thead><tbody>
    <?php foreach ($currency['methods'] as $method): ?>
    <tr data-cash-flow-method="<?= (int)($method['method_id']??0) ?>"><th scope="row"><?= htmlspecialchars($method['name'],ENT_QUOTES,'UTF-8') ?></th>
    <?php foreach (['income','expense'] as $key): ?><td class="cash-flow-<?= $key ?>"><?= bccomp($method[$key],'0',2)===0?'—':htmlspecialchars($method[$key],ENT_QUOTES,'UTF-8') ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php endif; ?>

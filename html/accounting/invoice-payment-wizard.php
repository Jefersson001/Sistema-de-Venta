<?php
$failedWizardAction = $page['errors'] && is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$wizardValue = static function (string $field, string $fallback, string $action = 'register_payment') use ($failedWizardAction): string {
    return $failedWizardAction === $action && is_string($_POST[$field] ?? null) ? $_POST[$field] : $fallback;
};
$wizardCurrency = $wizardValue('currency_code', $record['currency_code']);
?>
<?php if ($canRegisterPayment): ?>
<dialog id="invoice-payment-wizard" class="contact-sheet invoice-wizard" aria-labelledby="invoice-payment-wizard-title" data-invoice-wizard <?= $failedWizardAction === 'register_payment' ? 'data-reopen' : '' ?>>
    <header class="invoice-wizard-header"><h2 id="invoice-payment-wizard-title"><?= $credit ? 'Registrar reembolso' : 'Registrar pago' ?></h2><button class="contact-button" type="button" data-invoice-wizard-close aria-label="Cerrar asistente">×</button></header>
    <form method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['id'] ?>">
        <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><input type="hidden" name="action" value="register_payment">
        <div class="invoice-wizard-body">
            <p class="section-subtitle"><?= accountingEscape($record['contact_name']) ?> · <?= accountingEscape($record['name']) ?></p>
            <?php if ($failedWizardAction === 'register_payment'): ?><div class="notice" role="alert"><?php foreach ($page['errors'] as $error): ?><p><?= accountingEscape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
            <div class="contact-form-grid">
                <label>Importe del pago *<input name="amount" inputmode="decimal" required autofocus value="<?= accountingEscape($wizardValue('amount', $available)) ?>" maxlength="19"></label>
                <label>Fecha de pago *<input type="date" name="payment_date" required value="<?= accountingEscape($wizardValue('payment_date', commerceToday())) ?>"></label>
                <label>Moneda<select name="currency_code"><?php foreach (array_unique(array_merge(accountingCurrencyCodes($page['settings']), [$record['currency_code']])) as $code): ?><option value="<?= accountingEscape($code) ?>" <?= $wizardCurrency === $code ? 'selected' : '' ?>><?= accountingEscape($code) ?></option><?php endforeach; ?></select></label>
                <label><?= (($page['type']==='customer') === !$credit) ? 'Cuenta de ingreso' : 'Cuenta de egreso' ?> *<select name="account_id" required><option value="" <?= !salesId($wizardValue('account_id', '')) ? 'selected' : '' ?>>Selecciona una cuenta</option><?php foreach ($page['paymentAccounts'] as $account): ?><option value="<?= (int)$account['id'] ?>" <?= $wizardValue('account_id', '') === (string)$account['id'] ? 'selected' : '' ?>><?= accountingEscape($account['name']) ?><?= $account['currency_code'] ? ' · '.accountingEscape($account['currency_code']) : '' ?></option><?php endforeach; ?></select></label>
                <label>Medio de pago<select name="method_id"><?php foreach ($page['paymentMethods'] as $method): ?><option value="<?= (int)$method['id'] ?>" <?= $wizardValue('method_id', '') === (string)$method['id'] ? 'selected' : '' ?>><?= accountingEscape($method['name']) ?></option><?php endforeach; ?></select></label>
                <label>Referencia<input name="reference" maxlength="160" value="<?= accountingEscape($wizardValue('reference', '')) ?>"></label>
                <label class="contact-full">Tasa de cambio<input name="exchange_rate" inputmode="decimal" value="<?= accountingEscape($wizardValue('exchange_rate', $page['settings']['exchange_rate'] ?? '')) ?>"><small>1 <?= accountingEscape($page['settings']['primary_code']) ?> = tasa en <?= accountingEscape($page['settings']['alternate_code']) ?>. El importe corresponde a la moneda del pago.</small></label>
            </div>
            <p class="section-subtitle">El pago queda en proceso hasta que lo confirmes.</p>
            <?php if (!$credit): ?><p class="section-subtitle">Si el pago supera el saldo de esta factura, la diferencia queda disponible para otras facturas del mismo contacto.</p><?php endif; ?>
        </div>
        <footer class="invoice-wizard-footer"><button class="contact-button contact-button-primary" type="submit"><?= $credit ? 'Registrar reembolso' : 'Registrar pago' ?></button><button class="contact-button" type="button" data-invoice-wizard-close>Cancelar</button></footer>
    </form>
</dialog>
<?php endif; ?>
<?php if ($canCreateCredit): ?>
<dialog id="invoice-credit-wizard" class="contact-sheet invoice-wizard" aria-labelledby="invoice-credit-wizard-title" data-invoice-wizard <?= $failedWizardAction === 'create_credit' ? 'data-reopen' : '' ?>>
    <header class="invoice-wizard-header"><h2 id="invoice-credit-wizard-title">Nota de crédito</h2><button class="contact-button" type="button" data-invoice-wizard-close aria-label="Cerrar asistente">×</button></header>
    <form method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['id'] ?>"><input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><input type="hidden" name="action" value="create_credit">
        <div class="invoice-wizard-body">
            <?php if ($failedWizardAction === 'create_credit'): ?><div class="notice" role="alert"><?php foreach ($page['errors'] as $error): ?><p><?= accountingEscape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
            <label>Motivo de la nota de crédito *<input name="reason" required autofocus maxlength="255" value="<?= accountingEscape($wizardValue('reason', '', 'create_credit')) ?>"></label>
        </div>
        <footer class="invoice-wizard-footer"><button class="contact-button contact-button-primary" type="submit">Crear nota de crédito</button><button class="contact-button" type="button" data-invoice-wizard-close>Cancelar</button></footer>
    </form>
</dialog>
<?php endif; ?>
<script type="application/json" data-invoice-payment-settings><?= json_encode($page['settings'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../../js/currency.js" defer></script><script src="../../js/invoice-payments.js" defer></script>

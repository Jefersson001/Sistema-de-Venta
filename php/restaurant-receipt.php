<?php
declare(strict_types=1);

function restaurantLoadReceipt(PDO $db,int $accountId): array
{
    permissionRequire('restaurante.accounts','read',$accountId);
    $account=restaurantRecord($db,'restaurant_accounts',$accountId);
    $q=$db->prepare('SELECT i.*,r.receipt_snapshot,c.name AS customer_name,c.tax_id AS customer_tax_id FROM restaurant_account_carts r JOIN accounting_invoices i ON i.id=r.invoice_id JOIN contacts c ON c.id=i.contact_id WHERE r.account_id=?');
    $q->execute([$accountId]);$invoice=$q->fetch();
    if(!$invoice)throw new InvalidArgumentException('Esta mesa todavía no tiene una factura.',404);
    $q=$db->prepare('SELECT product_name,quantity,unit_price,tax_rate,total_amount FROM accounting_invoice_lines WHERE invoice_id=? ORDER BY position,id');
    $q->execute([$invoice['id']]);$lines=$q->fetchAll();
    $q=$db->prepare("SELECT p.name,p.reference,p.amount,p.received_amount,p.change_amount,p.currency_code,m.name AS method_name,a.name AS account_name FROM accounting_invoice_payments l JOIN accounting_payments p ON p.id=l.payment_id JOIN accounting_payment_methods m ON m.id=p.method_id JOIN accounting_payment_accounts a ON a.id=p.account_id WHERE l.invoice_id=? AND l.status='settled' AND p.status='settled' ORDER BY p.id");
    $q->execute([$invoice['id']]);$payments=$q->fetchAll();
    $q=$db->prepare('SELECT name,legal_name,tax_id,address,phone FROM business_company WHERE id=?');$q->execute([$account['company_id']]);$company=$q->fetch();
    if(!empty($invoice['receipt_snapshot'])){
        $identity=json_decode($invoice['receipt_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $invoice['customer_name']=$identity['customer']['name'];$invoice['customer_tax_id']=$identity['customer']['tax_id'];$company=$identity['company'];
    }
    $q=$db->prepare("SELECT details FROM restaurant_audit_events WHERE account_id=? AND action='pos_checkout' ORDER BY id DESC LIMIT 1");$q->execute([$accountId]);
    $audit=json_decode($q->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
    $q=$db->prepare('SELECT id,product_name FROM restaurant_account_items WHERE account_id=?');$q->execute([$accountId]);$itemsById=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    return compact('account','invoice','lines','payments','company','audit','itemsById');
}

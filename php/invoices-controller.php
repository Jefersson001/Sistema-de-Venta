<?php
declare(strict_types=1);
require_once __DIR__ . '/accounting-controller.php';
require_once __DIR__ . '/commerce-repository.php';
require_once __DIR__ . '/invoice-status-repository.php';

function renderAccountingInvoices(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['accounting_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $type = $_GET['type'] ?? 'customer'; $validType = is_string($type) && in_array($type, ['customer', 'supplier'], true);
    if (! $validType) { $type = 'customer'; http_response_code(404); }
    $page = ['area' => 'invoices', 'type' => $type, 'available' => false, 'errors' => [], 'view' => 'list', 'csrf' => $_SESSION['accounting_csrf'],
        'flash' => $_SESSION['invoice_flash'] ?? null, 'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['invoice_flash']);
    try {
        $db = createDatabaseConnection(); $page['databaseStatus']['connected'] = true; $page['available'] = $validType;
        if (! $validType) $page['errors'][] = 'El tipo de factura no existe.';
        $id = salesId($_GET['id'] ?? null); $page['view'] = $id ? 'form' : 'list';
        if (isset($_GET['id']) && ! $id) { http_response_code(404); $page['available'] = false; $page['errors'][] = 'La factura no existe.'; }
        if ($id) {
            $query = $db->prepare('SELECT i.*, c.name AS contact_name, t.name AS term_name, s.name AS seller_name FROM accounting_invoices i JOIN contacts c ON c.id = i.contact_id
                LEFT JOIN accounting_payment_terms t ON t.id = i.payment_term_id LEFT JOIN contacts s ON s.id = i.salesperson_id WHERE i.id = ? AND i.invoice_type = ?');
            $query->execute([$id, $type]); $page['record'] = $query->fetch();
            if (! $page['record']) { http_response_code(404); $page['available'] = false; $page['errors'][] = 'La factura no existe.'; }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            if (! is_string($_POST['csrf'] ?? null) || ! hash_equals($page['csrf'], $_POST['csrf'])) { http_response_code(403); $page['errors'][] = 'La sesión del formulario no es válida.'; }
            elseif (! $id || ! in_array($_POST['action'] ?? '', ['save_invoice', 'confirm_invoice', 'cancel_invoice', 'mark_invoice_sent', 'register_payment', 'settle_payment', 'cancel_payment', 'reverse_invoice', 'create_credit'], true)) { http_response_code(400); $page['errors'][] = 'La operación no es válida.'; }
            elseif (in_array($_POST['action'], ['reverse_invoice','create_credit'], true)) {
                try {
                    $credit = createCreditNote($db,$id,salesId($_POST['version']??null),is_string($_POST['reason']??null)?$_POST['reason']:'');
                    header('Location: index.php?type='.$type.'&id='.$credit,true,303); exit;
                } catch (InvalidArgumentException $exception) { $page['errors'][]=$exception->getMessage(); }
            }
            elseif (! in_array($_POST['action'], ['save_invoice', 'confirm_invoice'], true)) {
                try {
                    transitionInvoiceStatus($db, $id, salesId($_POST['version'] ?? null), $_POST['action'], $_POST);
                    $_SESSION['invoice_flash'] = match ($_POST['action']) { 'mark_invoice_sent' => 'Factura marcada como enviada. El envío se registra localmente.', 'register_payment' => 'Pago registrado en proceso de pago.', 'settle_payment' => 'Pago confirmado.', 'cancel_payment' => 'Pago cancelado.', 'reverse_invoice' => 'Factura revertida.', default => 'Factura cancelada.' };
                    header('Location: index.php?type=' . $type . '&id=' . $id, true, 303); exit;
                } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
            }
            else {
                $data = [];
                foreach (['invoice_date', 'due_date'] as $field) {
                    $value = $_POST[$field] ?? ($page['record'][$field] ?? '');
                    if ($field === 'due_date' && ($value === '' || $value === null)) { $data[$field] = null; continue; }
                    $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                    if (! $parsed || $parsed->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9999-12-31') $page['errors'][] = 'Selecciona fechas válidas para la factura.';
                    $data[$field] = is_string($value) ? $value : '';
                }
                if ($data['due_date'] && $data['due_date'] < $data['invoice_date']) $page['errors'][] = 'El vencimiento no puede ser anterior a la fecha de factura.';
                if (! $page['errors']) {
                    workflowBegin($db);
                    try {
                        workflowLockOrder($db,['quotation_id'=>$page['record']['sale_id'],'purchase_order_id'=>$page['record']['purchase_id']]);
                        if ($page['record']['reversal_of_id']) { $original=$db->prepare('SELECT id FROM accounting_invoices WHERE id=? FOR UPDATE'); $original->execute([$page['record']['reversal_of_id']]); }
                        $lock = $db->prepare('SELECT * FROM accounting_invoices WHERE id = ? FOR UPDATE'); $lock->execute([$id]); $current = $lock->fetch();
                        if ((int) $current['version'] !== salesId($_POST['version'] ?? null)) throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
                        if ($current['status'] !== 'draft') throw new InvalidArgumentException('La factura confirmada no admite cambios.');
                        if ($current['document_type']==='credit') {
                            $original=$db->prepare('SELECT status FROM accounting_invoices WHERE id=?');$original->execute([$current['reversal_of_id']]);
                            if($original->fetchColumn()!=='posted')throw new InvalidArgumentException('La factura original debe permanecer registrada.');
                            $pending=$db->prepare("SELECT COUNT(*) FROM accounting_invoice_payments WHERE invoice_id=? AND status='pending'");$pending->execute([$current['reversal_of_id']]);
                            if($pending->fetchColumn())throw new InvalidArgumentException('Confirma o cancela los pagos en proceso de la factura original antes de registrar la nota de crédito.');
                        }
                        if ($current['document_type']==='credit') saveCreditNoteQuantities($db,$current,is_array($_POST['credit_quantities']??null)?$_POST['credit_quantities']:[]);
                        $status = $_POST['action'] === 'confirm_invoice' ? 'posted' : 'draft';
                        $db->prepare('UPDATE accounting_invoices SET invoice_date = ?, due_date = ?, status = ?, version = version + 1 WHERE id = ?')->execute([$data['invoice_date'], $data['due_date'], $status, $id]);
                        if ($status === 'posted') {
                            $fresh=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$fresh->execute([$id]);invoiceRecomputePaymentState($db,$fresh->fetch());
                            if ($current['reversal_of_id']) { $fresh->execute([$current['reversal_of_id']]);invoiceRecomputePaymentState($db,$fresh->fetch()); }
                        }
                        $db->commit(); $_SESSION['invoice_flash'] = $status === 'posted' ? 'Factura registrada.' : 'Factura guardada.';
                        header('Location: index.php?type=' . $type . '&id=' . $id, true, 303); exit;
                    } catch (InvalidArgumentException $exception) { $db->rollBack(); $page['errors'][] = $exception->getMessage(); }
                    catch (Throwable $exception) { $db->rollBack(); throw $exception; }
                }
            }
        }
        if ($page['view'] === 'form' && $page['available']) {
            $query = $db->prepare('SELECT * FROM accounting_invoice_lines WHERE invoice_id = ? ORDER BY position, id'); $query->execute([$id]); $page['lines'] = $query->fetchAll();
            $page['documentKind'] = $type === 'customer' ? 'sale' : 'purchase';
            $page['links'] = commerceLinks($db, $page['documentKind'], (int) $page['record'][$type === 'customer' ? 'sale_id' : 'purchase_id']);
            $page['paymentDetails'] = invoicePaymentDetails($db, $id);
            $page['paymentDetails']['effective_amount']=$page['record']['document_type']==='credit'?creditRefundAmount($db,$page['record']):bcsub($page['record']['total_amount'],$page['paymentDetails']['credited'],2);
        } elseif ($page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
            $page['statusFilter'] = is_string($_GET['status'] ?? null) && isset(invoiceFilterStates()[$_GET['status']]) ? $_GET['status'] : 'all';
            $params = ['type' => $type]; $where = ' WHERE i.invoice_type = :type';
            foreach(['sale_id','purchase_id'] as $key) if($filterId=salesId($_GET[$key]??null)) { $where.=' AND i.'.$key.' = :'.$key; $params[$key]=$filterId; }
            if ($page['search'] !== '') { $where .= ' AND (i.name LIKE :reference OR c.name LIKE :contact)'; $params['reference'] = $params['contact'] = '%' . $page['search'] . '%'; }
            if (isset(invoiceStatuses()[$page['statusFilter']])) { $where .= ' AND i.status = :status'; $params['status'] = $page['statusFilter']; }
            elseif ($page['statusFilter'] === 'sent') { $where .= " AND i.status = 'posted' AND i.sent_at IS NOT NULL"; }
            elseif (isset(invoicePaymentStates()[$page['statusFilter']])) { $where .= " AND i.status = 'posted' AND i.payment_state = :payment_state"; $params['payment_state'] = $page['statusFilter']; }
            $from = ' FROM accounting_invoices i JOIN contacts c ON c.id = i.contact_id';
            $count = $db->prepare('SELECT COUNT(*)' . $from . $where); $count->execute($params); $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20)); $page['currentPage'] = min(salesId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $query = $db->prepare('SELECT i.*, c.name AS contact_name' . $from . $where . ' ORDER BY i.id DESC LIMIT 20 OFFSET ' . (($page['currentPage'] - 1) * 20)); $query->execute($params); $page['rows'] = $query->fetchAll();
        }
    } catch (PDOException $exception) { error_log('Facturas: ' . $exception->getMessage()); http_response_code(503); $page['available'] = false; $page['errors'][] = 'No se pudieron cargar las facturas. Verifica la instalación de Contabilidad.'; }
    renderModulePage('contabilidad', false, $page);
}

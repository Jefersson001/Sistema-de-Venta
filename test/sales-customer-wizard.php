<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/auth-server.php';
function wizardExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$server = startAuthTestServer();
$cookie = tempnam(sys_get_temp_dir(), 'sales-wizard-');
try {
    $login = 'wizard.admin.'.bin2hex(random_bytes(5)); $password = bin2hex(random_bytes(14));
    $token = prepareFirstAdministrator($server['db']);
    createFirstAdministrator($server['db'], ['name'=>'Prueba wizard', 'login'=>$login, 'password'=>$password, 'password_confirmation'=>$password], hash('sha256', $token));
    [, $body] = authTestRequest($server, '/login.php', $cookie);
    [$status] = authTestRequest($server, '/login.php', $cookie, ['csrf'=>authTestToken($body), 'login'=>$login, 'password'=>$password]);
    wizardExpect($status===303, 'Login failed.');
    [$status, $body] = authTestRequest($server, '/modules/ventas/index.php?new=1', $cookie);
    wizardExpect($status===200 && str_contains($body, 'data-unregistered-customer') && str_contains($body, 'data-sales-customer-dialog'), 'Wizard missing in sales form.');
    $scope = authTestToken($body, 'company_scope');
    $path = '/modules/contactos/index.php?'.http_build_query(['new'=>'1', 'sales_wizard'=>'1', 'wizard_scope'=>$scope]);
    [$status, $body] = authTestRequest($server, $path, $cookie);
    wizardExpect($status===200 && str_contains($body, 'contact-sales-wizard'), 'Contacts not embedded.');
    wizardExpect(str_contains(html_entity_decode($body), 'sales_wizard=1&wizard_scope='), 'Form does not retain wizard context.');
    $csrf = authTestToken($body);
    $client = (int)$server['db']->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $supplier = (int)$server['db']->query("SELECT id FROM contact_types WHERE system_key='supplier'")->fetchColumn();
    $count = (int)$server['db']->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $data = ['action'=>'save_contact', 'csrf'=>$csrf, 'entity_kind'=>'person', 'type_id'=>$client, 'name'=>'Cliente wizard <prueba>', 'address'=>'Calle de prueba', 'postal_code'=>'1234'];
    [$status] = authTestRequest($server, $path, $cookie, array_replace($data, ['csrf'=>'invalid']));
    wizardExpect($status===403, 'CSRF accepted.');
    [$status, $body] = authTestRequest($server, $path, $cookie, array_replace($data, ['name'=>'']));
    wizardExpect($status===200 && str_contains($body, 'Escribe el nombre'), 'Validation errors missing.');
    [$status, $body] = authTestRequest($server, $path, $cookie, array_replace($data, ['type_id'=>$supplier]));
    wizardExpect(str_contains($body, 'Esta ventana permite crear un cliente nuevo'), 'Non-client creation accepted.');
    [$status] = authTestRequest($server, str_replace($scope, str_repeat('0', 64), $path), $cookie);
    wizardExpect($status===409, 'Stale company scope accepted.');
    wizardExpect((int)$server['db']->query('SELECT COUNT(*) FROM contacts')->fetchColumn()===$count, 'Rejected requests changed contacts.');
    [$status, , $redirect] = authTestRequest($server, $path, $cookie, $data);
    wizardExpect($status===303 && str_contains($redirect, 'created=1'), 'Creation did not redirect to completion.');
    $redirectPath = substr($redirect, strlen($server['url']));
    [$status, $body] = authTestRequest($server, $redirectPath, $cookie);
    wizardExpect($status===200 && preg_match('~<script[^>]*data-created-sales-customer[^>]*>(.*?)</script>~s', $body, $match)===1, 'Completion payload missing.');
    $payload = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    wizardExpect($payload['name']===$data['name'] && $payload['address']==='Calle de prueba, 1234' && $payload['scope']===$scope, 'Customer data incorrect.');
    wizardExpect(!str_contains($match[1], '<prueba>'), 'Unsafe embedded JSON.');
    wizardExpect((int)$server['db']->query('SELECT COUNT(*) FROM contacts')->fetchColumn()===$count+1, 'Unexpected customer count.');
    [$status, $body] = authTestRequest($server, '/modules/ventas/index.php?new=1', $cookie);
    wizardExpect($status===200 && str_contains($body, 'value="'.$payload['id'].'" data-address="Calle de prueba, 1234"'), 'Customer unavailable in sales.');
    $env = getenv(); $env['NEXO_TEST_BASE_URL']=$server['url']; $env['NEXO_TEST_LOGIN']=$login; $env['NEXO_TEST_PASSWORD']=$password;
    $process = proc_open(['node', __DIR__.'/sales-customer-wizard-ui.js'], [0=>['pipe','r'], 1=>STDOUT, 2=>STDERR], $pipes, dirname(__DIR__), $env, ['bypass_shell'=>true, 'create_new_console'=>false]);
    wizardExpect(is_resource($process), 'Browser test could not start.'); fclose($pipes[0]);
    wizardExpect(proc_close($process)===0, 'Browser wizard test failed.');
    wizardExpect((int)$server['db']->query("SELECT COUNT(*) FROM contact_attachments a JOIN contacts c ON c.id=a.contact_id WHERE c.name='Cliente navegador wizard' AND a.description='Foto desde ventas'")->fetchColumn()===1, 'Browser attachment did not persist.');
    echo "PASS: sales wizard, embedded Contacts, validation, CSRF, company context, client-only creation and escaped completion payload in an isolated database.\n";
} finally {
    if (is_file($cookie)) unlink($cookie);
    stopAuthTestServer($server);
}

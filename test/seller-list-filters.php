<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
function sellerCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function sellerDom(string $html):DOMXPath{$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($d);}
$server=null;$cookies=[];
try{
    $server=startAuthTestServer();$db=$server['db'];$password=bin2hex(random_bytes(14));$prefix='sf.'.bin2hex(random_bytes(5));
    $db->exec("UPDATE business_company SET name='AAA Empresa filtros QA' WHERE id=1");
    $token=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>'Filtros QA','login'=>$prefix,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$admin);
    $insert=static function(string $table,array $data)use($db):int{$db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return(int)$db->lastInsertId();};
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $a=$insert('contacts',['name'=>'Filtro vendedor A','type_id'=>$types['salesperson']]);$b=$insert('contacts',['name'=>'Filtro vendedor B inactivo','type_id'=>$types['salesperson'],'active'=>0]);
    $client=$insert('contacts',['name'=>'Cliente filtros QA','type_id'=>$types['client'],'salesperson_id'=>$a]);
    $group=$db->query("SELECT id FROM access_groups WHERE system_key='direct_sales'")->fetchColumn();
    $user=saveAuthUser($db,['name'=>'Filtro vendedor QA','login'=>$prefix.'.seller','active'=>'1','groups'=>[$group],'company_ids'=>[1],'contact_id'=>$a,'password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));
    $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$user]);
    foreach(['A'=>$a,'B'=>$b,'N'=>null] as $code=>$seller){
        foreach(range(1,$code==='A'?21:1) as $n){
            $name='SF-'.$code.'-'.$n;
            $sale=$insert('sales_quotations',['name'=>$name,'customer_id'=>$client,'salesperson_id'=>$seller,'order_date'=>'2026-11-25','status'=>'sale','base_amount'=>'10','total_amount'=>'10']);
            $insert('inventory_operations',['name'=>$name,'quotation_id'=>$sale,'contact_id'=>$client,'operation_type'=>'delivery','scheduled_date'=>'2026-11-25']);
            $insert('accounting_invoices',['name'=>$name,'sale_id'=>$sale,'contact_id'=>$client,'salesperson_id'=>$seller,'invoice_type'=>'customer','invoice_address'=>'QA','invoice_date'=>'2026-11-25','base_amount'=>'10','total_amount'=>'10']);
        }
    }
    $other=$insert('business_company',['name'=>'Empresa filtros ajena']);$db->exec('SET @nexo_company_id='.$other);
    $otherSeller=$insert('contacts',['name'=>'Vendedor ajeno oculto','type_id'=>$types['salesperson']]);$otherClient=$insert('contacts',['name'=>'Cliente ajeno','type_id'=>$types['client']]);
    $sale=$insert('sales_quotations',['name'=>'SF-X-1','customer_id'=>$otherClient,'salesperson_id'=>$otherSeller,'order_date'=>'2026-11-25','base_amount'=>'10','total_amount'=>'10']);
    $insert('inventory_operations',['name'=>'SF-X-1','quotation_id'=>$sale,'contact_id'=>$otherClient,'operation_type'=>'delivery','scheduled_date'=>'2026-11-25']);
    $insert('accounting_invoices',['name'=>'SF-X-1','contact_id'=>$otherClient,'salesperson_id'=>$otherSeller,'invoice_type'=>'customer','invoice_address'=>'QA','invoice_date'=>'2026-11-25','base_amount'=>'10','total_amount'=>'10']);$db->exec('SET @nexo_company_id=1');
    $routes=['/modules/ventas/index.php?','/modules/inventario/operaciones.php?type=delivery&','/modules/contabilidad/index.php?type=customer&'];
    foreach(['admin'=>$prefix,'seller'=>$prefix.'.seller'] as $role=>$login){
        $cookie=tempnam(sys_get_temp_dir(),'seller-filter-');$cookies[]=$cookie;[, $loginHtml]=authTestRequest($server,'/login.php',$cookie);
        sellerCheck(authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($loginHtml),'login'=>$login,'password'=>$password])[0]===303,'Login failed.');
        foreach($routes as $route){
            [$status,$html]=authTestRequest($server,$route.'q=SF-&salesperson_id='.$a,$cookie);$xp=sellerDom($html);
            sellerCheck($status===200&&$xp->query('//select[@name="salesperson_id"]/option[@selected and @value="'.$a.'"]')->length===1,'Filter not rendered/selected: '.$route);
            $rows=$xp->query('//table[contains(@class,"contact-table")]/tbody/tr/td/a[contains(@class,"contact-name")]');sellerCheck($rows->length===20,'Filtered page count incorrect: '.$role.' '.$route.' rows='.$rows->length);
            foreach($rows as $row)sellerCheck(str_starts_with(trim($row->textContent),'SF-A-'),'Another seller entered results.');
            sellerCheck(!str_contains($html,'Vendedor ajeno oculto'),'Cross-company seller leaked.');
            $next=$xp->query('//nav[contains(@class,"contact-pagination")]//a[contains(@href,"page=2")]')->item(0);sellerCheck($next!==null&&str_contains($next->getAttribute('href'),'salesperson_id='.$a),'Pagination lost salesperson filter.');
            [$status,$page2]=authTestRequest($server,$route.'q=SF-&salesperson_id='.$a.'&page=2',$cookie);sellerCheck($status===200&&sellerDom($page2)->query('//table/tbody/tr/td/a[contains(@class,"contact-name")]')->length===1,'Page 2 incorrect.');
            foreach([$b=>'B','none'=>'N',$otherSeller=>'X','invalid'=>'X'] as $selected=>$expected){
                [$status,$body]=authTestRequest($server,$route.'q=SF-&salesperson_id='.$selected,$cookie);$xp=sellerDom($body);$rows=$xp->query('//table[contains(@class,"contact-table")]/tbody/tr/td/a[contains(@class,"contact-name")]');
                sellerCheck($status===200,'Filtered list failed.');
                if($role==='seller'||$expected==='X')sellerCheck($rows->length===0,'Permissions bypassed or invalid selector ignored.');
                else sellerCheck($rows->length===1&&str_starts_with(trim($rows->item(0)->textContent),'SF-'.$expected.'-'),'Inactive/unassigned seller filter incorrect.');
                if($role==='seller')sellerCheck(!str_contains($body,'Filtro vendedor B inactivo'),'Seller choice leaked another salesperson.');
            }
            [$status,$body]=authTestRequest($server,$route.'q=SF-&salesperson_id[]=1',$cookie);sellerCheck($status===200&&sellerDom($body)->query('//table/tbody/tr/td/a[contains(@class,"contact-name")]')->length===0,'Array input bypassed filter.');
        }
    }
    echo "PASS: sales/deliveries/invoices, seller A/B/unassigned, inactive history, search, pagination, invalid inputs, own permissions and company isolation.\n";
}finally{if($server)stopAuthTestServer($server);foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);}

<?php
declare(strict_types=1);
require_once __DIR__.'/company-catalog.php';
require_once __DIR__.'/transfer-transactions.php';

/**
 * Scope every business table occurrence, including nested queries and LEFT JOINs.
 * The lexer preserves literals/placeholders. Unsupported SQL fails closed; there is
 * no runtime opt-out on this connection. Schema migrations use a separate raw PDO.
 */
final class CompanyConnection extends PDO
{
    use TransferTransactions;
    public function __construct(string $dsn, string $username, string $password, array $options,
        private array $companies, private int $activeCompany, private int $actor, private array $tables)
    {
        parent::__construct($dsn,$username,$password,$options);
        parent::exec("SET time_zone='-04:00', @nexo_company_id=".$activeCompany.', @nexo_actor_id='.$actor);
    }

    public function prepare(string $query, array $options=[]): PDOStatement|false
    { return parent::prepare($this->scope($query),$options); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql=$this->scope($query);
        return $fetchMode===null?parent::query($sql):parent::query($sql,$fetchMode,...$fetchModeArgs);
    }
    public function exec(string $statement): int|false {
        if($this->transferBatchActive()&&preg_match('/^SET TRANSACTION ISOLATION LEVEL /i',$statement))return 0;
        return parent::exec($this->scope($statement));
    }
    public function activeCompanyId(): int { return $this->activeCompany; }
    public function configurationCompanyId(): int { return count($this->companies)===1?$this->companies[0]:$this->activeCompany; }
    public function markCompanyAccessChanged(array $ids): void
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids)return;
        parent::prepare('UPDATE business_company SET version=version+1 WHERE id IN('.implode(',',array_fill(0,count($ids),'?')).')')->execute($ids);
    }

    public function scope(string $sql): string
    {
        preg_match_all('/\s+|\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|`[a-zA-Z0-9_]+`|[a-zA-Z_][a-zA-Z0-9_]*|[0-9]+|./s',$sql,$matches);
        $tokens=$matches[0];$meaningful=[];
        foreach($tokens as $i=>$token)if(trim($token)!=='')$meaningful[]=$i;
        $words=array_map(static fn($i)=>strtoupper(trim($tokens[$i],'`')),$meaningful);
        $command=$words[0]??'';
        if(!in_array($command,['SELECT','INSERT','UPDATE','DELETE','SHOW','SET'],true))throw new LogicException('SQL no admitido en el contexto de empresa: '.$command);
        // Comments and multiple statements are deliberately not accepted.
        foreach($words as $p=>$word)if($word===';'||($word==='-'&&($words[$p+1]??'')==='-')||($word==='/'&&($words[$p+1]??'')==='*')||$word==='#')throw new LogicException('SQL compuesto no admitido.');
        if($command==='SET') {
            if(!preg_match('/^SET\s+TRANSACTION\s+ISOLATION\s+LEVEL\s+(REPEATABLE READ|READ COMMITTED|SERIALIZABLE)\s*$/iD',trim($sql)))throw new LogicException('SET no admitido.');
            return $sql;
        }
        if($command==='SHOW') {
            if(!preg_match('/^SHOW\s+(COLUMNS\s+FROM\s+[a-z_]+(?:\s+LIKE\s+\'[^\']+\')?|TABLES(?:\s+LIKE\s+\'[^\']+\')?)\s*$/iD',trim($sql)))throw new LogicException('SHOW no admitido.');
            return $sql;
        }
        $tables=$this->tables;$known=array_merge($tables,companySharedTables());$writeTarget=null;$targetPos=null;
        if($command==='UPDATE')$targetPos=1;
        if($command==='INSERT') { $p=array_search('INTO',$words,true);if($p!==false)$targetPos=$p+1; }
        if($command==='DELETE') {if(($words[1]??'')!=='FROM')throw new LogicException('DELETE múltiple no admitido.');$targetPos=2;}
        if($targetPos!==null) {
            $writeTarget=strtolower(trim($tokens[$meaningful[$targetPos]],'`'));
            if(!in_array($writeTarget,$known,true))throw new LogicException('Tabla de escritura no registrada: '.$writeTarget);
            if(in_array($writeTarget,['business_company','company_schema_tables','company_schema_state'],true))throw new LogicException('Usa la administración de empresas para esta operación.');
        }
        $locking=in_array('UPDATE',$words,true)&&$command==='SELECT'&&in_array('FOR',$words,true);
        $list=implode(',',array_map('intval',$this->companies));
        $reserved=['WHERE','LEFT','RIGHT','INNER','OUTER','CROSS','JOIN','ON','ORDER','GROUP','HAVING','LIMIT','UNION','FOR','LOCK','SET','USING','OFFSET','AND','OR'];
        foreach($words as $p=>$word) {
            if(!in_array($word,['FROM','JOIN'],true))continue;
            if($command==='DELETE'&&$p===1)continue;
            $n=$p+1;$token=$words[$n]??'';if($token==='(')continue;
            $name=strtolower(trim($tokens[$meaningful[$n]],'`'));
            if($name==='information_schema')continue; // Metadata only; contains no business rows.
            if(($words[$n+1]??'')==='.')throw new LogicException('Usa tablas sin prefijo de esquema.');
            if($word==='FROM') {
                $level=0;
                for($k=$n+1;$k<count($words);$k++) {
                    $part=$words[$k];if($part==='(')$level++;elseif($part===')'){if($level===0)break;$level--;}
                    if($level===0&&in_array($part,['WHERE','ORDER','GROUP','HAVING','LIMIT','UNION','FOR','JOIN','LEFT','RIGHT','INNER','CROSS','SET'],true))break;
                    if($level===0&&$part===',')throw new LogicException('Usa JOIN explícitos para relacionar tablas.');
                }
            }
            if(!in_array($name,$known,true))throw new LogicException('Tabla de lectura no registrada: '.$name);
            if(!in_array($name,$tables,true))continue;
            $next=$words[$n+1]??'';
            $hasAlias=$next==='AS'||($next!==''&&!in_array($next,$reserved,true)&&preg_match('/^[A-Z_][A-Z0-9_]*$/D',$next));
            // Singleton settings describe the active company's currency configuration.
            $scope=$name==='accounting_currency_settings'?(string)$this->configurationCompanyId():$list;
            $tokens[$meaningful[$n]]='(SELECT * FROM `'.$name.'` WHERE company_id IN ('.$scope.')'.($locking?' FOR UPDATE':'').')'.($hasAlias?'':' AS `'.$name.'`');
        }
        if(in_array($writeTarget,$tables,true)&&in_array($command,['UPDATE','DELETE'],true)) {
            // All existing runtime writes use a single unaliased target. Refuse
            // other shapes rather than accidentally changing their semantics.
            $after=$words[$targetPos+1]??'';
            if($command==='UPDATE'&&$after!=='SET')throw new LogicException('UPDATE con JOIN/alias no admitido.');
            $depth=0;$where=null;$end=count($tokens);
            foreach($words as $p=>$word) {
                if($word==='(')$depth++;elseif($word===')')$depth--;
                if($depth!==0)continue;
                if($word==='WHERE')$where=$meaningful[$p];
                if(in_array($word,['ORDER','LIMIT'],true)){$end=$meaningful[$p];break;}
            }
            $predicate='`'.$writeTarget.'`.company_id='.$this->activeCompany;
            if($where!==null){$tokens[$where]='WHERE '.$predicate.' AND (';$tokens[$end]=') '.($tokens[$end]??'');}
            else $tokens[$end]=' WHERE '.$predicate.' '.($tokens[$end]??'');
        }
        return implode('',$tokens);
    }
}

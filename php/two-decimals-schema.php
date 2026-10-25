<?php
declare(strict_types=1);

/** One-time upgrade; fresh installations already declare DECIMAL(...,2). */
function installTwoDecimals(PDO $db): array
{
    if ($db->inTransaction()) throw new LogicException('La migración decimal requiere una conexión sin transacciones activas.');
    $columns = $db->query("SELECT TABLE_NAME,COLUMN_NAME,NUMERIC_PRECISION,NUMERIC_SCALE,COLUMN_TYPE,EXTRA
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE='decimal' AND NUMERIC_SCALE<>2
        ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $plan = [];
    foreach ($columns as $column) {
        $table = $column['TABLE_NAME']; $field = $column['COLUMN_NAME'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table.$field) || $column['EXTRA'] !== '') {
            throw new RuntimeException('La columna decimal requiere revisión: '.$table.'.'.$field);
        }
        $name = '`'.$field.'`'; $target = '`'.$table.'`';
        $changes = (int)$db->query("SELECT COUNT(*) FROM $target WHERE $name<>ROUND($name,2)")->fetchColumn();
        if (in_array($field, ['quantity','demand','rate','exchange_rate'], true)
            && $db->query("SELECT 1 FROM $target WHERE $name<>0 AND ROUND($name,2)=0 LIMIT 1")->fetchColumn()) {
            throw new RuntimeException($table.'.'.$field.': hay valores no nulos que quedarían en cero. No se aplicó la migración.');
        }
        // Keep the original integer capacity rather than expanding the accepted range.
        $precision = (int)$column['NUMERIC_PRECISION'] - (int)$column['NUMERIC_SCALE'] + 2;
        $maximum = str_repeat('9', $precision-2).'.99';
        if ($db->query("SELECT 1 FROM $target WHERE ABS(ROUND($name,2))>$maximum LIMIT 1")->fetchColumn()) {
            throw new RuntimeException($table.'.'.$field.': el redondeo excedería la capacidad del campo. No se aplicó la migración.');
        }
        $definition = $db->query("SHOW FULL COLUMNS FROM $target WHERE Field=".$db->quote($field))->fetch(PDO::FETCH_ASSOC);
        $sql = $name.' DECIMAL('.$precision.',2)'.(str_contains($column['COLUMN_TYPE'],'unsigned')?' UNSIGNED':'');
        $sql .= $definition['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        if ($definition['Default'] !== null) $sql .= ' DEFAULT '.$db->quote($definition['Default']);
        elseif ($definition['Null'] === 'YES') $sql .= ' DEFAULT NULL';
        if ($definition['Comment'] !== '') $sql .= ' COMMENT '.$db->quote($definition['Comment']);
        $plan[$table][] = ['field'=>$field, 'rounded_rows'=>$changes, 'definition'=>$sql];
    }
    // All checks run before the first ALTER; DDL commits implicitly in MariaDB.
    $result = [];
    foreach ($plan as $table=>$fields) {
        $db->exec('ALTER TABLE `'.$table.'` '.implode(', ',array_map(static fn($field)=>'MODIFY COLUMN '.$field['definition'],$fields)));
        foreach ($fields as $field) $result[] = ['table'=>$table,'field'=>$field['field'],'rounded_rows'=>$field['rounded_rows']];
    }
    return $result;
}

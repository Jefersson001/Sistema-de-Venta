<?php
declare(strict_types=1);

/** Repositories keep their own transactions; a bulk operation encloses them. */
trait TransferTransactions
{
    private int $transferDepth=0;
    protected function transferBatchActive(): bool { return $this->transferDepth>0; }
    public function atomicTransfer(callable $work,bool $preview=false): mixed
    {
        if($this->inTransaction())throw new LogicException('La importación requiere una transacción propia.');
        parent::exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');parent::beginTransaction();$this->transferDepth=1;
        try {
            $result=$work($this);
            if($this->transferDepth!==1)throw new LogicException('Una operación dejó una transacción incompleta.');
            if($preview)parent::rollBack();else parent::commit();return $result;
        } catch(Throwable $e){if(parent::inTransaction())parent::rollBack();throw $e;}
        finally {$this->transferDepth=0;}
    }
    public function beginTransaction(): bool
    {
        if(!$this->transferDepth)return parent::beginTransaction();
        parent::exec('SAVEPOINT transfer_'.$this->transferDepth);$this->transferDepth++;return true;
    }
    public function commit(): bool
    {
        if(!$this->transferDepth)return parent::commit();
        if($this->transferDepth<2)throw new LogicException('Un modelo intentó confirmar la importación completa.');
        $this->transferDepth--;parent::exec('RELEASE SAVEPOINT transfer_'.$this->transferDepth);return true;
    }
    public function rollBack(): bool
    {
        if(!$this->transferDepth)return parent::rollBack();
        if($this->transferDepth<2)throw new LogicException('Un modelo intentó revertir la importación completa.');
        $this->transferDepth--;parent::exec('ROLLBACK TO SAVEPOINT transfer_'.$this->transferDepth);parent::exec('RELEASE SAVEPOINT transfer_'.$this->transferDepth);return true;
    }
}

final class TransferAdminConnection extends PDO
{
    use TransferTransactions;
    public function exec(string $statement): int|false
    {
        if($this->transferBatchActive()&&preg_match('/^SET TRANSACTION ISOLATION LEVEL /i',$statement))return 0;
        return parent::exec($statement);
    }
}

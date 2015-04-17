<?php
namespace SQLiteStructure;

class TableStructure{
    /**
     * @var PDO
     *
     */
    private $pdo;
    private $name;
    private $newColumns;
    private $isDiff= false;
    private $createColumns = array();
    private $fromColumns = array();
    private $toColumns = array();
    public $log = array();

    public function __construct($pdo, $name, $newColumns){
        $this->pdo = $pdo;
        $this->name = $name;
        $this->newColumns = $newColumns;
    }

    private function qCreateColumn($colName, $attrs){
        $type = $attrs['type'];
        $q = $colName.' '.$type.' '.((isset($attrs['null']) && $attrs['null'])?'NULL':'NOT NULL');

        if(isset($attrs['def']))
            $q.=' DEFAULT '.((is_numeric($attrs['def']) || is_bool($attrs['def']))?$attrs['def']:$this->pdo->quote($attrs['def']));
        if(isset($attrs['pk']) && $attrs['pk'])
            $q.=' PRIMARY KEY';

        $this->createColumns[] = $q;
    }

    private function qCopyColumn($from, $to){
        $this->toColumns[] = $to;
        $this->fromColumns[] = $from;
    }

    private function isEqualCollumn($currentCol, $attrs){
        if($attrs['type'] != $currentCol['type'])
            return false;
        if(((isset($attrs['pk']) && $attrs['pk'])?true:false) != $currentCol['pk'])
            return false;
        if(((isset($attrs['null']) && $attrs['null'])) == $currentCol['notnull'])
            return false;
        if(!isset($attrs['def']) && !isset($currentCol['dflt_value']))
            return true;
        if(!isset($attrs['def']) || !isset($currentCol['dflt_value']))
            return false;
        if(((is_numeric($attrs['def']) || is_bool($attrs['def']))?$attrs['def']:$this->pdo->quote($attrs['def'])) != $currentCol['dflt_value'])
            return false;
        return true;
    }
    private function findOldColumn($currentCol){
        $currentColName = $currentCol['name'];
        foreach($this->newColumns as $newColumnName => $newColumn){
            if(isset($newColumn['oldNames'])){
                foreach($newColumn['oldNames'] as $oldName){
                    if($currentColName == $oldName) {
                        $attrs = $newColumn;
                        $this->qCreateColumn($newColumnName, $attrs);
                        $this->qCopyColumn($currentColName, $newColumnName);
                        $l = $currentColName . '=> rename to '.$newColumnName.', ';
                        $this->isDiff = true;
                        if($this->isEqualCollumn($currentCol, $attrs)) {
                            $l .= 'rename';
                        }
                        else{

                            $l .= 'change type from ('.$currentCol['type'].', '.$currentCol['pk'].') to ' . var_export($attrs, true) ;
                        }
                        $this->log[] = $l;
                        unset($this->newColumns[$newColumnName]);
                        return;
                    }
                }
            }
        }
        $this->log[]= $currentColName . '=> remove';
        $this->isDiff = true;
    }


    /**
     * Check if a table exists in the current database.
     *
     * @param PDO $pdo PDO instance connected to a database.
     * @param string $table Table to search for.
     * @return bool TRUE if table exists, FALSE if no table found.
     */
    function tableExists() {

        // Try a select statement against the table
        // Run it in try/catch in case PDO is in ERRMODE_EXCEPTION.
        try {
            $result = $this->pdo->query("SELECT 1 FROM $this->name LIMIT 1");
        } catch (\PDOException $e) {
            // We got an exception == table not found
            return FALSE;
        }

        // Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
        return $result !== FALSE;
    }

    private function query($q, $isExec){
        if ($isExec) {
            try{
                $result = ($this->pdo->exec($q) !== false) ? true : false;
            }
            catch (Exception $e){
                $result = false;
            }
            $this->log[] = $q . ($result ? " OK" : (" ERROR (" . $this->pdo->errorInfo()[2] . ')'));
            return $result;

        } else {
            $this->log[] = $q;
            return NULL;
        }

    }

    public function update($isExec){
        $this->isDiff = false;
        $isExists = $this->tableExists();


        $this->log[] = $isExists ? "table exist" : "table not exist";

        if($this->newColumns == "remove") {
            if($isExists) {
                $this->query("drop table $this->name", $isExec);
            }
            return;
        }

        if($isExists) {
            $currentColumns = $this->pdo->query("PRAGMA table_info('$this->name')")->fetchAll();
            foreach ($currentColumns as $currentCol) {

                $currentColName = $currentCol['name'];
                if (isset($this->newColumns[$currentColName])) {
                    $attrs = $this->newColumns[$currentColName];

                    $this->qCreateColumn($currentColName, $attrs);
                    $this->qCopyColumn($currentColName, $currentColName);

                    if ($this->isEqualCollumn($currentCol, $attrs)) {
                        $this->log[] = $currentColName . '=> no change';
                    } else {
                        $this->isDiff = true;
                        $this->log[] = $currentColName . '=> change type from (' . $currentCol['type'] . ', ' . $currentCol['pk'] . ') to ' . var_export($attrs, true);
                    }
                    unset($this->newColumns[$currentColName]);
                } else {
                    $this->findOldColumn($currentCol);
                }

            }
            if (!$this->isDiff) {
                $this->createColumns = array();
            }
        }
        if(is_array($this->newColumns)) {
            foreach ($this->newColumns as $newColName => $attrs) {
                $this->qCreateColumn($newColName, $attrs);
                $this->log[] = $newColName . '=> add ' . var_export($attrs, true);
            }
        }
        if(!$isExists) {
            $this->query("create table $this->name (".implode(", ", $this->createColumns).")", $isExec);
        }
        else {
            if($this->isDiff) {
                if(false === $this->query("alter table $this->name rename to zzzz_$this->name", $isExec)){
                    $this->pdo->exec("alter table zzzz_$this->name rename to $this->name");
                    return;
                }

                if(false === $this->query("create table $this->name (".implode(", ", $this->createColumns).")", $isExec)){
                    $this->pdo->exec("alter table zzzz_$this->name rename to $this->name");
                    return;
                }

                $q = "insert into $this->name (" . implode(", ", $this->toColumns) . ") select " . implode(", ", $this->fromColumns) . " from zzzz_$this->name";
                if(false === $this->query($q, $isExec)){
                    $this->pdo->exec("drop table $this->name");
                    $this->pdo->exec("alter table zzzz_$this->name rename to $this->name");
                    return;
                }

                $this->query("drop table zzzz_$this->name", $isExec);

            }
            else{
                foreach($this->createColumns as $col){
                    $this->query("alter table $this->name add column ".$col, $isExec);
                }
            }
        }
    }



}
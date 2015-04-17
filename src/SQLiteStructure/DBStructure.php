<?php
namespace SQLiteStructure;

class DBStructure{
    private $pdo;

    public function __construct($pdo_str){
        $this->pdo = new \PDO($pdo_str);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    }

    public function makeUpdateQueries($structure, $isExec, \Closure $log = null){
        foreach($structure['tables'] as $tName => $newColumns) {
            $t = new TableStructure($this->pdo, $tName, $newColumns);
            $t->update($isExec);
            if(isset($log))
                $log($tName, $t->log);

        }

    }
}
<form method="POST">
    <input type="submit" value="Refresh Info" />
</form>
<form method="POST">
    <input type="hidden" name="execute" value="true" />
    <input type="submit" value="Execute changes" />
</form>

<?php

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

$structure = json_decode(file_get_contents('dbStructure.jmm'),true);
$isEcec = isset($_POST['execute']) && $_POST['execute'];

$us = new \SQLiteStructure\DBStructure('sqlite:'.__DIR__.'/database.sqlite');
$us->makeUpdateQueries($structure, $isEcec, function($tName, $log){
    echo '<h4>'.$tName.'</h4>'.implode("</br>", $log).'</br></br>';
});

?>



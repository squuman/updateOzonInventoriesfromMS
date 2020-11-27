<?php

header('Content-Type: text/html; charset=utf-8');
include __DIR__ . '/MSClass.php';
$ms = new MSClass();
$ms->updateInventories();

?>
<?php

/**
 * boot up
 */
include("boot.php");

/**
 * this will echo out create statements for the tables
 * (currently hardcoded mysql) :(
 */
echo orm::show_all_table_create();  
exit();

?>

<?php
date_default_timezone_set("America/New_York");

include("orm.php");

function __autoload($class) { 
	if(in_array($class, orm::$_all_domains)) { 
		include("./domain/$class.php");
	}
}

orm::set_dsn(
    array("default"=>
	array(
	    "dsn"=>"mysql:dbname=mgk;host=127.0.0.1;",
	    "username"=>"root",
	    "password"=>"f4g5F$G%"
	    )
        )
);
orm::set_domain_path("./domain/");

$start = microtime(true);
orm::init();
?>

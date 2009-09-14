<?php

include("boot.php");
orm::add_listener(new listener());
#echo orm::show_all_table_create();  exit();

$p = new Person(array("name"=>"mike", "email"=>"mgkimsal@gmail.com"));
$p->name = "foo";
$p->save();

$b = new Book(array("title"=>"mike's book", "isbn"=>"12355"));
$b->save();
$b2 = new Book(array("title"=>"mike's book #2", "isbn"=>"w4243"));
$b2->save();

echo "person has ".count($p->books)." books\n";
$p->add_to_books($b);
$p->add_to_books($b2);
echo "person has ".count($p->books)." books\n";
echo "person has ".count($p->books)." books\n";
echo "person has ".count($p->books)." books\n";

?>

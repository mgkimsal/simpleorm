<?php

/**
 * boot up
 */
include("boot.php");

/**
 * make a new person object
 */
$p = new Person(array("name"=>"mike", "email"=>"mgkimsal@gmail.com"));
$p->save();

/**
 * make a new book object
 */
$b = new Book(array("title"=>"mike's book", "isbn"=>"12355"));
$b->save();

/**
 * per the person object $_has_many attribute, a person can have many books
 * so we'll make 2
 */
$b2 = new Book(array("title"=>"mike's book #2", "isbn"=>"w4243"));
$b2->save();

/**
 * how many books does the person object have to start with ?
 */
echo "person has ".count($p->books)." books\n";

/**
 * add a book to the person 
 */
$p->add_to_books($b);
echo "person has ".count($p->books)." books\n";

/**
 * add another book
 */
$p->add_to_books($b2);
echo "person has ".count($p->books)." books\n";

?>

<?php
/**
 * Description of mgk
 *
 * @author michael
 */
class person extends orm {

	protected $name = "mike";
	protected $email;
	protected $address;
	public $state;

	protected $_has_many = array("books"=>"book");

	static protected $_types = array("email"=>"Email");
}
?>

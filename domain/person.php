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

	static public function has_many() {
		return array("books"=>"book");
	}

	static public function types() {
		return array("email"=>"Email");
	}

}
?>

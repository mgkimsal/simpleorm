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

	static public function column_types() {
		return array("email"=>"Email");
	}

}
?>

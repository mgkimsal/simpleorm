<?php
/**
 * Description of mgk
 *
 * @author michael
 */
class person extends orm {

	protected $name = "mike";
//	protected $email;
	protected $address;
	protected $state;

	static public function has_many() {
		return array("books"=>"book");
	}

	static public function has_one() {
		return array("profile"=>"profile");
	}


	static public function column_types() {
		return array("email"=>"Email");
	}

}
?>

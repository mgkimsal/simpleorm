<?php
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
class orm {

    static private $path = "";
    static public $_relations = array();
    static public $_all_domains = array();
    public $id = NULL;
    protected $_has_one = array();
    protected $_has_many = array();
    protected $_props = array();
    protected $_dirty = false;
    protected $_cached = array();

    protected $current_dsn = '';
    protected $table_name = '';
    static protected $types = array();
    static protected $_dsn = array();
    static public $_listeners = array();

    /**
     * todo - should the connection be static?
     */
    static protected $_connection = array();


    static public function set_domain_path($path) {
	self::$path = $path;
    }


    public function __construct($args="") {
	if(is_array($args)) {
	    foreach($args as $k=>$v) {
		$this->$k = $v;
	    }
	}
    }

    static public function init() {
	@unlink("./cache");
	if($f = @file_get_contents("./cache")) {
	    $temp = unserialize($f);
	    self::$_all_domains = $temp['domains'];
	    self::$_relations = $temp['relations'];
	} else {

	    $files = glob(self::$path."*php");
	    foreach($files as $file) {
		$class = basename($file, ".php");

		@include($file);

		$props = self::__get_props_for_class($class);
		self::$_relations[$class]['has_many'] = $props['extra']['_has_many'];
		self::$_relations[$class]['has_one'] = $props['extra']['_has_one'];

		foreach($props['extra']['_has_many'] as $k=>$v) {
		    self::$_relations[$v]['owned_by'][] = $class;
		}
		foreach($props['extra']['_has_one'] as $k=>$v) {
		    self::$_relations[$v]['owned_by'][] = $class;
		}

		self::$_all_domains[$class] = $class;
		unset($temp);
	    }

	    $data['relations'] = self::$_relations;
	    $data['domains'] = self::$_all_domains;
	    file_put_contents("./cache", serialize($data));

	}
    }

    static public function __get_props_for_class($class) {
	$props = array();
	$f = new ReflectionClass($class);
	$properties = $f->getProperties();
	$props['extra'] = $f->getDefaultProperties();
	foreach($properties as $prop) {
	    if($prop->getDeclaringClass()->getName()==$class) {
		$name = $prop->getName();
		if(substr($name,0,1)!='_') {
		    $props['regular'][$prop->getName()] = $prop;
		}
	    }
	}


	return $props;
    }



    static public function show_all_table_create() {
	ob_start();
	foreach(self::$_all_domains as $domain) {
	    $temp = new $domain();
	    echo $temp->__getSQL();
	    echo "\n";
	}
	$temp = ob_get_contents();
	ob_end_clean();
	return $temp;
    }

    public function __get($name) {
	if(method_exists($this, "get$name")) {
	    return $this->{"get$name"}();
	} else {
	    /**
	     * todo
	     * this will load up via SQL on every call to this property - not good
	     */
	    if(array_key_exists($name, $this->_has_many)) {
#				return $this->load_related_many($this->_has_many[$name]);
		if($this->_cached[$name]==null) {
		    $this->_cached[$name] = $this->load_related_many($this->_has_many[$name]);
		}
		return $this->_cached[$name];
	    } elseif (array_key_exists($name, $this->_has_one)) {
		if($this->_cached[$name]==null) {
		    $this->_cached[$name] = $this->load_related_one($this->_has_many[$name]);
		}
		return $this->_cached[$name];
	    } else {
		return $this->$name;
	    }
	}
    }

    public function __set($name, $value) {
	$this->_dirty = true;
	$this->cached[$name] = null;
	if(method_exists($this, "set$name")) {
	    return $this->{"set$name"}($value);
	} else {
	    $currentValue = $this->$name;
	    if($currentValue == $value) {
		return true;
	    } else {
		$this->_dirty = true;
		$this->cached[$name] = null;
		$this->notify_listeners($name, $currentValue, $value);
		return $this->$name = $value;
	    }
	}
    }

    static function add_listener(ormListener $listener) {
	self::$_listeners[] = $listener;
    }

    public function notify_listeners($propertyName, $oldValue, $newValue) {
	foreach(self::$_listeners as $l) {
	    $l->notifyPropertyChanged($propertyName, $oldValue, $newValue);
	}
    }


    public function __get_props() {
	$currentClass = get_class($this);
	$this->table_name = $currentClass;
	if(!$this->_props) {
	    $props = array();
	    $f = new ReflectionClass($currentClass);
	    $return = $f->getProperties();
	    foreach($return as $prop) {
		if($prop->getDeclaringClass()->getName()==$currentClass) {
		    $name = $prop->getName();
		    if(substr($name,0,1)!='_') {
			$this->_props[$prop->getName()] = $prop;
		    }
		}
	    }

	    $relations = orm::$_relations[$currentClass];
	    if(!empty($relations['owned_by'])) {
		foreach($relations['owned_by'] as $owner) {
		    $this->_props[$owner."_id"] = "relation";
		}
	    }
	}
	return $this->_props;
    }

    public function __getSQL() {
	$sql = "";

	$currentClass = get_class($this);
	$props = $this->__get_props();
	$finalTypes = array();

	eval("\$types={$currentClass}::\$types;");

	foreach($props as $key=>$prop) {
	    if(is_object($prop)) {
		$tempType = $types[$prop->getName()];
		$finalTypes[$prop->getName()] = ($tempType) ? $tempType : "string";
	    } else {
		if($prop=="related") {
		    $finalTypes[$key] = "int";
		}
	    }
	}


	/**
	 * go through relations
	 */
	$foreign_keys = array();
	$f_keys = '';
	$relations = orm::$_relations[$currentClass];
	if(!empty($relations['owned_by'])) {
	    foreach($relations['owned_by'] as $owner) {
		$finalTypes[$owner."_id"] = "int";
		$foreign_keys[] =  " FOREIGN KEY ($owner"."_id) REFERENCES $owner(id) ";
	    }
	}
	if(count($foreign_keys)>0) {
	    $f_keys = ",\n".implode(",\n", $foreign_keys);
	}



	/**
	 * build SQL
	 */
	$sql = "create table $currentClass ( \n";

	$sqlDefs[] = "id int(11) auto_increment primary key";
	$sqlDefs[] = "dateCreated datetime NULL";
	$sqlDefs[] = "dateUpdated datetime NULL";

	foreach($finalTypes as $propName=>$type) {
	    switch($type) {
		case "email":
		    $sqlDefs[] = "$propName varchar(255)";
		    break;
		case "int":
		    $sqlDefs[] = "$propName int(11) NULL";
		    break;
		case "text":
		    $sqlDefs[] = "$propName longtext NULL";
		    break;
		case "lob":
		    $sqlDefs[] = "$propName longblob NULL";
		    break;
		case "date":
		    $sqlDefs[] = "$propName datetime NULL";
		    break;
		default:
		    $sqlDefs[] = "$propName varchar(255)";
		    break;

	    }
	}

	$sql .= implode(",\n",$sqlDefs);
	$sql .= $f_keys;
	$sql .= "\n) ENGINE=INNODB;";
	return $sql;
    }


    public static function set_dsn($dsn) {
	self::$_dsn = $dsn;
    }

    /**
     *
     * @param string $dsn
     * @return PDO
     */
    public function get_connection($dsn="default") {
	$this->current_dsn = $dsn;
	if(!self::$_connection[$dsn]) {
	    self::$_connection[$dsn] = new PDO(self::$_dsn[$dsn]['dsn'],
		self::$_dsn[$dsn]['username'],
		self::$_dsn[$dsn]['password'],
		self::$_dsn[$dsn]['options']
	    );
	}
	return self::$_connection[$dsn];
    }


    public function load($id) {
	$props = $this->__get_props();
	$sql = "select * from ".$this->table_name." where id=".(int)$id;
	echo $sql."\n";
	$this->get_one($sql);
	return true;
    }

    static public function get($name,$id) {
	$temp = new $name();
	if($temp->load($id)) {
	    return $temp;
	} else {
	    return null;
	}
    }

    public function get_one($sql) {
	$conn = $this->get_connection();
	$rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	if($rows[0]) {
	    foreach($rows[0] as $k=>$v) {
		$this->$k = $v;
	    }
	    $this->_dirty = false;
	    return true;
	} else {
	    return false;
	}
	return true;
    }

    public function save() {
	/**
	 * if id is null, we're inserting, else updating
	 */
	$props = $this->__get_props();
	$p = array_keys($props);
	$vals = array();
	if($this->id === null) {
	    $sql = "insert into ".$this->table_name." ";
	    foreach($p as $key) {
		$vals[] = $this->$key;
	    }
	    $p[] = "dateCreated";
	    $vals[] = date("c");
	    $sql .= "(".implode(",",$p).") values ";
	    $sql .= "(".implode(",", array_fill(0,count($p),"?" )).")";

	} else {
	    if($this->_dirty == false ) {
		return true;
	    }
	    $sql = "update ".$this->table_name." set ";
	    foreach($p as $key) {
		$keys[] = "$key=?";
		$vals[] = $this->$key;
	    }
	    $keys[] = "dateUpdated=?";
	    $vals[] = date("c");
	    $sql .= implode(",", $keys)." where id=".(int)$this->id;
	}

	$conn = $this->get_connection();

	$st = $conn->prepare($sql);
	$st->execute($vals);
	$this->_dirty = false;
	$id = $conn->lastInsertId();
	if($id>0) {
	    $this->id = $id;
	}
    }

    public function load_related_many($table) {
	$this->__get_props();
	if($this->id===null) {
	    throw new Exception("id is null");
	}

	$sql = "select id from $table where ".$this->table_name."_id = ".$this->id;
	echo $sql."\n";
	$conn = $this->get_connection();
	$all = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	foreach($all as $a) {
	    $temp = new $table();
	    $temp->load($a['id']);
	    $related[] = $temp;
	}
	return $related;
    }

    public function load_related_one($table) {
	$this->__get_props();
	if($this->id===null) {
	    throw new Exception("id is null");
	}
	$sql = "select id from $table where ".$this->table_name."_id = ".$this->id;
	echo $sql."\n";
	$conn = $this->get_connection();
	$all = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	$temp = new $table();
	$temp->load($a[0]['id']);
	return $temp;
    }


    public function __call($method,$args) {
	if(substr($method,0,7)=="add_to_") {
	    $target = substr($method,7);
	    $this->_add_to($target,$args[0]);
	}
	if(substr($method,0,8)=="find_by_") {
	    $target = substr($method,8);
	    $this->_find_by($target,$args);
	}

    }

    /**
     * magic find_by methods
     */
    public function _find_by($col, $args) {

    }

    /**
     * add_to magic method
     * find relationship in hasone or hasmany
     */
    private function _add_to($target,$object) {

	$key = $this->table_name."_id";
	echo "adding ".get_class($object)." id ".$object->id." to ".$this->table_name." ".$this->id."\n";
	$object->$key = $this->id;
	$object->save();
	// $this->$target = array_merge($this->$target, array($object));
	// why don't I need to add it in with the array_merge?
	// cause the magic __get on $target auto-reloads it???
    }

}

/**
 * helper idea
 */
interface ormListener {
    public function notifyPropertyChanged($propertyName, $oldValue, $newValue);
}


/**
 * get notified of changes
 * for a logger maybe?
 */
class listener implements ormListener {
    public function notifyPropertyChanged($propertyName, $oldValue, $newValue) {
	echo "property $propertyName was changed from $oldValue to $newValue\n-------\n";
    }

}

/**
 * not used, just useful maybe???
 */
function parseCamelCase($string) {
    return preg_replace('/(?<=[a-z])(?=[A-Z])/',' ',$string);
}
?>

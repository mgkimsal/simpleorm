<?php

class orm {

    public $id = NULL;
    public $date_created = NULL;
    public $date_updated = NULL;
    protected $__dirty = false;
    protected $__cached = array();

    static private $__path = "";
    static public $__relations = array();
    static public $__constraints = array();
    static public $__all_domains = array();
    static private $__indent = 0;

    protected $__current_dsn = '';
    protected $__table_name = '';
    static protected $_dsn = array();
    static public $_listeners = array();

    /**
     * todo - should the connection be static?
     */
    static protected $_connection = array();


    /**
     * default hasMany relationship
     * used to influence schema generation
     */
    static public function has_many() {
	return array();
    }

    /**
     * default hasOne relationship
     * used to influence schema generation
     */
    static public function has_one() {
	return array();
    }

    /**
     * default constraints mapping
     * used for validation testing
     */
    static public function constraints() {
	return array();
    }

    /**
     * default types mapping
     * used to influence schema generation
     * by default, all properties are assumed to be 'strings'
     * override those in this array
     *
     * Example:
     * array(
     *	"birthday"=>"date",
     *	"numFollowers"=>"int",
     *	"price"=>"float"
     * );
     */
    static public function column_types() {
	return array();
    }


    static public function set_domain_path($path) {
	self::$__path = $path;
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
	    self::$__all_domains = $temp['domains'];
	    self::$__relations = $temp['relations'];
	} else {

	    $files = glob(self::$__path."*php");

	    foreach($files as $file) {
		$class = basename($file, ".php");
		include($file);

		$props = self::__get_props_for_class($class);
		eval("self::\$__relations[$class]['has_many'] = $class::has_many();");
		eval("self::\$__relations[$class]['has_one'] = $class::has_one();");
		eval("self::\$__constraints[$class] = $class::constraints();");
//		self::$__relations[$class]['has_one'] = {$class}::has_one();
//		self::$__constraints[$class] = {$class}::constraints();

		foreach(self::$__relations[$class]['has_many'] as $k=>$v) {
		    self::$__relations[$v]['owned_by'][] = $class;
		}
		foreach(self::$__relations[$class]['has_one'] as $k=>$v) {
		    self::$__relations[$v]['owned_by'][] = $class;
		}
		self::$__all_domains[$class] = $class;
	    }

	    $data['relations'] = self::$__relations;
	    $data['domains'] = self::$__all_domains;
	    
	    file_put_contents("./cache", serialize($data));

	}
    }

    static private function __get_props_for_class($class) {
	$props = array();
	$f = new ReflectionClass($class);
	$properties = $f->getProperties();
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
	foreach(self::$__all_domains as $domain) {
	    $temp = new $domain();
	    echo $temp->__getSQL();
	    echo "\n";
	}
	$temp = ob_get_contents();
	ob_end_clean();
	return $temp;
    }

	protected function clear_cache($propertyName) { 
		$this->__cached[$propertyName] = null;
	}

    public function __get($name) {
	if(method_exists($this, "get$name")) {
	    return $this->{"get$name"}();
	} else {
	    /**
	     * todo
	     * this will load up via SQL on every call to this property - not good
	     */
	    if(array_key_exists($name, self::$__relations[$this->__table_name]['has_many'])) {
		if($this->__cached[$name]==null) { 
		    $this->__cached[$name] = $this->load_related_many(self::$__relations[$this->__table_name]['has_many'][$name]);
		}
		return $this->__cached[$name];
	    } elseif (array_key_exists($name, self::$__relations[$this->__table_name]['has_one'])) {
		if($this->__cached[$name]==null ) { 
		    $this->__cached[$name] = $this->load_related_one(self::$__relations[$this->__table_name]['has_one'][$name]);
		}
		return $this->__cached[$name];
	    } else {
		return $this->$name;
	    }
	}
    }

    public function __set($name, $value) {
	$this->__dirty = true;
	$this->cached[$name] = null;
	if(method_exists($this, "set$name")) {
	    return $this->{"set$name"}($value);
	} else {
	    $currentValue = $this->$name;
	    if($currentValue == $value) {
		return true;
	    } else {
		$this->__dirty = true;
		$this->cached[$name] = null;
		$this->notify_listeners($name, $currentValue, $value);
		return $this->$name = $value;
	    }
	}
    }


    public function __get_props() {
	static $props = array();
	$currentClass = get_class($this);
	$this->__table_name = $currentClass;
	if(count($props)==0) {
	    $f = new ReflectionClass($currentClass);
	    $temp = $f->getProperties();
	    foreach($temp as $prop) {
		if($prop->getDeclaringClass()->getName()==$currentClass) {
		    $name = $prop->getName();
		    if(substr($name,0,1)!='_') {
			$props[$prop->getName()] = $prop;
		    }
		}
	    }

	    $relations = orm::$__relations[$currentClass];
	    if(!empty($relations['owned_by'])) {
		foreach($relations['owned_by'] as $owner) {
		    $props[$owner."_id"] = "relation";
		}
	    }
	}
	return $props;
    }

    public function __getSQL() {
	$sql = "";

	$currentClass = get_class($this);
	$props = $this->__get_props();
	$finalTypes = array();

	eval ("\$types = $currentClass::column_types();");

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
	$relations = orm::$__relations[$currentClass];
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
	$sql = "drop table if exists $currentClass;\n";
	$sql .= "create table $currentClass ( \n";

	$sqlDefs[] = "id int(11) auto_increment primary key";
	$sqlDefs[] = "date_created datetime NULL";
	$sqlDefs[] = "date_updated datetime NULL";

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
    public function &get_connection($dsn="default") {
	$this->__current_dsn = $dsn;
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
	$sql = "select * from ".$this->__table_name." where id=".(int)$id;
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
	    $this->__dirty = false;
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
	    $sql = "insert into ".$this->__table_name." ";
	    foreach($p as $key) {
		$vals[] = $this->$key;
	    }
	    $p[] = "date_created";
	    $vals[] = date("c");
	    $sql .= "(".implode(",",$p).") values ";
	    $sql .= "(".implode(",", array_fill(0,count($p),"?" )).")";
		$this->date_created = date("c");

	} else {
	    if($this->__dirty == false ) {
		return true;
	    }
	    $sql = "update ".$this->__table_name." set ";
	    foreach($p as $key) {
		$keys[] = "$key=?";
		$vals[] = $this->$key;
	    }
	    $keys[] = "date_updated=?";
	    $vals[] = date("c");
	    $sql .= implode(",", $keys)." where id=".(int)$this->id;
		$this->date_updated = date("c");
	}

	$conn = $this->get_connection();

	$st = $conn->prepare($sql);
	$st->execute($vals);
	$this->__dirty = false;
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

	$sql = "select id from $table where ".$this->__table_name."_id = ".$this->id;
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
	$sql = "select id from $table where ".$this->__table_name."_id = ".$this->id;
	$conn = $this->get_connection();
	$all = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	$temp = new $table();
	$temp->load($all[0]['id']);
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

	$key = $this->__table_name."_id";
	$object->$key = $this->id;
	$object->save();
	$this->clear_cache($target);
	// $this->$target = array_merge($this->$target, array($object));
	// why don't I need to add it in with the array_merge?
	// cause the magic __get on $target auto-reloads it???
    }


/** 
 * do a print_r style dump of stuff
 */
	public function debug() {
		$tabs = str_repeat("     ",(self::$__indent));
		$properties = $this->__get_props();
		$relations = self::$__relations[$this->__table_name];
		echo "\n$tabs"."Table:".$this->__table_name;
		echo "\n$tabs"."ID:".$this->id;
		echo "\n$tabs"."Created:".$this->date_created;
		echo "\n$tabs"."Updated:".$this->date_updated;
		echo "\n$tabs"."default properties\n$tabs========\n";
		foreach($properties as $key=>$val) { 
			echo $tabs."$key:".$this->$key."\n";
		}
		if(count($relations['has_many'])>0) { 
			echo "\n$tabs"."has many\n$tabs========\n";

			self::$__indent++;
			$tabs = str_repeat("     ",(self::$__indent));
			foreach($relations['has_many'] as $key=>$val) {
				echo $tabs."$key: ";
				$x = $this->$key;
				if(is_array($x)) { 
					foreach($x as $temp) { 
						$temp->debug();
					}
				} elseif (is_object($x)) {
					$x->debug();
				}
				echo "\n";
			}
			self::$__indent--;
			$tabs = str_repeat("     ",(self::$__indent));
		}
		if(count($relations['has_one'])>0) { 
			echo "\n$tabs"."has one\n$tabs===========\n";

			self::$__indent++;
			$tabs = str_repeat("     ",(self::$__indent));
			foreach($relations['has_one'] as $key=>$val) {
				echo $tabs."$key: ";
				$x = $this->$key;
				if(is_array($x)) { 
					foreach($x as $temp) { 
						$temp->debug();
					}
				} elseif (is_object($x)) {
					$x->debug();
				}
				echo "\n";
			}
			self::$__indent--;
			$tabs = str_repeat("     ",(self::$__indent));
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

?>

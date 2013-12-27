<?php
/*
 
record.class.php
 
this is the base class.  it's not TOTALLY finished, but it's getting pretty close.
also, the RecordCollection object is here, which is what is returned by a ->select().
 
main things to know about using this is that
1. it's meant to use method chaining, like jQuery.
2. most methods take many different types of input and numbers of arguments, jQuery style.
3. the RecordCollection works similarly to collections of jQuery objects.
4. class names are used in lieu of table names.
5. joins are config'd on an object ($info) and have names.  see the news class.
6. there still may be a few print_rs and echos hangin around.
 
main feature coming up is basic schema management via the static $info property!  can show you this code if you want.
 
*/

class RecordCollection extends ArrayIterator {
   
    private $record;
   
    public function __construct($record, $array_of_objects = array()) {
        $this->record = $record;
        parent::__construct($array_of_objects, ArrayObject::STD_PROP_LIST);
    }
   
    //public function offsetSet($key, $value) {
    //    throw new Exception();
   //}
   
    public function __get($property) {
        return array_map(function($entry) use ($property) {
                            return $entry->{$property};
                         },
                        $this->getArrayCopy());
    }
   
    public function __set($property, $value) {
        $this->record->{$property} = $value;
        foreach ($this as &$entry) {
            $entry->{$property} = $value;
        }
    }
   
    public function __call($method, $arguments) {
        return call_user_func_array(array($this->record, $method), $arguments);
    }
 
}
 
class Record extends ArrayIterator {
   
    private $user, $password, $dsn;
   
    private $wheres = array();
    private $orderby;
    private $update_fields = array();
    private $sql;
    private $joins = array();
    private $fields = array();
    private $pending_dynamics = array();
    static $info;
   
    // set everything up, set wheres
    public function __construct() {
        // make sure we have a legit Record
        if (empty(static::$info)) {
            throw new Exception();
        }
       
       
        $this->dsn = 'mysql:dbname=???;host=???';
        $this->user = '???';
        $this->password = '???';
       
        static::$info['fields'][static::$info['primary_key']]['primary_key'] = TRUE;
       
        // make the ArrayIterator
        parent::__construct(array(), ArrayObject::STD_PROP_LIST);
    }
    public function see_fields() { print_r(static::$info); }
   
    public function __invoke($id, $fields = array()) {
        return $this->grab($id, $fields);
    }
   
    public function offsetGet($prop) {
        if (isset(static::$info['fields'][$prop])  ||
            isset(static::$info['friends'][$prop]) ||
            isset(static::$info['family'][$prop])  ||
            isset(static::$info['dynamics'][$prop])  ) {
           
            return parent::offsetGet($prop);
       
        } else {
            throw new Exception();
        }
    }
   
    // return field as property
    public function __get($prop) {
        return $this->offsetGet($prop);
    }
   
    public function offsetSet($prop, $value) {
        if (isset(static::$info['fields'][$prop])  ||
            isset(static::$info['friends'][$prop]) ||
            isset(static::$info['family'][$prop])  ||
            isset(static::$info['dynamics'][$prop])  ) {
           
            if (isset(static::$info['fields'][$prop]))
                $this->update_fields[$prop] = TRUE;
               
            parent::offsetSet($prop, $value);
           
        } else {
            throw new Exception();
        }
    }
   
    // set field as property
    public function __set($prop, $value) {
        $this->offsetSet($prop, $value);
    }
   
    // create new record in db, returns its primary key
    public function create() {
        $props = array();
        $values = array();
        $qs = array();
       
        foreach ($this->update_fields as $prop => $update) {
            if ($update) {
                $props[] = $prop;
                $values[] = $this->offsetGet($prop);
                $qs[] = '?';
            }
            unset($this->update_fields[$prop]);
        }
       
        if (count($props)) {
            $props = implode(', ', $props);
            $props = "($props)";
           
            $qs = implode(', ', $qs);
            $qs = "($qs)";
           
            $sql = "INSERT INTO `".static::$info['table']."` $props VALUES $qs";
           
            try {
                $dbh = new PDO($this->dsn, $this->user, $this->password);
            } catch (PDOException $e) {
                echo 'Connection failed: ' . $e->getMessage();
            }
           
            $stmt = $dbh->prepare($sql);
            $success = $stmt->execute($values);
           
            if ($success) {
               
                foreach ($this->update_fields as $prop => $update) {
                    unset($this->update_fields[$prop]);
                }
               
                return $dbh->lastInsertId();
           
            } else {
                return FALSE;
            }
           
        } else {
            return FALSE;
        }
       
    }
   
    // update existing records in db, returns rows affected
    public function update() {
        $base_clause = "UPDATE `".static::$info['table']."`";
       
        $set_stuff = $this->set_clause();
        $set_clause = $set_stuff[0];
 
        $where_stuff = $this->where_clause();
        $where_clause = $where_stuff[0];
       
        $orderby_clause = '';
        $limit_clause = '';
       
        $sql = implode(' ', array($base_clause, $set_clause, $where_clause, $orderby_clause, $limit_clause));        
       
        if ($set_stuff) {
           
            try {
                $dbh = new PDO($this->dsn, $this->user, $this->password);
            } catch (PDOException $e) {
                echo 'Connection failed: ' . $e->getMessage();
            }
           
            $values = array_merge($set_stuff[1], $where_stuff[1]);
           
            $stmt = $dbh->prepare($sql);
            $success = $stmt->execute($values);
           
            if ($success) {
                return $stmt->rowCount();
            } else {
                return FALSE;
            }
           
        } else {
            return FALSE;
        }
    }
   
    // remove records from db, returns rows affected
    public function delete() {
        $base_clause = "DELETE FROM `".static::$info['table']."`";
       
        $where_stuff = $this->where_clause();
        $where_clause = $where_stuff[0];
        $values = $where_stuff[1];
 
        $orderby_clause = '';
        $limit_clause = '';
       
       
        $sql = implode(' ', array($base_clause, $where_clause, $orderby_clause, $limit_clause));
        echo $sql;
       
        try {
            $dbh = new PDO($this->dsn, $this->user, $this->password);
        } catch (PDOException $e) {
            echo 'Connection failed: ' . $e->getMessage();
        }
       
        $stmt = $dbh->prepare($sql);
        $success = $stmt->execute($values);
       
        if ($success) {
            return $stmt->rowCount();
        } else {
            return FALSE;
        }
   
    }
   
    // select record by its primary key
    public function grab($id, $fields = array()) {
       
        $this->joins = array();
        $this->wheres = array();
       
        $this->where(static::$info['primary_key'], $id);
        return $this->select($fields)->current();
   
    }
   
    // select many records into an OopsCollection.  takes array or string.
    public function select($fields = array()) {
        if (func_num_args() > 1) {
            $fields = func_get_args();
        } else {
            $fields = (array) $fields;
        }
       
        foreach ($fields as $field) {
            if (is_string($field)) {
                $this->fields[$field] = TRUE;
            } else {
                throw new Exception();
            }
        }
       
        $fields_clause = $this->fields_clause();
        $base_clause = "SELECT $fields_clause FROM " . static::$info['table'];
        $where_stuff = $this->where_clause();
        $where_clause = $where_stuff[0];
        $join_clause = $this->join_clause();
        $having_clause = '';
        $orderby_clause = '';
        $limit_clause = '';
       
        if ($fields_clause) {
            $sql = implode(' ', array($base_clause, $join_clause, $where_clause, $groupby_clause, $having_clause, $orderby_clause, $limit_clause));
           
            try {
                $dbh = new PDO($this->dsn, $this->user, $this->password);
            } catch (PDOException $e) {
                echo 'Connection failed: ' . $e->getMessage();
            }
           
            $values = $where_stuff[1];
           
            $stmt = $dbh->prepare($sql);
            $success = $stmt->execute($values);
           
            if ($success) {
               
                $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
               
                $results = array();
                foreach ($raw_results as &$result_arr) {
                    $primary_key = static::$info['primary_key'];
                    $this_class = get_called_class();
                   
                    $result_object = new $this_class();
                   
                    foreach ($this->fields as $field => $yes) {
                        $value = $result_arr[$field];
                        $result_object->{$field} = $value;
                    }
                   
                    foreach ($this->pending_dynamics as $name) {
                       
                        $dynamic_function = static::$info['dynamics'][$name]['call'];
                        $dynamic_args = static::$info['dynamics'][$name]['params'];
                       
                        foreach ($dynamic_args as &$arg) {
                            $arg = $result_arr[$arg];
                        }
                       
                        $result_object->{$name} = $result_object->{$dynamic_function}($dynamic_args);
                       
                    }
                   
                   
                    $result_object->where($primary_key, $result_arr[$primary_key]);
                    $results[$result_object->{$primary_key}] = $result_object;
                }
               
                return new RecordCollection($this, $results);
           
            } else {
                return FALSE;
            }
 
 
        } else {
            return FALSE;
        }
    }
   
    private function fields_clause() {
       
        $fields = array();
        $local_table = static::$info['table'];
       
        if (empty($this->fields)) {
           
            $fields[] = "$local_table.*";
           
        } else {
           
            $selecting_key = FALSE;
           
            foreach ($this->fields as $field => $yes) {
                $fields[] = $this->add_field($field);
               
                if ($field == static::$info['primary_key'])
                    $selecting_key = TRUE;
                   
            }
           
            if (!$selecting_key) {
                $this->fields[static::$info['primary_key']] = TRUE;
                $fields[] = $this->add_field(static::$info['primary_key']);
            }
           
        }
       
        return implode(', ', $fields);
    }
   
    private function add_field($field) {
        $local_table = static::$info['table'];
       
        if (isset(static::$info['fields'][$field])) {
           
            return "$local_table.$field";
           
        } else if (isset(static::$info['family'][$field])) {
           
            return static::$info['family'][$field] . " AS $field";
           
        } else if (isset(static::$info['friends'][$field])) {
           
            $friend_field = $this->get_friend_field($field, TRUE); //adding joins
           
            return "$friend_field AS $field";
           
        } else if (isset(static::$info['dynamics'][$field])) {
           
            $this->pending_dynamics[] = $field;
            $dyn_fields = array();
 
            foreach (static::$info['dynamics'][$field]['params'] as $param) {
                $dyn_fields[] = $this->add_field($param);
            }
           
            return implode(', ', $dyn_fields);
           
        } else {
            return $field;
        }
    }
    // do these exist?
    public function exists() {}
   
    // custom orderby clause, takes array or string.  sanitize!
    public function orderby($order) {}
   
    // custom where clause, takes array or string.  sanitize!
    public function where($wheres) {
        $num_args = func_num_args();
       
        if ($num_args == 1) {
           
            if ( is_array($wheres) ) {
               
                foreach ($wheres as $condition => $params) {
                    if ( is_int($condition) ) {
                       
                        if ( is_string($params) ) {
                            $this->wheres[] = $params;
                        } else {
                            throw new Exception();
                        }
                       
                    } else {
                       
                        $params = (array) $params;
                       
                        $condition_parts = explode(' ', $condition);
                        foreach ($condition_parts as &$part) {
                            if ( isset(static::$info['fields'][$part]) ) {
                                $part = static::$info['table'] . ".$part";
                            } elseif ( isset(static::$info['friends'][$part]) ) {                                
                               
                                $part = $this->get_friend_field($part);
                               
                            }
                        }
                        $condition = implode(' ', $condition_parts);
                       
                        if (preg_match('/^[A-Z0-9\$\._`]+$/i', $condition)) {
                            $condition = "$condition = ?";
                        }
                       
                        if (substr_count($condition, '?') == count($params)) {
                            $this->wheres[] = array($condition, $params);
                        } else {
                            throw new Exception();
                        }
                       
                    }
                }
               
            } else if ( is_string($wheres) ) {
               
                $this->wheres[] = $wheres;
               
            } else {
                throw new Exception();
            }
           
        } else if ( $num_args % 2 == 0 ) {
           
            $arguments = func_get_args();
            for ($i = 0; $i < $num_args; $i = $i+2) {
               
                $condition = $arguments[$i];
                $params = (array) $arguments[$i+1];
               
                $condition_parts = explode(' ', $condition);
                foreach ($condition_parts as &$part) {
                    if ( isset(static::$info['fields'][$part]) ) {
                        $part = static::$info['table'] . ".$part";
                    } elseif ( isset(static::$info['friends'][$part]) ) {                                
                       
                        $part = $this->get_friend_field($part);
                       
                    }
                }
                $condition = implode(' ', $condition_parts);
                       
                if (preg_match('/^[A-Z0-9\$\._`]+$/i', $condition)) {
                    $condition = "$condition = ?";
                }
               
                if (substr_count($condition, '?') == count($params)) {
                    $this->wheres[] = array($condition, $params);
                } else {
                    echo 'c';
                    print_r($condition);
                    echo 'p';
                    print_r($params);
                    throw new Exception();
                }
            }
           
        } else {
            throw new Exception();
        }
       
        return $this;  
    }
   
    private function get_friend_field($part, $add_joins = FALSE) {
       
        // determine friend class for table, field instead of alias.
        // where does not support aliases!
        $friend_class = get_called_class();
       
        foreach (static::$info['friends'][$part]['joins'] as $join) {
            if ($add_joins)
                $this->join($join);
            $friend_class = $join['neighbor'];
        }
       
        if (is_array(static::$info['friends'][$part]['field'])) {
           
            $friend_class = static::$info['friends'][$part]['field'][0];
            $friend_field = static::$info['friends'][$part]['field'][1];
           
        } else if(is_string(static::$info['friends'][$part]['field'])) {
           
            $friend_field = static::$info['friends'][$part]['field'];
           
        } else {
            throw new Exception();
        }
       
        $friend_table = $friend_class::$info['table'];
       
        return "$friend_table.$friend_field";
       
    }
   
    public function join($join) {
        $num_args = func_num_args();
       
        if ($num_args == 1) {
           
            if ( is_string($join) ) {
                $join = static::$info['joins'][$join];
            }
           
            if ( is_array($join) ) {
                $join_array = array_filter(array(
                                                 $join['neighbor'],
                                                 $join['neighbor_field'],
                                                 $join['type'],
                                                 $join['local_field'],
                                                 $join['local']
                                                 ));
            } else {
                throw new Exception();
            }
           
        } else {
            $join_array = func_get_args();
        }
       
        $num_args = count($join_array);
       
        switch ($num_args) {
           
            case 2:
                    // neighbor && neighbor_field
                if (isset($join_array[0]) && isset($join_array[1])) {
                    $join_array[2] = 'LEFT'; // type is LEFT
                    $join_array[3] = $join_array[1]; // local_field is neighbor_field
                    $join_array[4] = get_called_class(); // local is this class
                } else {
                    throw new Exception();
                }
               
                break;
            case 3:
                    // neighbor && neighbor_field && type
                if (isset($join_array[0]) && isset($join_array[1]) && isset($join_array[2])) {
                    $join_array[3] = $join_array[1]; // local_field is neighbor_field
                    $join_array[4] = get_called_class(); // local is this class
                } else {
                    throw new Exception();
                }
               
                break;
            case 4:
                    // neighbor && neighbor_field && type && local_field
                if (isset($join_array[0]) && isset($join_array[1]) && isset($join_array[2]) && isset($join_array[3])) {
                    $join_array[4] = get_called_class(); // local is this class
                } else {
                    throw new Exception();
                }
               
                break;
            case 5:
                    // just make sure everything is set
                if ( ! (isset($join_array[0]) && isset($join_array[1]) && isset($join_array[2]) && isset($join_array[3]) && isset($join_array[4]) )) {
                    throw new Exception();
                }
                break;
            default:
                throw new Exception();
            break;
           
        }
       
        $this->joins[] = array(
                               'neighbor'       => $join_array[0],
                               'neighbor_field' => $join_array[1],
                               'type'           => $join_array[2],
                               'local_field'    => $join_array[3],
                               'local'          => $join_array[4],                              
                               );
       
        return $this;
    }    
   
    public function join_clause() {
   
        $join_strings = array();
       
        foreach ($this->joins as $join) {
            $neighbor_class = $join['neighbor'];
            $local_class    = $join['local'];
           
            $neighbor_info = $neighbor_class::$info;
            $local_info    = $local_class::$info;
           
            $local_table    = $local_info['table'];
            $local_field    = $join['local_field'];
            $neighbor_table = $neighbor_info['table'];
            $neighbor_field = $join['neighbor_field'];
            $type           = strtoupper($join['type']);
           
            $join_strings[] = "$type JOIN $neighbor_table ON $neighbor_table.$neighbor_field = $local_table.$local_field";
        }
       
        $join_clause = implode(' ', $join_strings);
       
        return $join_clause;
    }
   
    // update/create the model in db land
    public function model($do_this = FALSE) {}
   
    private function where_clause() {
       
        // make where clause
        $where_conditions = array();
        $params = array();
       
        foreach ($this->wheres as $where) {
           
            if ( is_string($where) ) {
                $where_conditions[] = "($where)";
            } else if ( is_array($where) ) {
               
                $where_conditions[] = "({$where[0]})";
                $params = array_merge($params, $where[1]);
               
            }
           
        }
       
        if (!empty($where_conditions)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
        } else {
            $where_sql = 'WHERE 1=0';
        }
       
        return array($where_sql, $params);
   
    }
   
    private function set_clause() {
        foreach ($this->update_fields as $prop => $update) {
            if ($update) {
                $props[] = "$prop = ?";
                $values[] = $this->offsetGet($prop);
            }
        }
       
        if (count($props)) {
           
            $props = 'SET ' . implode(', ', $props);
            return array($props, $values);
           
        } else {
            return FALSE;
        }
    }
   
}
 
?>

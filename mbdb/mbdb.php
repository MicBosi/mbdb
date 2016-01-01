<?php

/* ============================================================================

    MBDB is a minimalistic PHP wrapper around mysqli.

    License: https://opensource.org/licenses/MIT

    Author: Michele Bosi

============================================================================ */

# http://php.net/manual/en/language.exceptions.extending.php
class MBDBException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) 
    {
        parent::__construct($message, $code, $previous);
    }

    // custom string representation of object
    public function __toString() 
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

////////////////////////////////////////////////////////////////////////////////
// ResultIterator
////////////////////////////////////////////////////////////////////////////////

class ResultIterator implements Iterator, Countable 
{
    private $last_result = NULL;

    private $data = NULL;

    private $can_rewind = TRUE;

    private $column = TRUE;

    private $position = 0;

    public function __construct($last_result, $column=NULL) 
    {
        $this->last_result = $last_result;
        $this->column = $column;
        $this->position = 0;
    }

    public function rewind() 
    {
        if (!$this->can_rewind) {
            throw new MBDBException("Result set can be iterated only once", 500);
        }
    }

    public function current() 
    {
        return $this->data;
    }

    public function key() 
    {
        return $this->position;
    }

    public function next() 
    {
        // no-op
        $this->position += 1;
    }

    public function valid() 
    {
        $this->can_rewind = FALSE;
        $this->data = NULL;
        if ($this->last_result !== NULL) {
            if ($this->data = $this->last_result->fetch_assoc()) {
                if ($this->column != NULL) {
                    if (!isset($this->data[$this->column])) {
                        throw new MBDBException("Column '".$this->column."' not found in query ".$this->SQL, 500);
                    }
                    $this->data = $this->data[$this->column];
                }
            }
        }
        return $this->data !== NULL;
    }

    // Countable interface

    public function count() 
    {
        return $this->last_result === NULL ? 0 : $this->last_result->num_rows;
    }

    public function getRawResult() 
    {
        return $this->last_result;
    }
}

////////////////////////////////////////////////////////////////////////////////
// Query
////////////////////////////////////////////////////////////////////////////////

class Query 
{
    private $db = NULL;

    private $SQL = NULL;

    private $must_affect = -1;

    public function __construct($db, $sql='') 
    {
        $this->db = $db;
        $this->SQL = $sql;
    }

    // ------------------------------------------------------------------------ query FROM and WHERE clauses

    public function from($table) 
    {
        $this->SQL .= " FROM $table";
        return $this;
    }

    public function where() 
    {
        $format = func_get_arg(0);
        $args = [];
        for($i=1; $i<func_num_args(); ++$i) {
            $arg = func_get_arg($i);
            $args[] = mbdb::sqlfy_arg($arg);
        }
        
        $this->SQL .= " WHERE ".vsprintf($format, $args);
        return $this;
    }

    // ------------------------------------------------------------------------ query methods (finalization)

    public function queryAll($column=NULL) 
    {
        $res = mbdb::exec($this->SQL, $this->must_affect);
        if ($res != NULL && $res->num_rows > 0) {
            return new ResultIterator($res,$column);
        } else {
            return [];
        }
    }

    public function queryOne($column=NULL) 
    {
        $res = mbdb::exec($this->SQL, $this->must_affect);
        if ($res == NULL || $res->num_rows != 1) {
            throw new MBDBException(sprintf("Expected 1 result but %s found in query %s", $res->num_rows, $this->SQL), 500);
        } else {
            $data = $res->fetch_assoc();
            if ($column != NULL) {
                if (!array_key_exists($column, $data)) {
                    throw new MBDBException("Column '$column' not found in query ".$this->SQL, 500);
                }
                $data = $data[$column];
            }
            return $data;
        }
    }

    public function queryFirst($column=NULL) 
    {
        $res = mbdb::exec($this->SQL, $this->must_affect);
        if ($res && $res->num_rows != 0) {
            $data = $res->fetch_assoc();
            if ($column != NULL) {
                if (!array_key_exists($column, $data)) {
                    throw new MBDBException("Column '$column' not found in query ".$this->SQL." -- ".json_encode($data), 500);
                }
                $data = $data[$column];
            }
            return $data;
        } else {
            return NULL;
        }
    }

    public function query() 
    {
        return mbdb::exec($this->SQL, $this->must_affect);
    }

    // ------------------------------------------------------------------------ query options

    public function groupBy($column) 
    {
        $this->SQL .= " GROUP BY $column";
        return $this;
    }

    public function orderByAsc($attribute) 
    {
        $this->SQL .= " ORDER BY $attribute ASC";
        return $this;
    }

    public function orderByDesc($attribute) 
    {
        $this->SQL .= " ORDER BY $attribute DESC";
        return $this;
    }

    public function limit($limit) 
    {
        $this->SQL .= " LIMIT $limit";
        return $this;
    }

    public function offset($offset) 
    {
        $this->SQL .= " OFFSET $offset";
        return $this;
    }

    public function forUpdate() 
    {
        $this->SQL .= " FOR UPDATE";
        return $this;
    }

    // only for inserts
    public function onDuplicateKeyUpdate() 
    {
        $this->SQL .= " ON DUPLICATE KEY UPDATE";
        return $this;
    }

    // ------------------------------------------------------------------------ debugging

    // returns the SQL query constructed so far
    public function sql() 
    {
        return $this->SQL;
    }
}

////////////////////////////////////////////////////////////////////////////////
// mbdb
////////////////////////////////////////////////////////////////////////////////

class mbdb {

    private static $dbname = NULL;
    
    private static $username = NULL;
    
    private static $password = NULL;
    
    private static $db = NULL;
    
    private static $transaction_level = 0;
    
    private static $last_result = NULL;
    
    private static $last_query = NULL;

    // ------------------------------------------------------------------------ database connection

    public static function connect($username, $password, $dbname) 
    {
        if (mbdb::$db != NULL) {
            mbdb::disconnect();
        }
        mbdb::$username = $username;
        mbdb::$password = $password;
        mbdb::$dbname = $dbname;
        mbdb::$db = new mysqli('localhost', $username, $password, $dbname);
        if (mbdb::$db->connect_error) {
            throw new MBDBException("Connection failed: ".mbdb::$db->connect_error, 500);
        }
    }

    public static function disconnect() 
    {
        if (mbdb::$db != NULL) {
            mbdb::$db->close();
            mbdb::$db == NULL;
        }
    }

    // returns current database connection
    public static function database() 
    {
        return mbdb::$db;
    }

    // returns current database connection
    public static function database_name() 
    {
        return mbdb::$dbname;
    }

    // ------------------------------------------------------------------------ SQL database dump utility

    public static function mysqldump($backup_dir) 
    {
        $dbhost = 'localhost';
        $dbuser = mbdb::$username;
        $dbpass = mbdb::$password;
        $dbname = mbdb::$dbname;
        $backup_file = "$backup_dir/mysqldump-" . $dbname . "-" . date("Y_m_d-H_i_s") . '.gz';
        $command = "mkdir -p $backup_dir/ && mysqldump --opt -h $dbhost -u $dbuser -p$dbpass $dbname | gzip > $backup_file";
        error_log($command);
        system($command, $retval);
        if ($retval !== 0) {
            throw new MBDBException("Backup command failed: $command", 500);
        }
        return $backup_file;
    }

    // ------------------------------------------------------------------------ advanced

    public static function exec($sql, $expect_affected_rows=-1) 
    {
        mbdb::$last_query = $sql;
        mbdb::$last_result = $r = mbdb::$db->query($sql);
        $errno = mbdb::$db->errno;
        $error = "$sql\ngenerated error:\n" . mbdb::$db->error.' ('.$errno.')';
        if ($errno) {
            throw new MBDBException($error, 500);
        }
        if($expect_affected_rows != -1 && $expect_affected_rows != mbdb::$db->affected_rows) {
            $msg = sprintf("Query '%s' expected to modify %d rows instead of %d", $sql, $expect_affected_rows, mbdb::$db->affected_rows);
            throw new MBDBException($msg, 500);
        }
        return $r;
    }

    // get last executed query string
    public static function lastQuery() 
    {
        return mbdb::$last_query;
    }

    // result gotten from last executed query
    public static function lastResult() 
    {
        return mbdb::$last_result;
    }

    // ------------------------------------------------------------------------ transactions

    public static function begin() 
    {
        if(mbdb::$transaction_level == 0)
        {
            mbdb::exec("START TRANSACTION");
        } else {
            mbdb::exec("SAVEPOINT LEVEL".mbdb::$transaction_level);
        }
        mbdb::$transaction_level++;

        if (mbdb::$transaction_level > 50) {
            throw new MBDBException("Too many mbdb::begin() calls.", 500);
        }

    }

    public static function commit() 
    {
        mbdb::$transaction_level--;
        if (mbdb::$transaction_level < 0) {
            throw new MBDBException("Unexpected mbdb::commit().", 500);
        }

        if(mbdb::$transaction_level == 0)
        {
            mbdb::exec("COMMIT");
        } else {
            mbdb::exec("RELEASE SAVEPOINT LEVEL".mbdb::$transaction_level);
        }
    }
        
    public static function rollback() 
    {
        mbdb::$transaction_level--;
        if (mbdb::$transaction_level < 0) {
            throw new MBDBException("Unexpected mbdb::rollback().", 500);
        }

        if(mbdb::$transaction_level == 0)
        {
            mbdb::exec("ROLLBACK");
        } else {
            mbdb::exec("ROLLBACK TO SAVEPOINT LEVEL".mbdb::$transaction_level);
        }
    }

    // ------------------------------------------------------------------------ standard methods

    public static function select($columns) 
    {
        return new Query(mbdb::$db, "SELECT $columns");
    }

    public static function delete_from($table) 
    {
        return new Query(mbdb::$db, "DELETE FROM $table");
    }

    public static function insert_into($table, $data) 
    {
        $cols = [];
        $values = [];

        foreach($data as $k => $v) {
            $cols[] = $k; 
            $values[] = mbdb::sqlfy_arg($v);
        }

        $cols = implode(",", $cols);
        $values = implode(",", $values);

        return new Query(mbdb::$db, sprintf("INSERT INTO $table (%s) VALUES (%s)", $cols, $values));
    }

    public static function update($table, $data) 
    {
        $values = [];

        foreach($data as $k => $v) {
            $values[] = "$k=".mbdb::sqlfy_arg($v);
        }

        return new Query(mbdb::$db, sprintf("UPDATE $table SET %s", implode(",", $values)));
    }

    // ------------------------------------------------------------------------ post query methods

    public static function insertId() 
    {
        return mysqli_insert_id(mbdb::$db);
    }

    public static function numResults() 
    {
        return mbdb::$db->affected_rows;
    }

    public static function affectedRows() 
    {
        return mbdb::$db->affected_rows;
    }

    // ------------------------------------------------------------------------ debugging methods

    public static function show($str) 
    {
        echo "$str<br>";
    }

    // public static function query() 
    // {
    //     return new Query(mbdb::$db);
    // }
    
    public static function print_table($table_name) 
    {
        echo "<h1>$table_name</h1>";
        // select the VM to provision and extract network info
        mbdb::select("*")->from($table_name)->queryAll();
        $res = mbdb::lastResult();
        if (!$res) {
            return;
        }
        $cols = $res->fetch_fields();
        echo "<table border=1 class=\"db-table\">";
        echo "<tr>";
        foreach($cols as $col) {
            echo "<th>$col->name</th>";
        }
        echo "</tr>";
        $result = new ResultIterator($res);
        foreach($result as $row) { 
            echo "<tr>";
            foreach($cols as $col) {
                $data = $row[$col->name];
                $data = strlen($data) > 40000 ? substr($data, 0, 40-3).'...' : $data;
                $data = htmlspecialchars($data);
                if ((strstr($col->name, 'time') !== FALSE || in_array($col->name, ['month'])) && $data != 0) {
                    $data = date('Y/m/d H:i:s', intval($data));
                }
                echo "<td>".$data."</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }

    // ------------------------------------------------------------------------ internal methods

    public static function sqlfy_arg($arg) 
    {
        if ($arg === NULL) {
            return 'NULL';
        } else
        if (is_string($arg)) {
            return "'".mbdb::database()->real_escape_string($arg)."'";
        } else
        if (is_bool($arg)) {
            return $arg ? 'TRUE' : 'FALSE';
        } else {
            return mbdb::database()->real_escape_string((string)$arg);
        }
    }
}

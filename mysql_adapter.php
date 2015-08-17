<?php
/*
Known Issues:
	pg_connect, pg_delete, pg_insert, pg_update, and pg_select won't allow custom options
	pg_query might have problems detecting whether there are one
		or more than one queries in a call.
	pg_field_table cannot fetch OID
	Error handling is missing from most of the functions
	
	includes replacements for:
		pg_connect
		pg_close
		pg_dbname
		pg_host
		pg_port
		
		pg_delete
		pg_insert
		pg_update
		pg_select
		
		pg_query
		pg_query_params
		pg_prepare
		pg_execute
		
		pg_num_rows
		pg_affected_rows
		pg_fetch_result
		pg_fetch_row
		pg_fetch_array
		pg_fetch_assoc
		pg_fetch_all_columns
		pg_fetch_all
		pg_fetch_object
		
		pg_field_is_null
		pg_field_name
		pg_field_num
		pg_field_size
		pg_field_table
		pg_field_type
		
	Uses newly designed objects instead of connection and result resources.
	
	For more information:
	http://php.net/manual/en/book.pgsql.php
	http://php.net/manual/en/book.mysqli.php
*/
if (!extension_loaded('pgsql'))
{
	define("PGSQL_LIBPQ_VERSION", 'v2015-08-16');
	
	echo "<!--\n";
	echo "Postgre to MySQL Adapter (".PGSQL_LIBPQ_VERSION.") Loaded\n";
	echo "Designed and Developed by T Duke Perry (http://noprestige.com) Copyright (C) 2014-2015\n";
	echo "Licensed under The GNU General Public License v3\n";
	echo "-->\n";
	
	define("PGSQL_LIBPQ_VERSION_STR", 'Postgre to MySQL Adapter '.PGSQL_LIBPQ_VERSION.' on '.php_uname('s').' '.php_uname('r'));
	
	//constants from pgsql driver
	define("PGSQL_ASSOC", 1); define("PGSQL_NUM", 2); define("PGSQL_BOTH", 3); define("PGSQL_CONNECT_FORCE_NEW", 2); define("PGSQL_CONNECT_ASYNC", 4); define("PGSQL_CONNECTION_BAD", 1); define("PGSQL_CONNECTION_OK", 0); define("PGSQL_SEEK_SET", 0); define("PGSQL_SEEK_CUR", 1); define("PGSQL_SEEK_END", 2); define("PGSQL_EMPTY_QUERY", 0); define("PGSQL_COMMAND_OK", 1); define("PGSQL_TUPLES_OK", 2); define("PGSQL_COPY_OUT", 3); define("PGSQL_COPY_IN", 4); define("PGSQL_BAD_RESPONSE", 5); define("PGSQL_NONFATAL_ERROR", 6); define("PGSQL_FATAL_ERROR", 7); define("PGSQL_TRANSACTION_IDLE", 0); define("PGSQL_TRANSACTION_ACTIVE", 1); define("PGSQL_TRANSACTION_INTRANS", 2); define("PGSQL_TRANSACTION_INERROR", 3); define("PGSQL_TRANSACTION_UNKNOWN", 4); define("PGSQL_DIAG_SEVERITY", 83); define("PGSQL_DIAG_SQLSTATE", 67); define("PGSQL_DIAG_MESSAGE_PRIMARY", 77); define("PGSQL_DIAG_MESSAGE_DETAIL", 68); define("PGSQL_DIAG_MESSAGE_HINT", 72); define("PGSQL_DIAG_STATEMENT_POSITION", 80); define("PGSQL_DIAG_INTERNAL_POSITION", 112); define("PGSQL_DIAG_INTERNAL_QUERY", 113); define("PGSQL_DIAG_CONTEXT", 87); define("PGSQL_DIAG_SOURCE_FILE", 70); define("PGSQL_DIAG_SOURCE_LINE", 76); define("PGSQL_DIAG_SOURCE_FUNCTION", 82); define("PGSQL_ERRORS_TERSE", 0); define("PGSQL_ERRORS_DEFAULT", 1); define("PGSQL_ERRORS_VERBOSE", 2); define("PGSQL_STATUS_LONG", 1); define("PGSQL_STATUS_STRING", 2); define("PGSQL_CONV_IGNORE_DEFAULT", 2); define("PGSQL_CONV_FORCE_NULL", 4); define("PGSQL_CONV_IGNORE_NOT_NULL", 8); define("PGSQL_DML_NO_CONV", 256); define("PGSQL_DML_EXEC", 512); define("PGSQL_DML_ASYNC", 1024); define("PGSQL_DML_STRING", 2048); define("PGSQL_DML_ESCAPE", 4096); define("PGSQL_POLLING_FAILED", 0); define("PGSQL_POLLING_READING", 1); define("PGSQL_POLLING_WRITING", 2); define("PGSQL_POLLING_OK", 3); define("PGSQL_POLLING_ACTIVE", 4);
	
	//will hold the last created connection
	$np_p2m_last_conn = null;
	
	class np_p2m_link_resource
	{
		private $mysqli_connection;
		//in Postgre, prepared statements are stored server side.
		//in lieu of that, this array will hold mysqli_stmt objects,
		//as well as query strings for static prepared statements (those that take no parameters).
		private $converted_stmts = array();
		//prepares initial holder variables in advance
		private $hostname = NULL;
		private $database = NULL;
		private $port = NULL;
		//public $options = NULL;
		private $is_error = false;
		
		public function __construct($connection_string)
		{
			$username = NULL;
			$password = NULL;

			$elements = explode(' ', $connection_string);
			foreach($elements as $part)
			{
				$endPoint = strpos($part,'=');
				if ($endPoint === false)
					$this->is_error = true;
				else
				{
					$parameter = substr($part, $endPoint + 1);
					switch (substr($part, 0, $endPoint))
					{
						case 'host':
							$this->hostname = $parameter;
							break;
						case 'dbname':
							$this->database = $parameter;
							break;
						case 'user':
							$username = $parameter;
							break;
						case 'password':
							$password = $parameter;
							break;
						case 'port':
							$this->port = $parameter;
							break;
						case 'options':
							//$this->options = $parameter;
							break;
						default:
							$this->is_error = true;
					}
				}
			}
			if (!$this->is_error)
			{
				$this->mysqli_connection = new mysqli($this->hostname, $username, $password, $this->database, $db_port);
				if (!is_null($this->mysqli_connection->connect_error))
					$this->is_error = true;
			}
		}
		
		public function close()
		{
			$return = $this->mysqli_connection->close();
			$this->converted_stmts = array();
			$this->hostname = NULL;
			$this->database = NULL;
			$this->port = NULL;
			return $return;
		}
		
		public function is_error()
		{
			return $this->is_error;
		}
		
		public function get_info($call)
		{
			switch ($call)
			{
				case 'dbname':
					$result = $this->database;
					break;
				case 'host':
					$result = $this->hostname;
					break;
				case 'port':
					$result = $this->port;
					break;
				default:
					$result = '';
			}
			
			return $result;
		}
		
		public function prepare($name, $query)
		{
			$this->converted_stmts[$name] = new np_p2m_converted_stmt($query, $this->mysqli_connection);
		}
		
		public function query_params($query, $params)
		{
			$stmt = new np_p2m_converted_stmt($query, $this->mysqli_connection);
			return $stmt->execute($params);
		}
		
		public function execute($name, $params)
		{
			return $this->converted_stmts[$name]->execute($params);
		}
		
		public function query($query)
		{
			//clear comments and count queries
			//might have problems if there are only two queries, and the second query does not end in a semicolon
			//might also run into issues if the symbol that indicates the start of a comment block is within quotes.
			$count = preg_match_all('/.*?;/s', preg_replace('/--.*/', '', preg_replace('/\/\*.*?\*\//s', '', $query)));
			
			//TODO: parse the individual statements and return query results on multiples.
			if ($count > 1)
				$result = $this->mysqli_connection->multi_query($query);
			else
				$result = $this->mysqli_connection->query($query);
			
			return new np_p2m_result_resource($result, $this->mysqli_connection->affected_rows);
		}
	}
	
	class np_p2m_converted_stmt
	{
		private $link; //stores either mysqli object, or mysqli_stmt object
		private $detail; //stores either static query, or array with the proper parameter order
		
		private $is_static;
		
		public function __construct($query_statement, $link)
		{
			$mysqli_query = preg_replace('/\$\d*/', '?', $query_statement);
			
			if ($mysqli_query === $query_statement)//for static prepared statements
			{
				$this->is_static = true;
				$this->link = $link;
				$this->detail = $query_statement;
			}
			else
			{
				$this->is_static = false;
				$this->link = $link->prepare($mysqli_query);
				preg_match_all('/\$(\d*)/', $query_statement, $order);
				$this->detail = $order[1];
			}
		}
		
		//taken from http://stackoverflow.com/questions/3681262/php5-3-mysqli-stmtbind-params-with-call-user-func-array-warnings
		private static function refValues($arr)
		{
			$refs = array();
			foreach ($arr as $key => $value)
				$refs[$key] = &$arr[$key];
			return $refs;
		}
		
		public function execute($params = NULL)
		{
			if ($this->is_static)
				$output = $this->link->query($this->detail);
			else
			{
				$types = "";
				$mysqli_params = array();
				//records the parameter types for the execution of the statement
				foreach ($this->detail as $index)
				{
					array_push($mysqli_params, $params[$index - 1]);
					
					if (is_int($params[$index - 1]))
						$types .= 'i';
					else if (is_float($params[$index - 1]))
						$types .= 'd';
					else if (is_string($params[$index - 1]))
						$types .= 's';
					else //something might've gone wrong
						$types .= ' ';
				}
				//sets parameters in mysqli statement object
				call_user_func_array(array($this->link, 'bind_param'), array_merge(array($types), np_p2m_converted_stmt::refValues($mysqli_params)));
				//runs prepared statement
				$this->link->execute();
				//fetches result
				$output = $this->link->get_result();
			}
			return new np_p2m_result_resource($output, $this->link->affected_rows);
		}
	}
	
	class np_p2m_result_resource
	{
		private $is_proper_result;
		private $result; // will hold either mysqli_result or int containing affected_rows
		private $row_pointer = -1;
		//private $col_pointer = -1;
		
		public function __construct($mysqli_result_object, $affected_rows)
		{
			if (is_bool($mysqli_result_object))
			{
				$this->result = $affected_rows;
				$this->is_proper_result = false;
			}
			else if (get_class($mysqli_result_object) == 'mysqli_result')
			{
				$this->result = $mysqli_result_object;
				$this->is_proper_result = true;
			}
		}
		
		public function set_row($index)
		{
			if ($this->result->num_rows > $index)
			{
				$this->row_pointer = $index;
				$return = true;
			}
			else
				$return = false;
				
			return $return;
		}
		
		public function inc_row()
		{
			$this->row_pointer++;
			if ($this->result->num_rows == $this->row_pointer)
				$this->row_pointer = 0;
		}
		
		/*public function set_col($index)
		{
			if ($this->result->field_count > $index)
			{
				$this->col_pointer = $index;
				$return = true;
			}
			else
				$return = false;
				
			return $return;
		}
		
		public function inc_col()
		{
			$this->col_pointer++;
			if ($this->result->field_count == $this->col_pointer)
				$this->col_pointer = 0;
		}*/
		
		public function num_rows()
		{
			return $this->result->num_rows;
		}
		
		public function affected_rows()
		{
			if ($this->is_proper_result)
				$return = $this->result->num_rows;
			else
				$return = $this->result;
			
			return $return;
		}
		
		public function fetch_array($mode)
		{
			$this->result->data_seek($this->row_pointer);
			return $this->result->fetch_array($mode);
		}
		
		public function fetch_all()
		{
			/*$return = array();
			$this->result->data_seek(0);
			$count = $this->result->num_rows;
			for ($i = 0; $i < $count; $i++)
				$return[$i] = $this->result->fetch_assoc();
			return $return;*/
			return $this->result->fetch_all(MYSQLI_ASSOC);
		}
		
		public function fetch_column($field_num)
		{
			$return = array();
			$this->result->data_seek(0);
			$count = $this->result->num_rows;
			for ($i = 0; $i < $count; $i++)
				$return[$i] = $this->result->fetch_row()[$field_num];
			return $return;
		}
		
		public function fetch_object($class_name, $params)
		{
			$this->result->data_seek($this->row_pointer);
			return $this->result->fetch_object($class_name, $params);
		}
		
		public function get_col_num($col_name)
		{
			$all_cols = $this->result->fetch_fields();
			$count = count($all_cols);
			$return = -1;
			for ($i = 0; $i < $count; $i++)
				if ($all_cols[$i]->name === $col_name)
					$return = $i;
			return $return;
		}
		
		public function col_data($col_index)
		{
			/*if (!is_int($col_index))
				$col_index = get_col_num($col_index);*/
			$this->result->field_seek($col_index);
			return $this->result->fetch_field();
		}
	}
	
	/*****************
	Internal functions
	*****************/
	function np_p2m_query_sanitize(&$query)
	{
		//mysql does not support the 'only' option when deleting.
		if (strcasecmp(substr($query, 0, 17), 'DELETE FROM ONLY ') == 0)
			$query = substr_replace($query, 'DELETE FROM ', 0, 17); 
	}
	
	/************************
	Adapted Postgre functions
	************************/
	function pg_connect($connection_string) //Still needs work //no connection type
	{
		global $np_p2m_last_conn;
		
		$link = new np_p2m_link_resource($connection_string);

		if ($link->is_error())
			$link = false;
		else
			$np_p2m_last_conn = $link;
		
		return $link;
	}
	
	function pg_close()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$link = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$link = func_get_arg(0);
		
		return $link->close();
	}
	
	function pg_dbname()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$link = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$link = func_get_arg(0);
		
		return $link->get_info('dbname');
	}
	
	function pg_host()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$link = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$link = func_get_arg(0);
		
		return $link->get_info('host');
	}
	
	function pg_port()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$link = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$link = func_get_arg(0);
		
		return $link->get_info('port');
	}
	
	function pg_delete($link, $table, $row_array, $options = PGSQL_DML_EXEC)//TODO: allow options
	{						//should redo with stmts
		
		//Valid options: PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		
		if (count($row_array) === 0)
			$return = false;
		else
		{
			$query = 'DELETE FROM '.$table.' WHERE ';
			
			foreach ($row_array as $field => $value)
				$query .= $field.' = \''.$value.'\' AND ';
			
			$return = $link->query(substr($query, 0, -5));
		}
		return $return;
	}
	
	function pg_insert($link, $table, $row_array, $options = PGSQL_DML_EXEC)//TODO: allow options
	{						//should redo with stmts
		
		//Valid options: PGSQL_CONV_OPTS, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		
		if (count($row_array) === 0)
			$return = false;
		else
		{
			$fields = '';
			$values = '\'';
			
			foreach ($row_array as $field => $value)
			{
				$fields .= $field.', ';
				$values .= $value.'\', \'';
			}
			$query = 'INSERT INTO '.$table.' ('.substr($fields, 0, -2).') VALUES ('.substr($values, 0, -3).')';
			
			$return = $link->query($query);
		}
		return $return;
	}
	
	function pg_update($link, $table, $new_data, $old_data, $options = PGSQL_DML_EXEC)//TODO: allow options
	{						//should redo with stmts
		
		//Valid options: PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		
		if (count($new_data) === 0 || count($old_data) === 0)
			$return = false;
		else
		{
			$query = 'UPDATE '.$table.' SET ';
			
			foreach ($new_data as $field => $value)
				$query .= $field.' = \''.$value.'\', ';
			
			$query = substr($query, 0, -2).' WHERE ';
			
			foreach ($old_data as $field => $value)
				$query .= $field.' = '.$value.' AND ';
			
			$return = $link->query(substr($query, 0, -5));
		}
		return $return;
	}
	
	function pg_select($link, $table, $row_array, $options = PGSQL_DML_EXEC)//TODO: allow options
	{						//should redo with stmts
		
		//Valid options: PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		
		if (count($row_array) === 0)
			$return = false;
		else
		{
			$query = 'SELECT * FROM '.$table.' WHERE ';
			
			foreach ($row_array as $field => $value)
				$query .= $field.' = \''.$value.'\' AND ';
			
			$return = $link->query(substr($query, 0, -5));
		}
		return $return;
	}
	
	function pg_query()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 1)
		{
			$link = $np_p2m_last_conn;
			$query = func_get_arg(0);
		}
		else if (func_num_args() == 2)
		{
			$link = func_get_arg(0);
			$query = func_get_arg(1);
		}
		
		np_p2m_query_sanitize($query);
		
		return $link->query($query);
	}
	
	function pg_query_params()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 2)
		{
			$link = $np_p2m_last_conn;
			$query = func_get_arg(0);
			$params = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$link = func_get_arg(0);
			$query = func_get_arg(1);
			$params = func_get_arg(2);
		}
		
		np_p2m_query_sanitize($query);
		
		return $link->query_params($query, $params);
	}
	
	function pg_prepare()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$link = $np_p2m_last_conn;
			$name = func_get_arg(0);
			$query = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$link = func_get_arg(0);
			$name = func_get_arg(1);
			$query = func_get_arg(2);
		}
		
		np_p2m_query_sanitize($query);
		
		$link->prepare($name, $query);
	}
	
	function pg_execute()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$link = $np_p2m_last_conn;
			$name = func_get_arg(0);
			$params = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$link = func_get_arg(0);
			$name = func_get_arg(1);
			$params = func_get_arg(2);
		}
		
		return $link->execute($name, $params);
	}
	
	function pg_num_rows($data)
	{
		return $data->num_rows();
	}
	
	function pg_affected_rows($data)
	{
		return $data->affected_rows();
	}
	
	function pg_fetch_result()
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$data->inc_row();
			$field = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$field = func_get_arg(2);
		}
		
		return $data->fetch_array(MYSQLI_BOTH)[$field];
	}
	
	function pg_fetch_row()
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$data->inc_row();
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
		}
		
		return $data->fetch_array(MYSQLI_NUM);
	}
	
	function pg_fetch_array()
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$data->inc_row();
			$option = PGSQL_BOTH;
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$option = PGSQL_BOTH;
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$option = func_get_arg(2);
		}
		
		if ($option == PGSQL_NUM)
			$mysql_option = MYSQLI_NUM;
		else if ($option == PGSQL_ASSOC)
			$mysql_option = MYSQLI_ASSOC;
		else if ($option == PGSQL_BOTH)
			$mysql_option = MYSQLI_BOTH;
		
		return $data->fetch_array($mysql_option);
	}
	
	function pg_fetch_assoc()
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$data->inc_row();
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
		}
		
		return $data->fetch_array(MYSQLI_ASSOC);
	}
	
	function pg_fetch_all_columns($data, $col_index = 0)
	{
		return $data->fetch_column($col_index);
	}
	
	function pg_fetch_all($data)
	{
		return $data->fetch_all();
	}
	
	function pg_fetch_object()//NB: result_type is Ignored and deprecated
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$data->inc_row();
			$class_name = 'stdClass';
			$params = null;
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$class_name = 'stdClass';
			$params = null;
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$class_name = func_get_arg(2);
			$params = null;
		}
		else if (func_num_args() == 4)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$class_name = func_get_arg(2);
			$params = func_get_arg(3);
		}
		
		return $data->fetch_object($class_name, $params);
	}
	
	function pg_field_is_null()
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$data->inc_row();
			$field = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$field = func_get_arg(2);
		}
		
		//TODO: Include error handling
		if ($data->fetch_array(MYSQLI_BOTH)[$field] === null)
			$return = 1;
		else
			$return = 0;
		
		return $return;
	}
	
	function pg_field_name($data, $col_index)
	{
		return $data->col_data($col_index)->name;
	}
	
	function pg_field_num($data, $col_name)
	{
		return $data->get_col_num();
	}
	
	function pg_field_size($data, $col_index)
	{
		return $data->col_data($col_index)->length;
	}
	
	function pg_field_table($data, $col_index, $is_oid = false)//TODO: support OID
	{
		return $data->col_data($col_index)->table;
	}
	
	function pg_field_type($data, $col_index)
	{
		return $data->col_data($col_index)->type;
	}
}
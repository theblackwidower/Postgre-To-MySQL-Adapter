<?php
/*
Will not support:
	pg_fetch_result, pg_fetch_row, pg_​fetch_​array, pg_fetch_assoc,
		pg_​fetch_​object and pg_​field_​is_​null must specify row number.
		Otherwise the first row will always be called.
	pg_connect, pg_delete, and pg_insert won't allow custom options
	pg_query might have problems detecting whether there are one
		or more than one queries in a call.
	pg_field_table cannot fetch OID
	Error handling is missing from most of the functions
	
	includes replacements for:
		pg_connect
		pg_close
		pg_dbname
		pg_host
		
		pg_​delete
		pg_​insert
		pg_query
		pg_​query_​params
		pg_prepare
		pg_execute
		
		pg_num_rows
		pg_fetch_result
		pg_fetch_row
		pg_​fetch_​array
		pg_fetch_assoc
		pg_​fetch_​all_​columns
		pg_fetch_all
		pg_​fetch_​object
		
		pg_​field_​is_​null
		pg_​field_​name
		pg_​field_​num
		pg_​field_​size
		pg_​field_​table
		pg_field_type
		
	Uses MySQLi objects instead of connection resources, and mysqli_result objects instead of result resources.
	
	For more information:
	http://php.net/manual/en/book.pgsql.php
	http://php.net/manual/en/book.mysqli.php
*/
if (!extension_loaded('pgsql'))
{
	define("PGSQL_LIBPQ_VERSION", 'v2015-08-08');
	
	echo "<!--\n";
	echo "Postgre to MySQL Adapter (".PGSQL_LIBPQ_VERSION.") Loaded\n";
	echo "Designed and Developed by T Duke Perry (http://noprestige.com) Copyright (C) 2014-2015\n";
	echo "Licensed under The GNU General Public License v3\n";
	echo "-->\n";
	
	define("PGSQL_LIBPQ_VERSION_STR", 'Postgre to MySQL Adapter '.PGSQL_LIBPQ_VERSION.' on '.php_uname('s').' '.php_uname('r'));
	
	//constants from pgsql driver
	define("PGSQL_ASSOC", 1); define("PGSQL_NUM", 2); define("PGSQL_BOTH", 3); define("PGSQL_CONNECT_FORCE_NEW", 2); define("PGSQL_CONNECT_ASYNC", 4); define("PGSQL_CONNECTION_BAD", 1); define("PGSQL_CONNECTION_OK", 0); define("PGSQL_SEEK_SET", 0); define("PGSQL_SEEK_CUR", 1); define("PGSQL_SEEK_END", 2); define("PGSQL_EMPTY_QUERY", 0); define("PGSQL_COMMAND_OK", 1); define("PGSQL_TUPLES_OK", 2); define("PGSQL_COPY_OUT", 3); define("PGSQL_COPY_IN", 4); define("PGSQL_BAD_RESPONSE", 5); define("PGSQL_NONFATAL_ERROR", 6); define("PGSQL_FATAL_ERROR", 7); define("PGSQL_TRANSACTION_IDLE", 0); define("PGSQL_TRANSACTION_ACTIVE", 1); define("PGSQL_TRANSACTION_INTRANS", 2); define("PGSQL_TRANSACTION_INERROR", 3); define("PGSQL_TRANSACTION_UNKNOWN", 4); define("PGSQL_DIAG_SEVERITY", 83); define("PGSQL_DIAG_SQLSTATE", 67); define("PGSQL_DIAG_MESSAGE_PRIMARY", 77); define("PGSQL_DIAG_MESSAGE_DETAIL", 68); define("PGSQL_DIAG_MESSAGE_HINT", 72); define("PGSQL_DIAG_STATEMENT_POSITION", 80); define("PGSQL_DIAG_INTERNAL_POSITION", 112); define("PGSQL_DIAG_INTERNAL_QUERY", 113); define("PGSQL_DIAG_CONTEXT", 87); define("PGSQL_DIAG_SOURCE_FILE", 70); define("PGSQL_DIAG_SOURCE_LINE", 76); define("PGSQL_DIAG_SOURCE_FUNCTION", 82); define("PGSQL_ERRORS_TERSE", 0); define("PGSQL_ERRORS_DEFAULT", 1); define("PGSQL_ERRORS_VERBOSE", 2); define("PGSQL_STATUS_LONG", 1); define("PGSQL_STATUS_STRING", 2); define("PGSQL_CONV_IGNORE_DEFAULT", 2); define("PGSQL_CONV_FORCE_NULL", 4); define("PGSQL_CONV_IGNORE_NOT_NULL", 8); define("PGSQL_DML_NO_CONV", 256); define("PGSQL_DML_EXEC", 512); define("PGSQL_DML_ASYNC", 1024); define("PGSQL_DML_STRING", 2048); define("PGSQL_DML_ESCAPE", 4096); define("PGSQL_POLLING_FAILED", 0); define("PGSQL_POLLING_READING", 1); define("PGSQL_POLLING_WRITING", 2); define("PGSQL_POLLING_OK", 3); define("PGSQL_POLLING_ACTIVE", 4);
	
	//in Postgre, prepared statements are stored server side.
	//in lieu of that, this array will hold mysqli_stmt objects,
	//as well as query strings for static prepared statements (those that take no parameters).
	$np_p2m_stmts = array();
	$np_p2m_conn_info = array();
	
	//will hold the last created connection
	$np_p2m_last_conn = null;
	
	/*****************
	Internal functions
	*****************/
	function np_p2m_query_sanitize(&$query)
	{
		//mysql does not support the 'only' option when deleting.
		if (strcasecmp(substr($query, 0, 17), 'DELETE FROM ONLY ') == 0)
			$query = substr_replace($query, 'DELETE FROM ', 0, 17); 
	}
	
	function np_p2m_stmt_conv($query)
	{
		$result = preg_replace('/\$\d*/', '?', $query);
		if ($result === $query)
			$result = false;
		return $result;
	}
	
	//taken from http://stackoverflow.com/questions/3681262/php5-3-mysqli-stmtbind-params-with-call-user-func-array-warnings
	function np_p2m_refValues($arr)
	{
		$refs = array();
		foreach ($arr as $key => $value)
			$refs[$key] = &$arr[$key];
		return $refs;
	}
	
	/************************
	Adapted Postgre functions
	************************/
	function pg_connect($connection_string) //Still needs work //no connection type
	{
		global $np_p2m_last_conn;
		
		$isError = false;
		
		//prepares initial holder variables in advance
		$hostname = NULL;
		$username = NULL;
		$password = NULL;
		$database = NULL;
		$port = NULL;
		//$options = NULL;
		
		$elements = explode(' ', $connection_string);
		foreach($elements as $part)
		{
			$endPoint = strpos($part,'=');
			if ($endPoint === false)
				$isError = true;
			else
			{
				$parameter = substr($part, $endPoint + 1);
				switch (substr($part, 0, $endPoint))
				{
					case 'host':
						$hostname = $parameter;
						break;
					case 'dbname':
						$database = $parameter;
						break;
					case 'user':
						$username = $parameter;
						break;
					case 'password':
						$password = $parameter;
						break;
					case 'port':
						$port = $parameter;
						break;
					case 'options':
						//$options = $parameter;
						break;
					default:
						$isError = true;
				}
			}
		}
		if ($isError)
			$return = false;
		else
		{
			$return = new mysqli($hostname, $username, $password, $database, $db_port);
			if ($return !== false)
			{
				$np_p2m_last_conn = $return;
				$thread = $return->thread_id;
				$np_p2m_conn_info[$thread] = array();
				$np_p2m_conn_info[$thread]['dbname'] = $database;
				$np_p2m_conn_info[$thread]['host'] = $hostname;
			}
		}
		return $return;
	}
	
	function pg_close()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$connection = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$connection = func_get_arg(0);
		
		return $connection->close();
	}
	
	function pg_dbname()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$connection = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$connection = func_get_arg(0);
		
		return $np_p2m_conn_info[$connection->thread_id]['dbname'];
	}
	
	function pg_host()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 0)
			$connection = $np_p2m_last_conn;
		else if (func_num_args() == 1)
			$connection = func_get_arg(0);
		
		return $np_p2m_conn_info[$connection->thread_id]['host'];
	}
	
	function pg_​delete()//TODO: allow options
	{
		if (func_num_args() == 3)
		{
			$connection = func_get_arg(0);
			$table = func_get_arg(1);
			$row_array = func_get_arg(2);
			//$options = PGSQL_DML_EXEC;
		}
		else if (func_num_args() == 4)
		{
			$connection = func_get_arg(0);
			$table = func_get_arg(1);
			$row_array = func_get_arg(2);
			//$options = func_get_arg(3);
			//PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		}
		
		if (count($row_array) === 0)
			$return = false;
		else
		{
			$query = "DELETE FROM ".$table." WHERE ";
			
			foreach ($row_array as $field => $value)
				$query .= $field." = ".$value." AND ";
			
			$return = $connection->query(substr($query, 0, -5));
		}
		return $return;
	}
	
	function pg_​insert()//TODO: allow options
	{
		if (func_num_args() == 3)
		{
			$connection = func_get_arg(0);
			$table = func_get_arg(1);
			$row_array = func_get_arg(2);
			//$options = PGSQL_DML_EXEC;
		}
		else if (func_num_args() == 4)
		{
			$connection = func_get_arg(0);
			$table = func_get_arg(1);
			$row_array = func_get_arg(2);
			//$options = func_get_arg(3);
			//PGSQL_CONV_OPTS, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		}

		if (count($row_array) === 0)
			$return = false;
		else
		{
			$fields = '';
			$values = '';
			
			foreach ($row_array as $field => $value)
			{
				$fields .= $field.', ';
				$values .= $value.', ';
			}
			$query = "INSERT INTO ".$table." (".substr($fields, 0, -2).') VALUES ('.substr($values, 0, -2).')';
			
			$return = $connection->query($query);
		}
		return $return;
	}
	
	function pg_query()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 1)
		{
			$connection = $np_p2m_last_conn;
			$query = func_get_arg(0);
		}
		else if (func_num_args() == 2)
		{
			$connection = func_get_arg(0);
			$query = func_get_arg(1);
		}
		
		np_p2m_query_sanitize($query);
		
		//clear comments and count queries
		//might have problems if there are only two queries, and the second query does not end in a semicolon
		//might also run into issues if the symbol that indicates the start of a comment block is within quotes.
		$count = preg_match_all('/.*?;/s', preg_replace('/--.*/', '', preg_replace('/\/\*.*?\*\//s', '', $query)));
		
		//TODO: parse the individual statements and return query results on multiples.
		if ($count > 1)
			$result = $connection->multi_query($query);
		else
			$result = $connection->query($query);
		
		return $result;
	}
	
	function pg_​query_​params()
	{
		global $np_p2m_last_conn;
		
		if (func_num_args() == 2)
		{
			$connection = $np_p2m_last_conn;
			$query = func_get_arg(0);
			$params = func_get_arg(1);
			
		}
		else if (func_num_args() == 3)
		{
			$connection = func_get_arg(0);
			$query = func_get_arg(1);
			$params = func_get_arg(2);
		}
		
		np_p2m_query_sanitize($query);
		
		$mysqli_query = np_p2m_stmt_conv($query);
		if ($mysqli_query === false)
			$output = $connection->query($query);
		else
		{
			preg_match_all('/\$(\d*)/', $query, $order);
			$types = "";
			$mysqli_params = array();
			//records the parameter types for the execution of the statement
			foreach ($order[1] as $index)
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
			$stmt = $connection->prepare($mysqli_query);
			//sets parameters in mysqli statement object
			call_user_func_array(array($stmt, 'bind_param'), array_merge(array($types), np_p2m_refValues($mysqli_params)));
			//runs prepared statement
			$stmt->execute();
			//fetches result
			$output = $stmt->get_result();
		}
		return $output;
	}
	
	function pg_prepare()
	{
		global $np_p2m_stmts;
		global $np_p2m_last_conn;
		
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$connection = $np_p2m_last_conn;
			$name = func_get_arg(0);
			$query = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$connection = func_get_arg(0);
			$name = func_get_arg(1);
			$query = func_get_arg(2);
		}
		
		np_p2m_query_sanitize($query);
		
		$thread = $connection->thread_id;
		if (!isset($np_p2m_stmts[$thread]))
			$np_p2m_stmts[$thread] = array();
		
		$mysqli_query = np_p2m_stmt_conv($query);
		if ($mysqli_query === false)
			$np_p2m_stmts[$thread][$name] = $query; //for static prepared statements
		else
		{
			$np_p2m_stmts[$thread][$name]['obj'] = $connection->prepare($mysqli_query);
			preg_match_all('/\$(\d*)/', $query, $order);
			$np_p2m_stmts[$thread][$name]['order'] = $order[1];
		}
	}
	
	function pg_execute()
	{
		global $np_p2m_stmts;
		global $np_p2m_last_conn;
		
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$connection = $np_p2m_last_conn;
			$name = func_get_arg(0);
			$params = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$connection = func_get_arg(0);
			$name = func_get_arg(1);
			$params = func_get_arg(2);
		}
		
		$thread = $connection->thread_id;
		
		//is statement static
		if (is_string($np_p2m_stmts[$thread][$name]))
			$output = $connection->query($np_p2m_stmts[$thread][$name]);
		else
		{
			$types = "";
			$mysqli_params = array();
			//records the parameter types for the execution of the statement
			foreach ($np_p2m_stmts[$thread][$name]['order'] as $index)
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
			call_user_func_array(array($np_p2m_stmts[$thread][$name]['obj'], 'bind_param'), array_merge(array($types), np_p2m_refValues($mysqli_params)));
			//runs prepared statement
			$np_p2m_stmts[$thread][$name]['obj']->execute();
			//fetches result
			$output = $np_p2m_stmts[$thread][$name]['obj']->get_result();
		}
		return $output;
	}
	
	function pg_num_rows($data)
	{
		return $data->num_rows;
	}
	
	function pg_fetch_result()//TODO: fix how row field is handled
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = 0; //kludge
			$field = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$field = func_get_arg(2);
		}
		$data->data_seek($index);
		$row = $data->fetch_assoc();
		return $row[$field];
	}
	
	function pg_fetch_row()//TODO: fix how row field is handled
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$index = 0; //kludge
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
		}
		$data->data_seek($index);
		return $data->fetch_row();
	}
	
	function pg_​fetch_​array()//TODO: fix how row field is handled
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$index = 0; //kludge
			$option = PGSQL_BOTH;
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$option = PGSQL_BOTH;
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$option = func_get_arg(2);
		}
		$data->data_seek($index);
		
		if ($option == PGSQL_NUM)
			$mysql_option = MYSQLI_NUM;
		else if ($option == PGSQL_ASSOC)
			$mysql_option = MYSQLI_ASSOC;
		else if ($option == PGSQL_BOTH)
			$mysql_option = MYSQLI_BOTH;
		
		return $data->fetch_array($mysql_option);
	}
	
	function pg_fetch_assoc()//TODO: fix how row field is handled
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$index = 0; //kludge
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
		}
		$data->data_seek($index);
		return $data->fetch_assoc();
	}
	
	function pg_​fetch_​all_​columns()
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$col_index = 0;
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$col_index = func_get_arg(1);
		}
		
		$results = array();
		$data->data_seek(0);
		$count = $data->num_rows;
		for ($i = 0; $i < $count; $i++)
			$results[$i] = $data->fetch_array()[$col_index];
		return $results;
	}
	
	function pg_fetch_all($data) //NB: could replace with mysqli_result::fetch_all
	{
		$results = array();
		$data->data_seek(0);
		$count = $data->num_rows;
		for ($i = 0; $i < $count; $i++)
			$results[$i] = $data->fetch_assoc();
		return $results;
	}
	
	function pg_​fetch_​object() //TODO: fix how row field is handled
	{							//NB: result_type is Ignored and deprecated
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$index = 0;
			$class_name = 'stdClass';
			$params = null;
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$class_name = 'stdClass';
			$params = null;
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$class_name = func_get_arg(2);
			$params = null;
		}
		else if (func_num_args() == 4)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$class_name = func_get_arg(2);
			$params = func_get_arg(3);
		}
		$data->data_seek($index);
		return $data->fetch_object($class_name, $params);
	}
	
	function pg_​field_​is_​null()//TODO: fix how row field is handled
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = 0;
			$field = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$field = func_get_arg(2);
		}
		
		$data->data_seek($index);
		$row_data = $data->fetch_array();
		//TODO: Include error handling
		if ($row_data[$field] === null)
			$return = 1;
		else
			$return = 0;
		
		return $return;
	}
	
	function pg_​field_​name($data, $col_index)
	{
		$data->field_seek($col_index);
		$col_data = $data->fetch_field();
		return $col_data->name;
	}
	
	function pg_​field_​num($data, $col_name)
	{
		$all_cols = $data->fetch_fields();
		$count = count($all_cols);
		$return = -1;
		for ($i = 0; $i < $count; $i++)
			if ($all_cols[$i]->name == $col_name)
				$return = $i;
		return $return;
	}
	
	function pg_​field_​size($data, $col_index)
	{
		$data->field_seek($col_index);
		$col_data = $data->fetch_field();
		return $col_data->length;
	}
	
	function pg_​field_​table()//TODO: support OID
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$col_index = func_get_arg(1);
			//$is_oid = false;
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$col_index = func_get_arg(1);
			//$is_oid = func_get_arg(2);
		}
		$data->field_seek($col_index);
		$col_data = $data->fetch_field();
		$return = $col_data->table;
	}
	
	function pg_field_type($data, $col_index)
	{
		$data->field_seek($col_index);
		$col_data = $data->fetch_field();
		return $col_data->type;
	}
}
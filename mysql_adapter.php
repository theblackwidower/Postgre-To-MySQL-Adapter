<?php
/*
Will not support:
	pg_fetch_result, pg_fetch_row and pg_fetch_assoc must specify row number.
		Otherwise the first row will always be called.
	pg_connect won't allow custom options
	pg_query might have problems detecting whether there are one 
		or more than one queries in a call. 
	
	includes replacements for:
		pg_connect
		pg_close
		pg_​delete
		pg_query
		pg_num_rows
		pg_fetch_result
		pg_fetch_row
		pg_fetch_assoc
		pg_fetch_all
		pg_prepare
		pg_execute
	
	Uses MySQLi objects instead of connection resources, and mysqli_result objects instead of result resources.
	
	For more information:
	http://php.net/manual/en/book.pgsql.php
	http://php.net/manual/en/book.mysqli.php
*/
if (!extension_loaded('pgsql'))
{
	define("PGSQL_LIBPQ_VERSION", "v2015-08-07"); define("PGSQL_LIBPQ_VERSION_STR", "Postgre to MySQL Adapter ".PGSQL_LIBPQ_VERSION." on ".php_uname('s').' '.php_uname('r')); 
	
	echo "<!--\n";
	echo "Postgre to MySQL Adapter (".PGSQL_LIBPQ_VERSION.") Loaded\n";
	echo "Designed and Developed by T Duke Perry (http://noprestige.com) Copyright (C) 2014-2015\n";
	echo "Licensed under The GNU General Public License v3\n";
	echo "-->\n";
	
	define("PGSQL_ASSOC", 1); define("PGSQL_NUM", 2); define("PGSQL_BOTH", 3); define("PGSQL_CONNECT_FORCE_NEW", 2); define("PGSQL_CONNECT_ASYNC", 4); define("PGSQL_CONNECTION_BAD", 1); define("PGSQL_CONNECTION_OK", 0); define("PGSQL_SEEK_SET", 0); define("PGSQL_SEEK_CUR", 1); define("PGSQL_SEEK_END", 2); define("PGSQL_EMPTY_QUERY", 0); define("PGSQL_COMMAND_OK", 1); define("PGSQL_TUPLES_OK", 2); define("PGSQL_COPY_OUT", 3); define("PGSQL_COPY_IN", 4); define("PGSQL_BAD_RESPONSE", 5); define("PGSQL_NONFATAL_ERROR", 6); define("PGSQL_FATAL_ERROR", 7); define("PGSQL_TRANSACTION_IDLE", 0); define("PGSQL_TRANSACTION_ACTIVE", 1); define("PGSQL_TRANSACTION_INTRANS", 2); define("PGSQL_TRANSACTION_INERROR", 3); define("PGSQL_TRANSACTION_UNKNOWN", 4); define("PGSQL_DIAG_SEVERITY", 83); define("PGSQL_DIAG_SQLSTATE", 67); define("PGSQL_DIAG_MESSAGE_PRIMARY", 77); define("PGSQL_DIAG_MESSAGE_DETAIL", 68); define("PGSQL_DIAG_MESSAGE_HINT", 72); define("PGSQL_DIAG_STATEMENT_POSITION", 80); define("PGSQL_DIAG_INTERNAL_POSITION", 112); define("PGSQL_DIAG_INTERNAL_QUERY", 113); define("PGSQL_DIAG_CONTEXT", 87); define("PGSQL_DIAG_SOURCE_FILE", 70); define("PGSQL_DIAG_SOURCE_LINE", 76); define("PGSQL_DIAG_SOURCE_FUNCTION", 82); define("PGSQL_ERRORS_TERSE", 0); define("PGSQL_ERRORS_DEFAULT", 1); define("PGSQL_ERRORS_VERBOSE", 2); define("PGSQL_STATUS_LONG", 1); define("PGSQL_STATUS_STRING", 2); define("PGSQL_CONV_IGNORE_DEFAULT", 2); define("PGSQL_CONV_FORCE_NULL", 4); define("PGSQL_CONV_IGNORE_NOT_NULL", 8); define("PGSQL_DML_NO_CONV", 256); define("PGSQL_DML_EXEC", 512); define("PGSQL_DML_ASYNC", 1024); define("PGSQL_DML_STRING", 2048); define("PGSQL_DML_ESCAPE", 4096); define("PGSQL_POLLING_FAILED", 0); define("PGSQL_POLLING_READING", 1); define("PGSQL_POLLING_WRITING", 2); define("PGSQL_POLLING_OK", 3); define("PGSQL_POLLING_ACTIVE", 4);
	
	//in Postgre, prepared statements are stored server side.
	//in lieu of that, this array will hold mysqli_stmt objects,
	//as well as query strings for static prepared statements (those that take no parameters).
	$msqli_stmt = array();
	
	//will hold the last created connection
	$msqli_last_connection = null;
	
	function query_sanitize(&$query)
	{
		//mysql does not support the 'only' option when deleting.
		if (strcasecmp(substr($query, 0, 17), 'DELETE FROM ONLY ') == 0)
			$query = substr_replace($query, 'DELETE FROM ', 0, 17); 
	}
	
	function pg_connect($connection_string) //Still needs work //no connection type
	{
		global $msqli_last_connection;
		
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
			$return = new mysqli($hostname, $username, $password, $database, $db_port);
		
		if ($return !== false)
			$msqli_last_connection = $return;
		
		return $return;
	}
	
	function pg_close()
	{
		global $msqli_last_connection;
		
		if (func_num_args() == 0)
			$connection = $msqli_last_connection;
		else if (func_num_args() == 1)
			$connection = func_get_arg(0);
		
		return $connection->close();
	}
	
	function pg_​delete()//allow options
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
			/*PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING*/
		}
		
		$query = "DELETE FROM ".$table." WHERE ";
		
		foreach ($row_array as $field => $value)
			$query .= $field." = ".$value." AND ";
		
		return $connection->query(substr($query, 0, -5));
	}
	
	function pg_query()
	{
		global $msqli_last_connection;
		
		if (func_num_args() == 1)
		{
			$connection = $msqli_last_connection;
			$query = func_get_arg(0);
		}
		else if (func_num_args() == 2)
		{
			$connection = func_get_arg(0);
			$query = func_get_arg(1);
		}
		
		query_sanitize($query);
		
		//clear comments and count queries
		//might have problems if there are only two queries, and the second query does not end in a semicolon
		//might also run into issues if the symbol that indicates the start of a comment block is within quotes.
		$count = preg_match_all('/.*?;/s', preg_replace('/--.*/', '', preg_replace('/\/\*.*?\*\//s', '', $query)));
		
		//should parse the individual statements and return query results on multiples.
		if ($count > 1)
			$result = $connection->multi_query($query);
		else
			$result = $connection->query($query);
		
		return $result;
	}
	
	function pg_num_rows($data)
	{
		return $data->num_rows;
	}
	
	function pg_fetch_result()//fix how row field is handled
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$field = func_get_arg(1);
			$data->data_seek(0); //kludge
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$field = func_get_arg(2);
			$data->data_seek($index);
		}
		$row = $data->fetch_assoc();
		return $row[$field];
	}
	
	function pg_fetch_row()//fix how row field is handled
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$data->data_seek(0); //kludge
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$data->data_seek($index);
		}
		return $data->fetch_row();
	}
	
	function pg_fetch_assoc()//fix how row field is handled
	{
		if (func_num_args() == 1)
		{
			$data = func_get_arg(0);
			$data->data_seek(0); //kludge
		}
		else if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			$index = func_get_arg(1);
			$data->data_seek($index);
		}
		return $data->fetch_assoc();
	}
	
	function pg_fetch_all($data)
	{
		$results = array();
		$data->data_seek(0);
		$count = $data->num_rows;
		for ($i = 0; $i < $count; $i++)
			$results[$i] = $data->fetch_assoc();
		return $results;
	}
	
	function pg_prepare()
	{
		global $msqli_stmt;
		global $msqli_last_connection;
		
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$connection = $msqli_last_connection;
			$name = func_get_arg(0);
			$query = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$connection = func_get_arg(0);
			$name = func_get_arg(1);
			$query = func_get_arg(2);
		}
		
		query_sanitize($query);
		
		$thread = $connection->thread_id;
		if (!isset($msqli_stmt[$thread]))
			$msqli_stmt[$thread] = array();
		
		$mysqli_query = preg_replace('/\$\d*/', '?', $query);
		if (strpos($mysqli_query, '?') === false)
			$msqli_stmt[$thread][$name] = $mysqli_query; //for static prepared statements
		else
		{
			$msqli_stmt[$thread][$name]['obj'] = $connection->prepare($mysqli_query);
			preg_match_all('/\$(\d*)/', $query, $order);
			$msqli_stmt[$thread][$name]['order'] = $order[1];
		}
	}
	
	//taken from http://stackoverflow.com/questions/3681262/php5-3-mysqli-stmtbind-params-with-call-user-func-array-warnings
	function refValues($arr)
	{
		$refs = array();
		foreach ($arr as $key => $value)
			$refs[$key] = &$arr[$key];
		return $refs;
	}
	
	function pg_execute()
	{
		global $msqli_stmt;
		global $msqli_last_connection;
		
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$connection = $msqli_last_connection;
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
		if (is_string($msqli_stmt[$thread][$name]))
			$output = $connection->query($msqli_stmt[$thread][$name]);
		else
		{
			$types = "";
			$mysqli_params = array();
			//records the parameter types for the execution of the statement
			foreach ($msqli_stmt[$thread][$name]['order'] as $index)
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
			call_user_func_array(array($msqli_stmt[$thread][$name]['obj'], 'bind_param'), array_merge(array($types), refValues($mysqli_params)));
			//runs prepared statement
			$msqli_stmt[$thread][$name]['obj']->execute();
			//fetches result
			$output = $msqli_stmt[$thread][$name]['obj']->get_result();
		}
		return $output;
	}
}
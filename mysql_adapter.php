<?php
/*
Will not support:
	Using the same name for two prepared statements on different connections
	Prepared statements with parameters in an arbitrary order
		(must use standard numerological order: $1, $2, $3, etc...)
	pg_fetch_result, pg_fetch_row and pg_fetch_assoc must specify row number.
		Otherwise the first row will always be called.
	pg_connect won't allow custom options
	connection must always be specified on queries and prepared statements.
	
	includes replacements for:
		pg_connect
		pg_query
		pg_num_rows
		pg_fetch_result
		pg_fetch_row
		pg_fetch_assoc
		pg_fetch_all
		pg_prepare
		pg_execute
*/
if (!extension_loaded('pgsql'))
{
	echo "<!--MySQLi Adapter Loaded-->\n";
	function pg_connect($connection_string) //Still needs work //no connection type
	{
		$isError = false;
		
		//prepares initial holder variables in advance
		$hostname = NULL;
		$username = NULL;
		$password = NULL;
		$database = NULL;
		$port = NULL;
		
		$elements = explode(' ', $connection_string);
		foreach($elements as $part)
		{
			$endPoint = strpos($part,'=');
			if ($endPoint === false)
			{
				$isError = true;
			}
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
					default:
						$isError = true;
				}
			}
		}
		if ($isError)
			$return = false;
		else
			$return = new mysqli($hostname, $username, $password, $database, $db_port);
		return $return;
	}
	
	function pg_query($connection, $query) //find a way to make connection default to last created connection
	{
		return $connection->query($query);
	}
	
	function pg_num_rows($data)
	{
		return $data->num_rows;
	}
	
	function pg_fetch_result()
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
	
	function pg_fetch_row()
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
	
	function pg_fetch_assoc()
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
	
	$msqli_stmt = array();
	
	function pg_prepare($connection, $name, $query) //find a way to make connection default to last created connection
	{
		global $msqli_stmt;
		$mysqli_query = preg_replace('/\$\d*/', '?', $query);
		if (strpos($mysqli_query, '?') !== false)
			$msqli_stmt[$name] = $connection->prepare($mysqli_query);
		else
			$msqli_stmt[$name] = $mysqli_query; //for static prepared statements
	}
	
	//taken from http://stackoverflow.com/questions/3681262/php5-3-mysqli-stmtbind-params-with-call-user-func-array-warnings
	function refValues($arr)
	{
		$refs = array();
		foreach ($arr as $key => $value)
			$refs[$key] = &$arr[$key];
		return $refs;
	}
	
	function pg_execute($connection, $name, $params) //connection unused, should default to last created connection
	{
		global $msqli_stmt;
		if (is_string($msqli_stmt[$name]))
		{
			$output = $connection->query($msqli_stmt[$name]);
		}
		else
		{
			$types = "";
			foreach ($params as $one_param)
			{
				if (is_int($one_param))
					$types .= 'i';
				else if (is_float($one_param))
					$types .= 'd';
				else if (is_string($one_param))
					$types .= 's';
				else
					$types .= ' ';
			}
			call_user_func_array(array($msqli_stmt[$name], 'bind_param'), array_merge(array($types), refValues($params)));
			$msqli_stmt[$name]->execute();
			$output = $msqli_stmt[$name]->get_result();
		}
		return $output;
	}
}
?>

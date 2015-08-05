<?php
/*
Will not support:
	Prepared statements with parameters in an arbitrary order
		(must use standard numerological order: $1, $2, $3, etc...)
	pg_fetch_result, pg_fetch_row and pg_fetch_assoc must specify row number.
		Otherwise the first row will always be called.
	pg_connect won't allow custom options
	pg_query might have problems detecting whether there are one or more than one queries in a call
	
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
	echo "<!--\n";
	echo "Postgre to MySQL Adapter Loaded\n";
	echo "Designed and Developed by T Duke Perry (http://noprestige.com) Copyright (C) 2014-2015\n";
	echo "Licensed under The GNU General Public License v3\n";
	echo "-->\n";
	
	//in Postgre, prepared statements are stored server side.
	//in lieu of that, this array will hold the prepared statement objects,
	//as well as query strings for static prepared statements (take no parameters).
	$msqli_stmt = array();
	
	//will hold the last created connection
	$msqli_last_connection = null;
	
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
		
		if ($return !== false)
			$msqli_last_connection = $return;
		
		return $return;
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
		
		//clear comments and count queries
		//might have problems if there are only two queries, and the second query does not end in a semicolon
		//might also run into issues if the symbol that indicates the start of a comment block is within quotes.
		$count = preg_match_all('/.*?;/s', preg_replace('/--.*/', '', preg_replace('/\/\*.*?\*\//s', '', $query)));
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
		
		$thread = $connection->thread_id;
		if (!isset($msqli_stmt[$thread]))
			$msqli_stmt[$thread] = array();
		
		$mysqli_query = preg_replace('/\$\d*/', '?', $query);
		if (strpos($mysqli_query, '?') !== false)
			$msqli_stmt[$thread][$name] = $connection->prepare($mysqli_query);
		else
			$msqli_stmt[$thread][$name] = $mysqli_query; //for static prepared statements
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
		{
			$output = $connection->query($msqli_stmt[$thread][$name]);
		}
		else
		{
			$types = "";
			//records the parameter types for the execution of the statement
			foreach ($params as $one_param)
			{
				if (is_int($one_param))
					$types .= 'i';
				else if (is_float($one_param))
					$types .= 'd';
				else if (is_string($one_param))
					$types .= 's';
				else //something might've gone wrong
					$types .= ' ';
			}
			//sets parameters in mysqli statement object
			call_user_func_array(array($msqli_stmt[$thread][$name], 'bind_param'), array_merge(array($types), refValues($params)));
			//runs prepared statement
			$msqli_stmt[$thread][$name]->execute();
			//fetches result
			$output = $msqli_stmt[$thread][$name]->get_result();
		}
		return $output;
	}
}
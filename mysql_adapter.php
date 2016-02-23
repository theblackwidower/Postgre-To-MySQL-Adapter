<?php
/*
Postgre to MySQL Adapter, Emulates a PostgreSQL Database on a MySQL backend
Copyright (C) 2016  T Duke Perry (http://noprestige.com)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

-----------------------------------------------

Known Issues:
	pg_connect, pg_delete, pg_insert, pg_update,
		and pg_select won't allow custom options
	pg_query might have problems detecting whether there are one
		or more than one queries in a call.
	pg_field_table cannot fetch OID
	Error handling is missing from most of the functions

Includes replacements for:
	pg_connect
	pg_close
	pg_ping
	pg_last_error

	pg_dbname
	pg_host
	pg_port
	pg_options

	pg_delete
	pg_insert
	pg_update
	pg_select

	pg_query
	pg_query_params
	pg_prepare
	pg_execute

	pg_escape_string
	pg_escape_literal

	pg_num_rows
	pg_affected_rows
	pg_fetch_result
	pg_fetch_row
	pg_fetch_array
	pg_fetch_assoc
	pg_fetch_all_columns
	pg_fetch_all
	pg_fetch_object

	pg_num_fields
	pg_field_is_null
	pg_field_name
	pg_field_num
	pg_field_size
	pg_field_table
	pg_field_type
	pg_field_prtlen

Uses newly designed objects instead of connection and result resources.

For more information:
	http://php.net/manual/en/book.pgsql.php
	http://php.net/manual/en/book.mysqli.php

*/
if (!extension_loaded('pgsql') && extension_loaded('mysqli'))
{
	define("PGSQL_LIBPQ_VERSION", 'v2016-02-22');

	echo "<!--\n";
	echo "Postgre to MySQL Adapter (".PGSQL_LIBPQ_VERSION.") Loaded\n";
	echo "Designed and Developed by T Duke Perry (http://noprestige.com) Copyright (C) 2014-2016\n";
	echo "Licensed under The GNU Lesser General Public License v3\n";
	echo "Full source code available at http://github.com/theblackwidower/Postgre-To-MySQL-Adapter\n";
	echo "-->\n";

	define("PGSQL_LIBPQ_VERSION_STR", 'Postgre to MySQL Adapter '.PGSQL_LIBPQ_VERSION.' on '.php_uname('s').' '.php_uname('r'));

	//constants from pgsql driver
	define("PGSQL_ASSOC",1);define("PGSQL_NUM",2);define("PGSQL_BOTH",3);define("PGSQL_CONNECT_FORCE_NEW",2);define("PGSQL_CONNECT_ASYNC",4);define("PGSQL_CONNECTION_BAD",1);define("PGSQL_CONNECTION_OK",0);define("PGSQL_SEEK_SET",0);define("PGSQL_SEEK_CUR",1);define("PGSQL_SEEK_END",2);define("PGSQL_EMPTY_QUERY",0);define("PGSQL_COMMAND_OK",1);define("PGSQL_TUPLES_OK",2);define("PGSQL_COPY_OUT",3);define("PGSQL_COPY_IN",4);define("PGSQL_BAD_RESPONSE",5);define("PGSQL_NONFATAL_ERROR",6);define("PGSQL_FATAL_ERROR",7);define("PGSQL_TRANSACTION_IDLE",0);define("PGSQL_TRANSACTION_ACTIVE",1);define("PGSQL_TRANSACTION_INTRANS",2);define("PGSQL_TRANSACTION_INERROR",3);define("PGSQL_TRANSACTION_UNKNOWN",4);define("PGSQL_DIAG_SEVERITY",83);define("PGSQL_DIAG_SQLSTATE",67);define("PGSQL_DIAG_MESSAGE_PRIMARY",77);define("PGSQL_DIAG_MESSAGE_DETAIL",68);define("PGSQL_DIAG_MESSAGE_HINT",72);define("PGSQL_DIAG_STATEMENT_POSITION",80);define("PGSQL_DIAG_INTERNAL_POSITION",112);define("PGSQL_DIAG_INTERNAL_QUERY",113);define("PGSQL_DIAG_CONTEXT",87);define("PGSQL_DIAG_SOURCE_FILE",70);define("PGSQL_DIAG_SOURCE_LINE",76);define("PGSQL_DIAG_SOURCE_FUNCTION",82);define("PGSQL_ERRORS_TERSE",0);define("PGSQL_ERRORS_DEFAULT",1);define("PGSQL_ERRORS_VERBOSE",2);define("PGSQL_STATUS_LONG",1);define("PGSQL_STATUS_STRING",2);define("PGSQL_CONV_IGNORE_DEFAULT",2);define("PGSQL_CONV_FORCE_NULL",4);define("PGSQL_CONV_IGNORE_NOT_NULL",8);define("PGSQL_DML_NO_CONV",256);define("PGSQL_DML_EXEC",512);define("PGSQL_DML_ASYNC",1024);define("PGSQL_DML_STRING",2048);define("PGSQL_DML_ESCAPE",4096);define("PGSQL_POLLING_FAILED",0);define("PGSQL_POLLING_READING",1);define("PGSQL_POLLING_WRITING",2);define("PGSQL_POLLING_OK",3);define("PGSQL_POLLING_ACTIVE",4);

	/*****************
	Internal functions
	*****************/
	//taken from http://stackoverflow.com/questions/3681262/php5-3-mysqli-stmtbind-params-with-call-user-func-array-warnings
	function np_p2m_refValues($arr)
	{
		$refs = array();
		foreach ($arr as $key => $value)
			$refs[$key] = &$arr[$key];
		return $refs;
	}

	/***************
	Emulated classes
	***************/
	class np_p2m_link
	{
		//will hold the last created connection
		private static $last_conn = NULL;

		public static function last_connection()
		{
			return self::$last_conn;
		}

		private $mysqli_connection;
		//in Postgre, prepared statements are stored server side.
		//in lieu of that, this array will hold mysqli_stmt objects,
		//as well as query strings for static prepared statements (those that take no parameters).
		private $stored_stmts = array();
		//prepares initial holder variables in advance
		private $hostname = NULL;
		private $database = NULL;
		private $port = NULL;
		private $options = NULL;
		private $is_error = false;

		private static function query_sanitize(&$query)
		{
			//mysql does not support the 'only' option when deleting.
			if (strcasecmp(substr($query, 0, 17), 'DELETE FROM ONLY ') == 0)
				$query = substr_replace($query, 'DELETE FROM ', 0, 17);
		}

		private static function parse_conn_string($string)
		{
			$return = Array();
			$param = "";
			$value = "";
			$length = strlen($string);

			$is_value = false;
			$is_quote = false;

			for($i = 0; $i < $length; $i++)
			{
				if ($is_value)
				{
					if ($is_quote)
					{
						if ($string[$i] == "'")
							$is_quote = false;
						else if ($string[$i] == "\\")
						{
							$i++;
							$value .= $string[$i];
						}
						else
							$value .= $string[$i];
					}
					else if ($string[$i] == "'")
						$is_quote = true;
					else if ($string[$i] == " ")
					{
						while ($string[$i + 1] == " ")
							$i++;
						$is_value = false;

						$return[] = Array("param" => $param, "value" => $value);
						$param = "";
						$value = "";
					}
					else
						$value .= $string[$i];
				}
				else if ($string[$i] == "=")
				{
					$is_value = true;
					while ($string[$i + 1] == " ")
						$i++;
				}
				else if ($string[$i] == " ")
				{
					while ($string[$i + 1] == " ")
						$i++;
					if ($string[$i + 1] == "=")
					{
						$i++;
						$is_value = true;
						while ($string[$i + 1] == " ")
							$i++;
					}
					else
					{
						$return[] = Array("param" => $param, "value" => $value);
						$param = "";
						$value = "";
					}
				}
				else
					$param .= $string[$i];
			}
			if (strlen($param.$value) > 0)
				$return[] = Array("param" => $param, "value" => $value);
			return $return;
		}

		public function __construct($connection_string)
		{
			$username = NULL;
			$password = NULL;

			$elements = self::parse_conn_string($connection_string);
			foreach($elements as $part)
			{
				switch ($part['param'])
				{
					case 'host':
						$this->hostname = $part['value'];
						break;
					case 'dbname':
						$this->database = $part['value'];
						break;
					case 'user':
						$username = $part['value'];
						break;
					case 'password':
						$password = $part['value'];
						break;
					case 'port':
						$this->port = $part['value'];
						break;
					case 'options'://currently does no more than record options
						$this->options = $part['value'];
						break;
					default:
						$this->is_error = true;
				}
			}
			if (!$this->is_error)
			{
				$this->mysqli_connection = new mysqli($this->hostname, $username, $password, $this->database, $db_port);
				if ($this->mysqli_connection->connect_errno !== 0)
					$this->is_error = true;
				else
					self::$last_conn = $this;
			}
		}

		public function close()
		{
			if ($this->mysqli_connection->close())
			{
				$this->stored_stmts = array();
				$this->hostname = NULL;
				$this->database = NULL;
				$this->port = NULL;
				return true;
			}
			else
				return false;
		}

		public function ping()
		{
			return $this->mysqli_connection->ping();
		}

		public function last_error()
		{
			return $this->mysqli_connection->error;
		}

		public function escape_string($string)
		{
			return $this->mysqli_connection->real_escape_string($string);
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
				case 'options':
					$result = $this->options;
					break;
				default:
					$result = null;
			}
			if ($result == null)
				$result = false;

			return $result;
		}

		public function prepare($name, $query)
		{
			self::query_sanitize($query);

			$this->stored_stmts[$name] = new np_p2m_stmt($query, $this->mysqli_connection);
		}

		public function query_params($query, $params)
		{
			self::query_sanitize($query);

			$stmt = new np_p2m_stmt($query, $this->mysqli_connection);
			return $stmt->execute($params);
		}

		public function execute($name, $params)
		{
			return $this->stored_stmts[$name]->execute($params);
		}

		public function query($query)
		{
			self::query_sanitize($query);

			//clear comments and count queries
			//might have problems if there are only two queries, and the second query does not end in a semicolon
			//might also run into issues if the symbol that indicates the start of a comment block is within quotes.
			$count = preg_match_all('/.*?;/s', preg_replace('/--.*/', '', preg_replace('/\/\*.*?\*\//s', '', $query)));

			//TODO: parse the individual statements and return query results on multiples.
			if ($count > 1)
				$result = $this->mysqli_connection->multi_query($query);
			else
				$result = $this->mysqli_connection->query($query);

			return new np_p2m_result($result, $this->mysqli_connection->affected_rows);
		}

		/************************************************
		For pg_delete, pg_insert, pg_update and pg_select
		************************************************/

		private function direct_execute($mysqli_query, $params, $return_array = false)
		{
			$types = "";
			foreach ($params as $value)
			{
				if (is_int($value))
					$types .= 'i';
				else if (is_float($value))
					$types .= 'd';
				else if (is_string($value))
					$types .= 's';
				else //something might've gone wrong
					$types .= ' ';
			}

			$stmt = $this->link->prepare($mysqli_query);

			call_user_func_array(array($stmt, 'bind_param'), array_merge(array($types), np_p2m_refValues($params)));
			if ($stmt->execute())
			{
				if ($return_array)
					return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
				else
					return true;
			}
			else
				return false;
		}

		public function delete($table, $row_array, $options)
		{
			if (count($row_array) === 0)
				return false;
			else
			{
				$query = 'DELETE FROM '.$table.' WHERE ';
				$params = array();
				foreach ($row_array as $field => $value)
				{
					$query .= $field.' = ? AND ';
					$params[] = $value;
				}

				return $this->direct_execute(substr($query, 0, -5), $params);
			}
		}

		public function insert($table, $row_array, $options)
		{
			if (count($row_array) === 0)
				return false;
			else
			{
				$params = array();
				$fields = '';
				$values = '\'';
				foreach ($row_array as $field => $value)
				{
					$fields .= $field.', ';
					$values .= '?, ';

					$params[] = $value;
				}

				$query = 'INSERT INTO '.$table.' ('.substr($fields, 0, -2).') VALUES ('.substr($values, 0, -2).')';

				return $this->direct_execute($query, $params);
			}
		}

		public function update($table, $new_data, $old_data, $options)
		{
			if (count($row_array) === 0)
				return false;
			else
			{
				$query = 'UPDATE '.$table.' SET ';
				$params = array();

				foreach ($new_data as $field => $value)
				{
					$query .= $field.' = ?, ';
					$params[] = $value;
				}

				$query = substr($query, 0, -2).' WHERE ';

				foreach ($old_data as $field => $value)
				{
					$query .= $field.' = ? AND ';
					$params[] = $value;
				}

				return $this->direct_execute(substr($query, 0, -5), $params);
			}
		}

		public function select($table, $row_array, $options)
		{
			if (count($row_array) === 0)
				return false;
			else
			{
				$query = 'SELECT * FROM '.$table.' WHERE ';
				$params = array();
				foreach ($row_array as $field => $value)
				{
					$query .= $field.' = ? AND ';
					$params[] = $value;
				}

				return $this->direct_execute(substr($query, 0, -5), $params, true);
			}
		}
	}

	class np_p2m_stmt
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
					$mysqli_params[] = $params[$index - 1];

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
				call_user_func_array(array($this->link, 'bind_param'), array_merge(array($types), np_p2m_refValues($mysqli_params)));
				//runs prepared statement
				$this->link->execute();
				//fetches result
				$output = $this->link->get_result();
			}
			return new np_p2m_result($output, $this->link->affected_rows);
		}
	}

	class np_p2m_result
	{
		private $is_proper_result;
		private $result; // will hold either mysqli_result or int containing affected_rows
		private $row_pointer = -1;

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

		private function transfer_row_pointer()
		{
			if ($this->row_pointer < 0)
				$this->result->data_seek(0);
			else
				$this->result->data_seek($this->row_pointer);
		}

		public function num_rows()
		{
			if ($this->is_proper_result)
				return $this->result->num_rows;
			else
				return false;
		}

		public function num_fields()
		{
			if ($this->is_proper_result)
				return $this->result->field_count;
			else
				return false;
		}

		public function affected_rows()
		{
			if ($this->is_proper_result)
				return $this->result->num_rows;
			else
				return $this->result;
		}

		public function fetch_array($mode)
		{
			if ($this->is_proper_result)
			{
				$this->transfer_row_pointer();
				return $this->result->fetch_array($mode);
			}
			else
				return false;
		}

		public function fetch_all()
		{
			/*$return = array();
			$this->result->data_seek(0);
			$count = $this->result->num_rows;
			for ($i = 0; $i < $count; $i++)
				$return[$i] = $this->result->fetch_assoc();
			return $return;*/
			if ($this->is_proper_result)
				return $this->result->fetch_all(MYSQLI_ASSOC);
			else
				return false;
		}

		public function fetch_column($field_num)
		{
			if ($this->is_proper_result)
			{
				$return = array();
				$this->result->data_seek(0);
				$count = $this->result->num_rows;
				for ($i = 0; $i < $count; $i++)
					$return[$i] = $this->result->fetch_row()[$field_num];
				return $return;
			}
			else
				return false;
		}

		public function fetch_object($class_name, $params)
		{
			if ($this->is_proper_result)
			{
				$this->transfer_row_pointer();
				return $this->result->fetch_object($class_name, $params);
			}
			else
				return false;
		}

		public function get_col_num($col_name)
		{
			if ($this->is_proper_result)
			{
				$all_cols = $this->result->fetch_fields();
				$count = count($all_cols);
				$return = -1;
				for ($i = 0; $i < $count; $i++)
					if ($all_cols[$i]->name === $col_name)
						$return = $i;
				return $return;
			}
			else
				return false;
		}

		public function col_data($col_index)
		{
			if ($this->is_proper_result)
			{
				/*if (!is_int($col_index))
					$col_index = get_col_num($col_index);*/
				$this->result->field_seek($col_index);
				return $this->result->fetch_field();
			}
			else
				return false;
		}
	}

	/************************
	Adapted Postgre functions
	************************/
	function pg_connect($connection_string) //Still needs work //no connection type
	{										//PGSQL_CONNECT_FORCE_NEW, PGSQL_CONNECT_ASYNC
		$link = new np_p2m_link($connection_string);

		if ($link->is_error())
			$link = false;

		return $link;
	}

	function pg_close()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->close();
	}

	function pg_ping()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->ping();
	}

	function pg_last_error()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->last_error();
	}

	function pg_dbname()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->get_info('dbname');
	}

	function pg_host()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->get_info('host');
	}

	function pg_port()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->get_info('port');
	}

	function pg_options()
	{
		if (func_num_args() == 0)
			$link = np_p2m_link::last_connection();
		else if (func_num_args() == 1)
			$link = func_get_arg(0);

		return $link->get_info('options');
	}

	function pg_delete($link, $table, $row_array, $options = PGSQL_DML_EXEC)//TODO: allow options
	{
		//Valid options: PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		return $link->delete($table, $row_array, $options);
	}

	function pg_insert($link, $table, $row_array, $options = PGSQL_DML_EXEC)//TODO: allow options
	{
		//Valid options: PGSQL_CONV_OPTS, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		return $link->insert($table, $row_array, $options);
	}

	function pg_update($link, $table, $new_data, $old_data, $options = PGSQL_DML_EXEC)//TODO: allow options
	{
		//Valid options: PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		return $link->update($table, $new_data, $old_data, $options);
	}

	function pg_select($link, $table, $row_array, $options = PGSQL_DML_EXEC)//TODO: allow options
	{
		//Valid options: PGSQL_CONV_FORCE_NULL, PGSQL_DML_NO_CONV, PGSQL_DML_ESCAPE, PGSQL_DML_EXEC, PGSQL_DML_ASYNC or PGSQL_DML_STRING
		return $link->select($table, $row_array, $options);
	}

	function pg_query()
	{
		if (func_num_args() == 1)
		{
			$link = np_p2m_link::last_connection();
			$query = func_get_arg(0);
		}
		else if (func_num_args() == 2)
		{
			$link = func_get_arg(0);
			$query = func_get_arg(1);
		}

		return $link->query($query);
	}

	function pg_query_params()
	{
		if (func_num_args() == 2)
		{
			$link = np_p2m_link::last_connection();
			$query = func_get_arg(0);
			$params = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$link = func_get_arg(0);
			$query = func_get_arg(1);
			$params = func_get_arg(2);
		}

		return $link->query_params($query, $params);
	}

	function pg_prepare()
	{
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$link = np_p2m_link::last_connection();
			$name = func_get_arg(0);
			$query = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$link = func_get_arg(0);
			$name = func_get_arg(1);
			$query = func_get_arg(2);
		}

		$link->prepare($name, $query);
	}

	function pg_execute()
	{
		if (func_num_args() == 2)
		{
			//defaults to last created connection
			$link = np_p2m_link::last_connection();
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

	function pg_escape_string()
	{
		if (func_num_args() == 1)
		{
			$link = np_p2m_link::last_connection();
			$string = func_get_arg(0);
		}
		else if (func_num_args() == 2)
		{
			$link = func_get_arg(0);
			$string = func_get_arg(1);
		}

		return $link->escape_string($string);
	}

	function pg_escape_literal()
	{
		if (func_num_args() == 1)
		{
			$link = np_p2m_link::last_connection();
			$string = func_get_arg(0);
		}
		else if (func_num_args() == 2)
		{
			$link = func_get_arg(0);
			$string = func_get_arg(1);
		}

		return "'".$link->escape_string($string)."'";
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

	function pg_num_fields($data)
	{
		return $data->num_fields();
	}

	function pg_field_is_null()
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			//current row is fetched by default
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

	function pg_field_prtlen()
	{
		if (func_num_args() == 2)
		{
			$data = func_get_arg(0);
			//current row is fetched by default
			$field = func_get_arg(1);
		}
		else if (func_num_args() == 3)
		{
			$data = func_get_arg(0);
			$data->set_row(func_get_arg(1));
			$field = func_get_arg(2);
		}

		return strlen($data->fetch_array(MYSQLI_BOTH)[$field]);
	}
}

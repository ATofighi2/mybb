<?php
/**
 * MyBB 1.2
 * Copyright � 2007 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.com
 * License: http://www.mybboard.com/license.php
 *
 * $Id: db_mysql.php 1370 2006-04-16 13:47:01Z chris $
 */

class DB_SQLite3
{
	/**
	 * The title of this layer.
	 *
	 * @var string
	 */
	var $title = "SQLite 3";
	
	/**
	 * The short title of this layer.
	 *
	 * @var string
	 */
	var $short_title = "SQLite";
	
	/**
	 * The type of db software being used.
	 *
	 * @var string
	 */
	var $type;

	/**
	 * A count of the number of queries.
	 *
	 * @var int
	 */
	var $query_count = 0;

	/**
	 * A list of the performed queries.
	 *
	 * @var array
	 */
	var $querylist = array();

	/**
	 * 1 if error reporting enabled, 0 if disabled.
	 *
	 * @var boolean
	 */
	var $error_reporting = 1;

	/**
	 * The database connection resource.
	 *
	 * @var resource
	 */
	var $link;

	/**
	 * Explanation of a query.
	 *
	 * @var string
	 */
	var $explain;

	/**
	 * The current version of SQLite.
	 *
	 * @var string
	 */
	var $version;

	/**
	 * The current table type in use (myisam/innodb)
	 *
	 * @var string
	 */
	var $table_type = "myisam";
	
	/**
	 * The table prefix used for simple select, update, insert and delete queries
	 *
	 * @var string
	 */
	var $table_prefix;
	
	/**
	 * The extension used to run the SQL database
	 *
	 * @var string
	 */
	var $engine = "pdo";

	/**
	 * The database encoding currently in use (if supported)
	 *
	 * @var string
	 */
	var $db_encoding = "";

	/**
	 * Connect to the database server.
	 *
	 * @param array Array of DBMS connection details.
	 * @return resource The DB connection resource.
	 */
	function connect($config)
	{
		// $database ($config['database']) should be a full path to the file; i.e. C:\temp\test_db.db
		// To be changed before 1.4 release
		
		require_once MYBB_ROOT."inc/db_pdo.php";
		
		$this->db = new dbpdoEngine("sqlite:{$config['database']}");
		@$this->query('PRAGMA short_column_names = 1');
		
		if($this->db)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Query the database.
	 *
	 * @param string The query SQL.
	 * @param boolean 1 if hide errors, 0 if not.
	 * @return resource The query data.
	 */
	function query($string, $hide_errors=0)
	{
		global $pagestarttime, $querytime, $db, $mybb;
		
		$qtimer = new timer();
		if(strtolower(substr(ltrim($string), 0, 5)) == 'alter')
		{			
			$queryparts = preg_split("/[\s]+/", $string, 4, PREG_SPLIT_NO_EMPTY);
			$tablename = $queryparts[2];
			$alterdefs = $queryparts[3];
			if(strtolower($queryparts[1]) != 'table' || $queryparts[2] == '')
			{
				$this->error_msg = "near \"{$queryparts[0]}\": syntax error";
			}
			else
			{
				$alterdefs = preg_replace("#\sAFTER\s([a-z_]+?)(;*?)$#i", "", $alterdefs);
				
				// SQLITE 3 supports ADD Alter statements
				if(strtolower(substr(ltrim($alterdefs), 0, 3)) == 'add')
				{
					$query = @$this->db->query($string);
				}
				else
				{
					$query = $this->alter_table_parse($tablename, $alterdefs);
				}
			}
		}
	  	else
	  	{
			$query = $this->db->query($string);
		}
		
		if($this->error_number($query) > 0 && !$hide_errors)
		{
			$this->error($string, $query);
			exit;
		}
		
		$qtime = $qtimer->stop();
		$querytime += $qtimer->totaltime;
		$qtimer->remove();
		$this->query_count++;
		
		if($mybb->debug_mode)
		{
			$this->explain_query($string, $qtime);
		}
		return $query;
	}

	/**
	 * Explain a query on the database.
	 *
	 * @param string The query SQL.
	 * @param string The time it took to perform the query.
	 */
	function explain_query($string, $qtime)
	{
		if(preg_match("#^\s*select#i", $string))
		{
			$query = $this->query("EXPLAIN {$string}");
			$this->explain .= "<table style=\"background-color: #666;\" width=\"95%\" cellpadding=\"4\" cellspacing=\"1\" align=\"center\">\n".
				"<tr>\n".
				"<td colspan=\"8\" style=\"background-color: #ccc;\"><strong>#".$this->query_count." - Select Query</strong></td>\n".
				"</tr>\n".
				"<tr>\n".
				"<td colspan=\"8\" style=\"background-color: #fefefe;\"><span style=\"font-family: Courier; font-size: 14px;\">".$string."</span></td>\n".
				"</tr>\n".
				"<tr style=\"background-color: #efefef;\">\n".
				"<td><strong>table</strong></td>\n".
				"<td><strong>type</strong></td>\n".
				"<td><strong>possible_keys</strong></td>\n".
				"<td><strong>key</strong></td>\n".
				"<td><strong>key_len</strong></td>\n".
				"<td><strong>ref</strong></td>\n".
				"<td><strong>rows</strong></td>\n".
				"<td><strong>Extra</strong></td>\n".
				"</tr>\n";

			while($table = $this->fetch_array($query))
			{
				$this->explain .=
					"<tr bgcolor=\"#ffffff\">\n".
					"<td>".$table['table']."</td>\n".
					"<td>".$table['type']."</td>\n".
					"<td>".$table['possible_keys']."</td>\n".
					"<td>".$table['key']."</td>\n".
					"<td>".$table['key_len']."</td>\n".
					"<td>".$table['ref']."</td>\n".
					"<td>".$table['rows']."</td>\n".
					"<td>".$table['Extra']."</td>\n".
					"</tr>\n";
			}
			$this->explain .=
				"<tr>\n".
				"<td colspan=\"8\" style=\"background-color: #fff;\">Query Time: ".$qtime."</td>\n".
				"</tr>\n".
				"</table>\n".
				"<br />\n";
		}
		else
		{
			$this->explain .= "<table style=\"background-color: #666;\" width=\"95%\" cellpadding=\"4\" cellspacing=\"1\" align=\"center\">\n".
				"<tr>\n".
				"<td style=\"background-color: #ccc;\"><strong>#".$this->query_count." - Write Query</strong></td>\n".
				"</tr>\n".
				"<tr style=\"background-color: #fefefe;\">\n".
				"<td><span style=\"font-family: Courier; font-size: 14px;\">".htmlspecialchars_uni($string)."</span></td>\n".
				"</tr>\n".
				"<tr>\n".
				"<td bgcolor=\"#ffffff\">Query Time: ".$qtime."</td>\n".
				"</tr>\n".
				"</table>\n".
				"<br />\n";
		}

		$this->querylist[$this->query_count]['query'] = $string;
		$this->querylist[$this->query_count]['time'] = $qtime;
	}
	
	/**
	 * Execute a write query on the database
	 *
	 * @param string The query SQL.
	 * @param boolean 1 if hide errors, 0 if not.
	 * @return resource The query data.
	 */
	function write_query($query, $hide_errors=0)
	{
		return $this->query($query, $hide_errors);
	}

	/**
	 * Return a result array for a query.
	 *
	 * @param resource The result data.
	 * @param constant The type of array to return.
	 * @return array The array of results.
	 */
	function fetch_array($query)
	{
		$array = $this->db->fetch_array($query);
		return $array;
	}

	/**
	 * Return a specific field from a query.
	 *
	 * @param resource The query ID.
	 * @param string The name of the field to return.
	 * @param int The number of the row to fetch it from.
	 */
	function fetch_field($query, $field, $row=false)
	{
		if($row !== false)
		{
			$this->data_seek($query, $row);
		}
		$array = $this->fetch_array($query);
		return $array[$field];
	}

	/**
	 * Moves internal row pointer to the next row
	 *
	 * @param resource The query ID.
	 * @param int The pointer to move the row to.
	 */
	function data_seek($query, $row)
	{
		return $this->db->seek($query, $row);
	}

	/**
	 * Return the number of rows resulting from a query.
	 *
	 * @param resource The query data.
	 * @return int The number of rows in the result.
	 */
	function num_rows($query)
	{
		return $this->db->num_rows($query);
	}

	/**
	 * Return the last id number of inserted data.
	 *
	 * @return int The id number.
	 */
	function insert_id($name="")
	{
		return $this->db->insert_id($name);
	}

	/**
	 * Close the connection with the DBMS.
	 *
	 */
	function close()
	{
		return;
	}

	/**
	 * Return an error number.
	 *
	 * @return int The error number of the current error.
	 */
	function error_number($query="")
	{
		if(!$query)
		{
			$query = $this->db->last_query;
		}
		
		$this->error_number = $this->db->error_number($query);
		
		return $this->error_number;
	}

	/**
	 * Return an error string.
	 *
	 * @return string The explanation for the current error.
	 */
	function error_string($query="")
	{
		if($this->error_number != "")
		{
			if(!$query)
			{
				$query = $this->db->last_query;
			}
			
			$error_string = $this->db->error_string($query);
			$this->error_number = "";
		
			return $error_string;
		}
	}

	/**
	 * Output a database error.
	 *
	 * @param string The string to present as an error.
	 */
	function error($string="", $query="", $error="", $error_no="")
	{
		$this->db->roll_back();
		
		if($this->error_reporting)
		{
			global $error_handler;
			
			if(!is_object($error_handler))
			{
				require_once MYBB_ROOT."inc/class_error.php";
				$error_handler = new errorHandler();
			}
			
			if(!$query)
			{
				$query = $this->db->last_query;
			}
			
			if($error == "")
			{
				$error = $this->error_string($query);
			}
			
			if($error_no == "")
			{
				$error_no = $this->error_number($query);
			}
			
			$error = array(
				"error_no" => $error_no,
				"error" => $error[1]." - ".$error[2],
				"query" => $string
			);
			$error_handler->error(MYBB_SQL, $error);
		}
	}


	/**
	 * Returns the number of affected rows in a query.
	 *
	 * @return int The number of affected rows.
	 */
	function affected_rows($query="")
	{
		if(!$query)
		{
			$query = $this->db->last_query;
		}
		
		return $this->db->affected_rows($query);
	}

	/**
	 * Return the number of fields.
	 *
	 * @param resource The query data.
	 * @return int The number of fields.
	 */
	function num_fields($query)
	{
		if(!$query)
		{
			$query = $this->db->last_query;
		}
		
		return $this->db->num_fields($query);
	}

	/**
	 * Lists all functions in the database.
	 *
	 * @param string The database name.
	 * @param string Prefix of the table (optional)
	 * @return array The table list.
	 */
	function list_tables($database, $prefix='')
	{
		if($prefix)
		{
			$query = $this->query("SELECT tbl_name FROM sqlite_master WHERE type = 'table' AND tbl_name LIKE '".$this->escape_string($prefix)."%'");
		}
		else
		{
			$query = $this->query("SELECT tbl_name FROM sqlite_master WHERE type = 'table'");
		}
		
		while($table = $this->fetch_array($query))
		{
			$tables[] = $table['tbl_name'];
		}
		return $tables;
	}

	/**
	 * Check if a table exists in a database.
	 *
	 * @param string The table name.
	 * @return boolean True when exists, false if not.
	 */
	function table_exists($table)
	{
		$err = $this->error_reporting;
		$this->error_reporting = 0;
		$query = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$this->table_prefix}{$table}'");
		$exists = $this->num_rows($query);
		$this->error_reporting = $err;

		if($exists > 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Check if a field exists in a database.
	 *
	 * @param string The field name.
	 * @param string The table name.
	 * @return boolean True when exists, false if not.
	 */
	function field_exists($field, $table)
	{
		$err = $this->error_reporting;
		$this->error_reporting = 0;
		$query = $this->query("PRAGMA table_info('{$this->table_prefix}{$table}')");
		
		$exists = 0;
		
		while($row = $this->fetch_array($query))
		{
			if($row['name'] == $field)
			{
				++$exists;
			}
        }
		$this->error_reporting = $err;
		
		if($exists > 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Add a shutdown query.
	 *
	 * @param resource The query data.
	 * @param string An optional name for the query.
	 */
	function shutdown_query($query, $name=0)
	{
		global $shutdown_queries;
		if($name)
		{
			$shutdown_queries[$name] = $query;
		}
		else
		{
			$shutdown_queries[] = $query;
		}
	}

	/**
	 * Performs a simple select query.
	 *
	 * @param string The table name to be queried.
	 * @param string Comma delimetered list of fields to be selected.
	 * @param string SQL formatted list of conditions to be matched.
	 * @param array List of options, order by, order direction, limit, limit start
	 */
	
	function simple_select($table, $fields="*", $conditions="", $options=array())
	{
		$query = "SELECT ".$fields." FROM ".$this->table_prefix.$table;
		
		if($conditions != "")
		{
			$query .= " WHERE ".$conditions;
		}
		
		if(isset($options['order_by']))
		{
			$query .= " ORDER BY ".$options['order_by'];
			
			if(isset($options['order_dir']))
			{
				$query .= " ".strtoupper($options['order_dir']);
			}
		}
		
		if(isset($options['limit_start']) && isset($options['limit']))
		{
			$query .= " LIMIT ".$options['limit_start'].", ".$options['limit'];
		}
		else if(isset($options['limit']))
		{
			$query .= " LIMIT ".$options['limit'];
		}
		
		return $this->query($query);
	}


	/**
	 * Build an insert query from an array.
	 *
	 * @param string The table name to perform the query on.
	 * @param array An array of fields and their values.
	 * @return int The insert ID if available
	 */
	function insert_query($table, $array)
	{
		$comma = $query1 = $query2 = "";
		
		if(!is_array($array))
		{
			return false;
		}
		
		$comma = "";
		$query1 = "";
		$query2 = "";
		
		foreach($array as $field => $value)
		{
			$query1 .= $comma.$field;
			$query2 .= $comma."'".$value."'";
			$comma = ", ";
		}
		
		$this->query("INSERT INTO ".$this->table_prefix.$table." (".$query1.") VALUES (".$query2.");");
		return $this->insert_id();
	}

	/**
	 * Build an update query from an array.
	 *
	 * @param string The table name to perform the query on.
	 * @param array An array of fields and their values.
	 * @param string An optional where clause for the query.
	 * @param string An optional limit clause for the query.
	 * @return resource The query data.
	 */
	function update_query($table, $array, $where="", $limit="")
	{
		if(!is_array($array))
		{
			return false;
		}
		
		$comma = "";
		$query = "";
		
		foreach($array as $field => $value)
		{
			$query .= $comma.$field."='".$value."'";
			$comma = ", ";
		}
		
		if(!empty($where))
		{
			$query .= " WHERE $where";
		}
		
		return $this->query("UPDATE {$this->table_prefix}$table SET $query");
	}

	/**
	 * Build a delete query.
	 *
	 * @param string The table name to perform the query on.
	 * @param string An optional where clause for the query.
	 * @param string An optional limit clause for the query.
	 * @return resource The query data.
	 */
	function delete_query($table, $where="", $limit="")
	{
		$query = "";
		if(!empty($where))
		{
			$query .= " WHERE $where";
		}
		
		return $this->query("DELETE FROM {$this->table_prefix}$table $query");
	}

	/**
	 * Escape a string
	 *
	 * @param string The string to be escaped.
	 * @return string The escaped string.
	 */
	function escape_string($string)
	{
		$string = $this->db->escape_string($string);
		return $string;
	}
	
	/**
	 * Serves no purposes except compatibility
	 *
	 */
	function free_result($query)
	{
		return;
	}
	
	/**
	 * Escape a string used within a like command.
	 *
	 * @param string The string to be escaped.
	 * @return string The escaped string.
	 */
	function escape_string_like($string)
	{
		return $this->escape_string(str_replace(array('%', '_') , array('\\%' , '\\_') , $string));
	}

	/**
	 * Gets the current version of SQLLite.
	 *
	 * @return string Version of MySQL.
	 */
	function get_version()
	{
		if($this->version)
		{
			return $this->version;
		}
		$this->version = $this->db->get_attribute("ATTR_SERVER_VERSION");
		
		return $this->version;
	}

	/**
	 * Optimizes a specific table.
	 *
	 * @param string The name of the table to be optimized.
	 */
	function optimize_table($table)
	{
		$this->query("VACUUM ".$this->table_prefix.$table."");
	}
	
	/**
	 * Analyzes a specific table.
	 *
	 * @param string The name of the table to be analyzed.
	 */
	function analyze_table($table)
	{
		$this->query("ANALYZE ".$this->table_prefix.$table."");
	}

	/**
	 * Show the "create table" command for a specific table.
	 *
	 * @param string The name of the table.
	 * @return string The MySQL command to create the specified table.
	 */
	function show_create_table($table)
	{
		$query = $this->simple_select("sqlite_master", "sql", "type = 'table' AND name = '{$this->table_prefix}{$table}' ORDER BY type DESC, name");
		
		return $this->fetch_field($query, 'sql');
	}

	/**
	 * Show the "show fields from" command for a specific table.
	 *
	 * @param string The name of the table.
	 * @return string Field info for that table
	 */
	function show_fields_from($table)
	{
		$query = $this->simple_select("sqlite_master", "sql", "type = 'table' AND name = '{$this->table_prefix}{$table}'");
		$table = trim(preg_replace('#CREATE\s+TABLE\s+"?'.$this->table_prefix.$table.'"?#i', '', $this->fetch_field($query, "sql")));

		preg_match('#\((.*)\)#s', $table, $matches);

		$field_info = array();
		$table_cols = explode(',', trim($matches[1]));
		foreach($table_cols as $declaration)
		{
			$entities = preg_split('#\s+#', trim($declaration));
			$column_name = preg_replace('/"?([^"]+)"?/', '\1', $entities[0]);

			$field_info[] = array('Extra' => $entities[1], 'Field' => $column_name);
		}
		
		return $field_info;
	}

	/**
	 * Returns whether or not the table contains a fulltext index.
	 *
	 * @param string The name of the table.
	 * @param string Optionally specify the name of the index.
	 * @return boolean True or false if the table has a fulltext index or not.
	 */
	function is_fulltext($table, $index="")
	{
		return false;
	}

	/**
	 * Returns whether or not this database engine supports fulltext indexing.
	 *
	 * @param string The table to be checked.
	 * @return boolean True or false if supported or not.
	 */

	function supports_fulltext($table)
	{
		return false;
	}

	/**
	 * Returns whether or not this database engine supports boolean fulltext matching.
	 *
	 * @param string The table to be checked.
	 * @return boolean True or false if supported or not.
	 */
	function supports_fulltext_boolean($table)
	{
		return false;
	}

	/**
	 * Creates a fulltext index on the specified column in the specified table with optional index name.
	 *
	 * @param string The name of the table.
	 * @param string Name of the column to be indexed.
	 * @param string The index name, optional.
	 */
	function create_fulltext_index($table, $column, $name="")
	{
		return false;
	}

	/**
	 * Drop an index with the specified name from the specified table
	 *
	 * @param string The name of the table.
	 * @param string The name of the index.
	 */
	function drop_index($table, $name)
	{
		$this->query("ALTER TABLE {$this->table_prefix}$table DROP INDEX $name");
	}
	
	/**
	 * Drop an table with the specified table
	 *
	 * @param string The name of the table.
	 * @param boolean hard drop - no checking
	 * @param boolean use table prefix
	 */
	function drop_table($table, $hard=false, $table_prefix=true)
	{
		if($table_prefix == false)
		{
			$table_prefix = "";
		}
		else
		{
			$table_prefix = $this->table_prefix;
		}
		
		if($hard == false)
		{
			if($this->table_exists($table))
			{
				$this->query('DROP TABLE '.$table_prefix.$table);
			}
		}
		else
		{
			$this->query('DROP TABLE '.$table_prefix.$table);
		}
	}
	
	/**
	 * Replace contents of table with values
	 *
	 * @param string The table
	 * @param array The values
	 */
	function replace_query($table, $replacements=array())
	{
		$columns = '';
		$values = '';
		$comma = '';
		foreach($replacements as $column => $value)
		{
			$columns .= $comma.$column;
			$values .= $comma."'".$value."'";
			
			$comma = ',';
		}
		
		if(empty($columns) || empty($values))
		{
			 return false;
		}
		
		return $this->query("REPLACE INTO {$this->table_prefix}{$table} ({$columns}) VALUES({$values})");
	}
	
	/**
	 * Sets the table prefix used by the simple select, insert, update and delete functions
	 *
	 * @param string The new table prefix
	 */
	function set_table_prefix($prefix)
	{
		$this->table_prefix = $prefix;
	}
	
	/**
	 * Fetched the total size of all mysql tables or a specific table
	 *
	 * @param string The table (optional) (ignored)
	 * @return integer the total size of all mysql tables or a specific table
	 */
	function fetch_size($table='')
	{
		global $config, $lang;
		
		$total = @filesize($config['database']);
		if(!$total || $table != '')
		{
			$total = $lang->na;
		}
		return $total;
	}
	
	// 
	/**
	 * Perform an "Alter Table" query in SQLite < 3.2.0 - Code taken from http://code.jenseng.com/db/
	 *
	 * @param string The table (optional)
	 * @return integer the total size of all mysql tables or a specific table
	 */
	function alter_table_parse($table, $alterdefs)
	{
		if($alterdefs != '')
		{
			$result = $this->query("SELECT sql,name,type FROM sqlite_master WHERE tbl_name = '{$table}' ORDER BY type DESC");
			if($this->num_rows($result) > 0)
			{
				echo $this->num_rows($result);
				$row = $this->fetch_array($result); // Table sql
				$tmpname = 't'.TIME_NOW;
				$origsql = trim(preg_replace("/[\s]+/", " ", str_replace(",", ", ", preg_replace("/[\(]/","( ", $row['sql'], 1))));
				$createtemptableSQL = 'CREATE TEMPORARY '.substr(trim(preg_replace("'".$table."'", $tmpname, $origsql, 1)), 6);
				$createindexsql = array();
				$i = 0;
				$defs = preg_split("/[,]+/", $alterdefs, -1, PREG_SPLIT_NO_EMPTY);
				$prevword = $table;
				$oldcols = preg_split("/[,]+/", substr(trim($createtemptableSQL), strpos(trim($createtemptableSQL), '(')+1), -1, PREG_SPLIT_NO_EMPTY);
				$newcols = array();
				
				for($i = 0; $i < sizeof($oldcols); $i++)
				{
					$colparts = preg_split("/[\s]+/", $oldcols[$i], -1, PREG_SPLIT_NO_EMPTY);
					$oldcols[$i] = $colparts[0];
					$newcols[$colparts[0]] = $colparts[0];
				}
				
				$newcolumns = '';
				$oldcolumns = '';
				reset($newcols);
				
				foreach($newcols as $key => $val)
				{
					$newcolumns .= ($newcolumns ? ', ' : '').$val;
					$oldcolumns .= ($oldcolumns ? ', ' : '').$key;
				}
				
				$copytotempsql = 'INSERT INTO '.$tmpname.'('.$newcolumns.') SELECT '.$oldcolumns.' FROM '.$table;
				$dropoldsql = 'DROP TABLE '.$table;
				$createtesttableSQL = $createtemptableSQL;
				
				foreach($defs as $def)
				{
					$defparts = preg_split("/[\s]+/", $def, -1, PREG_SPLIT_NO_EMPTY);
					$action = strtolower($defparts[0]);
					
					switch($action)
					{
						case 'change':
							if(sizeof($defparts) <= 3)
							{
								$this->error($alterdefs, 'near "'.$defparts[0].($defparts[1] ? ' '.$defparts[1] : '').($defparts[2] ? ' '.$defparts[2] : '').'": syntax error', E_USER_WARNING);
								return false;
							}
							
							if($severpos = strpos($createtesttableSQL, ' '.$defparts[1].' '))
							{
								if($newcols[$defparts[1]] != $defparts[1])
								{
									$this->error($alterdefs, 'unknown column "'.$defparts[1].'" in "'.$table.'"');
									return false;
								}
								
								$newcols[$defparts[1]] = $defparts[2];
								$nextcommapos = strpos($createtesttableSQL, ',', $severpos);
								$insertval = '';
								
								for($i = 2; $i < sizeof($defparts); $i++)
								{
									$insertval .= ' '.$defparts[$i];
								}
								
								if($nextcommapos)
								{
									$createtesttableSQL = substr($createtesttableSQL, 0, $severpos).$insertval.substr($createtesttableSQL, $nextcommapos);
								}
								else
								{
									$createtesttableSQL = substr($createtesttableSQL, 0, $severpos-(strpos($createtesttableSQL, ',') ? 0 : 1)).$insertval.')';
								}
							}
							else
							{
								$this->error($alterdefs, 'unknown column "'.$defparts[1].'" in "'.$table.'"', E_USER_WARNING);
								return false;
							}
							break;
						case 'drop':
							if(sizeof($defparts) < 2)
							{
								$this->error($alterdefs, 'near "'.$defparts[0].($defparts[1] ? ' '.$defparts[1] : '').'": syntax error');
								return false;
							}
							
							if($severpos = strpos($createtesttableSQL, ' '.$defparts[1].' '))
							{
								$nextcommapos = strpos($createtesttableSQL, ',', $severpos);
								
								if($nextcommapos)
								{
									$createtesttableSQL = substr($createtesttableSQL, 0, $severpos).substr($createtesttableSQL, $nextcommapos + 1);
								}
								else
								{
									$createtesttableSQL = substr($createtesttableSQL, 0, $severpos-(strpos($createtesttableSQL, ',') ? 0 : 1) - 1).')';
								}
								
								unset($newcols[$defparts[1]]);
							}
							else
							{
								$this->error($alterdefs, 'unknown column "'.$defparts[1].'" in "'.$table.'"');
								return false;
							}
							break;
						default:
							$this->error($alterdefs, 'near "'.$prevword.'": syntax error');
							return false;
					}
					
					$prevword = $defparts[sizeof($defparts)-1];
				}
			
			
				// This block of code generates a test table simply to verify that the columns specifed are valid in an sql statement
				// This ensures that no reserved words are used as columns, for example
				$this->query($createtesttableSQL);
				
				$droptempsql = 'DROP TABLE '.$tmpname;
				if($this->query($droptempsql, 0) === false)
				{
					return false;
				}
				// End block
				
				
				$createnewtableSQL = 'CREATE '.substr(trim(preg_replace("'{$tmpname}'", $table, $createtesttableSQL, 1)), 17);
				$newcolumns = '';
				$oldcolumns = '';
				reset($newcols);
				
				foreach($newcols as $key => $val)
				{
					$newcolumns .= ($newcolumns ? ', ' : '').$val;
					$oldcolumns .= ($oldcolumns ? ', ' : '').$key;
				}
				
				$copytonewsql = 'INSERT INTO '.$table.'('.$newcolumns.') SELECT '.$oldcolumns.' FROM '.$tmpname;
				
				
				$this->query($createtemptableSQL); // Create temp table
				$this->query($copytotempsql); // Copy to table
				$this->query($dropoldsql); // Drop old table
				
				$this->query($createnewtableSQL); // Recreate original table
				$this->query($copytonewsql); // Copy back to original table
				$this->query($droptempsql); // Drop temp table
			}
			else
			{
				$this->error($alterdefs, 'no such table: '.$table);
				return false;
			}
			return true;
		}
	}

	/**
	 * Fetch a list of database character sets this DBMS supports
	 *
	 * @return array Array of supported character sets with array key being the name, array value being display name. False if unsupported
	 */
	function fetch_db_charsets()
	{
		return false;
	}

	/**
	 * Fetch a database collation for a particular database character set
	 *
	 * @param string The database character set
	 * @return string The matching database collation, false if unsupported
	 */
	function fetch_charset_collation($charset)
	{
		return false;
	}

	/**
	 * Fetch a character set/collation string for use with CREATE TABLE statements. Uses current DB encoding
	 *
	 * @return string The built string, empty if unsupported
	 */
	function build_create_table_collation()
	{
		return '';
	}
}

if(!class_exists('databaseEngine'))
{
	class databaseEngine extends DB_SQLite3 {
	}
}
?>
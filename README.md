# Postgre-To-MySQL-Adapter

Did you build a website anticipating the use of a PostgreSQL database, and end up on a server using MySQL?

No? Well I did. So I created this. 

A simple PHP file you can include in your site header that immediately detects if a server does not support PostgreSQL, and immediately generates several replacement functions that allows your Postgre-based site to interface with a MySQL database instead.

This is especially helpful if you're migrating your site between servers.

So far, only the following PostgreSQL functions are supported:
* pg_connect
* pg_query
* pg_num_rows
* pg_fetch_result
* pg_fetch_row
* pg_fetch_assoc
* pg_fetch_all
* pg_prepare
* pg_execute

There are also several limitations I have yet to work around:

```
Will not support:
	Using the same name for two prepared statements on different connections
	Prepared statements with parameters in an arbitrary order
		(must use standard numerological order: $1, $2, $3, etc...)
	pg_fetch_result, pg_fetch_row and pg_fetch_assoc must specify row number.
		Otherwise the first row will always be called.
	pg_connect won't allow custom options
	connection must always be specified on queries and prepared statements.
```

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

###Other Known Issues
One user has reported problems with prepared statements. I have built a workaround that bypasses prepared statements entirely, but have chosen not to upload it for three reasons. First, it allows for SQL injection that prepared statements are intended to foil, defeating the purpose of prepared statements and creating a massive security hole. Second, I'm certain there's a better way, but I haven't had the chance to properly diagnose the problem yet. Third, the file that contains the fix is old and hasn't been updated with the improvements from the original. 

If any further reports of this issue crop up, and we are unable to find a more workable fix, I will upload my old kludgy workaround.

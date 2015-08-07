# Postgre-To-MySQL-Adapter

Did you build a website anticipating the use of a PostgreSQL database, and end up on a server using MySQL?

No? Well I did. So I created this. 

A simple PHP file you can include in your site header that detects if a server does not support PostgreSQL, and immediately generates several replacement functions that allows your Postgre-based site to interface with a MySQL database instead.

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

Though more may be added as they are requested or required.

There are also a few limitations I have yet to work around:
```
Will not support:
	pg_fetch_result, pg_fetch_row and pg_fetch_assoc must specify row number.
		Otherwise the first row will always be called.
	pg_connect won't allow custom options
	pg_query might have problems detecting whether there are one 
		or more than one queries in a call. 
```

Not all features have been fully tested, due to the limitations of my set-up, lack of time, and personal overconfidence. However, I plan to support this adapter to the best of my ability, as long as I can. If anyone encounters any issues, please report them, and I will try my best to tackle them, and issue a fix as soon as I am able.

###Other Known Issues
A colleague of mine has reported problems with prepared statements. I have built a workaround that bypasses prepared statements entirely, but have chosen not to upload it for three reasons. First, it allows for SQL injection that prepared statements are intended to foil, defeating the purpose of prepared statements and creating a massive security hole. Second, I'm certain there's a better way, but I haven't had the chance to properly diagnose the problem yet. Third, the file that contains the fix is old and hasn't been updated with the improvements from the original. 

If any further reports of this issue crop up, and we are unable to find a more workable fix, I will upload my old kludgy workaround.

This adapter also fails in the following scenario: If a prepared statement is built calling a table, then that table is subsequently destroyed and rebuilt, and the aforementioned statement is executed.

While this may seem like an unusual scenario, Postgre handles it fine, but my MySQL does not. I have yet to come up with a workable solution.

##Installation
To install the adapter, download mysql_adapter.php, and add this block of code to your header file, above any database calls. Be sure the code points to the file's proper location.

```
if (file_exists(__DIR__.'/mysql_adapter.php'))
	include_once 'mysql_adapter.php';
```

The adapter automatically detects if the server is already the PostgreSQL PHP drivers. So it's not necessary to worry about uninstalling it if you're migrating back to PostgreSQL.
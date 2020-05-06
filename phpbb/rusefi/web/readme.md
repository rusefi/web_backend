To manually get rid of XXX (this will remove all data):

- Delete the row for the extension from the phpbb_ext table.

select * FROM phpbb_ext WHERE ext_name like '%rusefi%'

DELETE FROM `phpbb_ext` WHERE ext_name like %rusefi%

- Delete any rows in the phpbb_config table where config_name like %XXX%

select * from phpbb_config where config_name like '%rusefi%'

- Delete columns in the phpbb_users table where the column name is like user_XXX_%
select * from phpbb_config where config_name like '%rusefi%'


- Delete any rows in the phpbb_migrations table where migration_name like %XXX%

select * from phpbb_migrations where migration_name like '%rusefi%'

- Delete any rows in the phpbb_modules table where module_langname like %XXX%

select * from phpbb_modules where module_langname like '%rusefi%'

- Make sure the /ext/xxx/XXXs folder and files inside are removed
- Purge the cache
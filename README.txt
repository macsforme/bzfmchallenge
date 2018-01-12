BZFLAG FUNMATCH CHALLENGE

By Joshua Bodine
https://github.com/macsforme/bzfmchallenge

This repository contains the PHP source code for the BZFlag FunMatch Challenge web site. All of the site logic is located in the index.php file. Some setup-related files are located in the setup/ subdirectory.

To set up this web site, you must create a MySQL database, import the table structure located in setup/database.sql, and set up MySQL user credentials to access this database. Then, you must copy the file setup/config.example.php into the base directory, edit it with the appropriate settings, and rename it to config.php. Navigate to this web site in a web browser to complete setup. Note that until you set up the database and the configuration file, anyone can log into the site and configure the administration groups, so complete this step as soon as possible.

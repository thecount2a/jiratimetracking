# jiratimetracking
Web-based system for tracking employee time using Jira

Features:
* Uses Jira for persistent data storage and redis for temporary caching storage
* Allows a "punch-in" and "punch-out" type of interface for improved accuracy in time tracking

Install instructions:
* Place PHP files in webroot
* Build and put hledger binary in location accessible from web server
* Install predis in subdirectory called "predis"
* Run local redis server
* Setup OAuth pairing with Jira cloud instance
* Copy config-example.php to config.php and change settings to appropriate values for your use

Dependencies:
* Redis -- tested with version 3.2.8
* predis -- tested with version 1.1
* hledger -- tested with version 1.2 (must be fairly recent version)

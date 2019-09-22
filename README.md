# A PHP-Script that turns [OpenProject](https://www.openproject.org/) into a ticket system.
This small PHP script works as follows:
* Called periodically via cronjob
* If new mails are in the Inbox (e.g. support@varioous.at)
  * The script automatically creats a ticket
  * If the user has no account a account is created and invitation mail is sent to user 
  * If the user has no support-project assigned (identifier contains "support") a new project is created. This is needed because otherwise the user would see the support tickets of other projects/user
  * If the user has an account and a support-project, the ticket is created in this support-project
  * A mail to the user is sent with information about the ticket (number, link)
* Slack integration: After ticket creation a slack message is sent to slack channel
* Job integration: For every incoming mail to a job adress an automatic ticket is created in the ticket project inside OpenProject and assigned to the human ressource person

## Infos
* Find more infos in [this](https://varioous.at/blog/ticketing-system-mit-openproject/) blog post about "Ticketing-System mit OpenProject" - German only.
* Blog post update [here](https://varioous.at/blog/ticketing-system-mit-openproject-update/)

## Updates
* composer support
* slack integration
* installation instruction
* creation of tickets from job mailbox

## Features
* Turn OpenProject in a ticket system
* Slack Integration
* Support for job applications

## Requirements
* Web server with php 7+
* OpenProject server with version 8.0.0+
* OpenProject server with ssh server runing
* Mail server (mail box as imap available)

## Installation
* Configure OpenProject 
  * Configure user creation (users will get mail for activation)
  * Get api key
  * Create user role for new users
* Copy script on server
* Execute composer install
* Create directory "attachments" in root directory
* Check config values in file index.php (const variables)
* Check config values in code.php (const variables)
* Create Cronjob to run the script periodically
* Change default messages for mail reply's
* Remove some varioous specific checks (e.q. we always check for {Spam?} in mail subject, because our mail scanner always add's this to subject if mail is possible spam.

## Compatibility
* OpenProject 8.0.0-9.0.3 (tested)

## Useful informations
* The script marks mails as flagged if the already processed by the script
* OpenProject API v3 is used. The api is very basic and does not offer all needed functions (create project, add member to project, edit the author of a project,...)
* There is also a command line interface for some basic task, e.g. create project. I use this for creating project and assign a user to this project. For this i connect via ssh and execute the command (like on putty)

## Possible future improvments:
* Bot / spam check
* Make it available as OpenProject Plugin

## Sponsorship
Development time and resources for this tool are provided by [varioous](https://varioous.at/), a digital agency based in upper austria.
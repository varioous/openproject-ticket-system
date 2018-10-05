# A PHP-Script that turns [OpenProject](https://www.openproject.org/) into a ticket system.
This small PHP script works as follows:
* Called periodically via cronjob
* If new mails are in the Inbox (e.g. support@varioous.at)
** The script automatically creats a ticket
** If the user has no account a account is created and invitation mail is sent to user
** If the user has no support-project assigned (identifier contains "support") a new project is created. This is needed because otherwise the user would see the support tickets of other projects/user
** If the user has a account and a support-project, the ticket is created in this support-project
** A mail to the user is sent with information about the ticket (number, link)

## Compatibility
* Tested with OpenProject 8.0.1

## Useful informations
* The script marks mails as flagged if the already processed by the script
* OpenProject API v3 is used. The api is very basic and does not offer all needed functions (create project, add member to project, edit the author of a project,...)
* There is also a command line interface for some basic task, e.g. create project. I use this for creating project and assign a user to this project. For this i connect via ssh and execute the command (like on putty)

## Possible future improvments:
* Bot / Spam check
* Make it available as OpenProject Plugin

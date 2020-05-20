<?php

/**
 * Copyright notice
 *
 * (c) 2019 Harald Holzmann <harald@varioous.at>, varioous OG
 *
 * All rights reserved
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 */

class OpenProject
{

    //role id for new users that are created with this script
    const SUPPORT_ROLE_ID = 0;

    //role id of admin
    const ADMIN_ROLE_ID = 0;

    //user id, every ticket is assigned to this user
    const SUPPORT_DEFAULT_ASSIGN_USER = 0;

    //default user for job tickets
    const JOBS_DEFAULT_ASSIGN_USER = 0;

    //project id for job tickets
    const JOBS_DEFAULT_PROJECT = 0;

    //open project api key
    const OPEN_PROJECT_API_KEY = "xxxxxxxxx";

    //api version to use
    const OPEN_PROJECT_API_VERSION = 3;

    //url of the open project api
    const OPEN_PROJECT_API_URL = "https://projects.varioous.at/api/v" . self::OPEN_PROJECT_API_VERSION . "/";

    //slack tocken to send message
    const SLACK_TOKEN = "xxxxxxxxx";

    //slack api url
    const SLACK_API_URL = "https://slack.com/api/chat.postMessage";
    
    //rocket chat api url
    const ROCKET_CHAT_API_URL = "xxxxxxxxxxxx";

    //rocket chat room id
    const ROCKET_CHAT_ROOM_ID = "xxxx";

    //rocket chat auth token
    const ROCKET_CHAT_AUTH_TOKEN = "sxxxxxxxxxxxxx";

    //rocket chat user id
    const ROCKET_CHAT_USER_ID = "xxxxxxxxxx";

    public static function createJobTicket($mail, $mailInfo)
    {
        //data for ticket
        $ticketData = array();

        //type
        $ticketData['_type'] = 'WorkPackage';

        $subject = 'Jobanfrage';
        if (!is_null($mailInfo->subject) && strlen($mailInfo->subject) > 0) {
            //subject
            $subject = $mailInfo->subject;
        }
        $ticketData['subject'] = $subject;

        //description
        $descriptionArray = array();
        $descriptionArray['format'] = "textile";
        $descriptionArray['raw'] = "Absender-Mail-Adresse: " . $mailInfo->fromAddress . "\n";
        $descriptionArray['raw'] .= "Absender-Name: " . $mailInfo->fromName . "\n";
        $descriptionArray['raw'] .= " ............................. " . "\n\n";
        $descriptionArray['raw'] .= $mail->textPlain;
        $ticketData['description'] = $descriptionArray;

        //assignee
        $assigneeArray = array();
        $assigneeArray['href'] =
            "/api/v" . self::OPEN_PROJECT_API_VERSION . "/users/" . intval(self::JOBS_DEFAULT_ASSIGN_USER);
        $ticketData['assignee'] = $assigneeArray;

        //assign project data
        $projectArray = array();
        $projectArray['href'] = "/api/v" . self::OPEN_PROJECT_API_VERSION . "/projects/" . self::JOBS_DEFAULT_PROJECT;
        $ticketData['project'] = $projectArray;

        //send request
        $ch = curl_init(self::OPEN_PROJECT_API_URL . 'work_packages');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . self::OPEN_PROJECT_API_KEY);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ticketData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        $response = curl_exec($ch);
        curl_close($ch);

        $respArray = json_decode($response);
        if (is_null($respArray->id)) {
            //send mail that creation failed
            return -1;
        }
        $workPackageId = $respArray->id;

        //attachments
        foreach ($mail->getAttachments() as $attachment) {
            self::createAttachement($attachment, $workPackageId);
        }

        return $respArray->id;
    }

    public static function createTicket($mail, $mailInfo)
    {
        //data for ticket
        $ticketData = array();

        //type
        $ticketData['_type'] = 'WorkPackage';

        $subject = 'Supportanfrage';
        if (!is_null($mailInfo->subject) && strlen($mailInfo->subject) > 0) {
            //subject
            $subject = $mailInfo->subject;
        }

        $ticketData['subject'] = $subject;

        //description
        $descriptionArray = array();
        $descriptionArray['format'] = "textile";
        $descriptionArray['raw'] = "Ersteller-Mail-Adresse: " . $mailInfo->fromAddress . "\n";
        $descriptionArray['raw'] .= "Ersteller-Name: " . $mailInfo->fromName . "\n";
        $descriptionArray['raw'] .= " ............................. " . "\n\n";
        $descriptionArray['raw'] .= $mail->textPlain;
        $ticketData['description'] = $descriptionArray;

        //assignee
        $assigneeArray = array();
        $assigneeArray['href'] =
            "/api/v" . self::OPEN_PROJECT_API_VERSION . "/users/" . intval(self::SUPPORT_DEFAULT_ASSIGN_USER);
        $ticketData['assignee'] = $assigneeArray;

        //sender, first check if user or email already exists
        $user = self::getUser($mailInfo->fromAddress);
        if (intval($user->count) == 0) {
            //create user
            $user = self::createUser($mailInfo);
            $userId = $user->id;
        } else {
            $userId = $user->_embedded->elements[0]->id;
        }

        //check if user already has a support project
        $supportProjectId = -1;
        $projects = self::getAllProjects();
        foreach ($projects->_embedded->elements as $project) {
            $availableAssignees = self::getProjectAvailableAssignees($project->id);
            foreach ($availableAssignees->_embedded->elements as $possibleAssignees) {
                if (intval($possibleAssignees->id) == intval($userId)) {
                    if (strpos($project->identifier, 'support') !== false) {
                        $supportProjectId = $project->id;
                    }

                }
            }
        }

        //check if support project found
        if ($supportProjectId == -1) {
            //create new project
            $projectName = explode("@", $mailInfo->fromAddress, 2);
            $projectIdentifier =
                strtolower('created-support-' . preg_replace('/[^a-z]/i', '', $projectName[0]) . '-' . rand(1, 99));
            self::createProject($projectIdentifier, 'Support - ' . ucfirst($projectName[0]));

            //load project id
            $projectsNew = self::getAllProjects();
            foreach ($projectsNew->_embedded->elements as $projectVal) {
                if (stripos($projectVal->identifier, $projectIdentifier) !== false) {
                    $supportProjectId = $projectVal->id;
                }
            }

            //add user to project as member
            self::addProjectMembership($supportProjectId, $userId, self::SUPPORT_ROLE_ID);

            //also add default user to project as member
            self::addProjectMembership($supportProjectId, self::SUPPORT_DEFAULT_ASSIGN_USER, self::ADMIN_ROLE_ID);
        }

        //assign project data
        $projectArray = array();
        $projectArray['href'] = "/api/v" . self::OPEN_PROJECT_API_VERSION . "/projects/" . $supportProjectId;
        $ticketData['project'] = $projectArray;

        //send request
        $ch = curl_init(self::OPEN_PROJECT_API_URL . 'work_packages?notify=false');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . self::OPEN_PROJECT_API_KEY);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ticketData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        $response = curl_exec($ch);
        curl_close($ch);

        $respArray = json_decode($response);
        if (is_null($respArray->id)) {
            //send mail that creation failed
            return -1;
        }
        $workPackageId = $respArray->id;

        //attachments
        foreach ($mail->getAttachments() as $attachment) {
            self::createAttachement($attachment, $workPackageId);
        }

        //set user as observer
        self::addUserToProjectAsWatcher($workPackageId, $userId);

        return $respArray->id;
    }

    public static function addProjectMembership($projectId, $userId, $roleId)
    {
        //project data
        $projectArray = array();
        $projectArray['href'] = '/api/v' . self::OPEN_PROJECT_API_VERSION . '/projects/' . intval($projectId);

        //user data
        $userArray = array();
        $userArray['href'] = '/api/v' . self::OPEN_PROJECT_API_VERSION . '/users/' . intval($userId);

        //roles data
        $rolesArray = array();
        $roleArray = array();
        $roleArray['href'] = '/api/v' . self::OPEN_PROJECT_API_VERSION . '/roles/' . intval($roleId);
        array_push($rolesArray, $roleArray);

        //membership data
        $membershipData = array();
        $membershipData['project'] = $projectArray;
        $membershipData['principal'] = $userArray;
        $membershipData['roles'] = $rolesArray;

        //send request
        $ch = curl_init(self::OPEN_PROJECT_API_URL . 'memberships');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . self::OPEN_PROJECT_API_KEY);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($membershipData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function createUser($mailInfo)
    {
        //data for user
        $userData = array();
        $userData['login'] = $mailInfo->fromAddress;
        $userData['email'] = $mailInfo->fromAddress;
        $userData['status'] = 'invited';
        $name = explode(' ', $mailInfo->fromName);
        $userData['firstName'] = $name[0];
        if (count($name) >= 2) {
            $userData['lastName'] = $name[1];
        }

        //send request
        $ch = curl_init(self::OPEN_PROJECT_API_URL . 'users');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . self::OPEN_PROJECT_API_KEY);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response);
    }

    public static function addUserToProjectAsWatcher($workPackageId, $userId)
    {
        //description
        $userData = array();
        $userArray = array();
        $userArray['href'] = '/api/v' . self::OPEN_PROJECT_API_VERSION . '/users/' . intval($userId);
        $userData['user'] = $userArray;

        //send request
        $ch = curl_init(self::OPEN_PROJECT_API_URL . 'work_packages/' . intval($workPackageId)
            . '/watchers?notify=false');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . self::OPEN_PROJECT_API_KEY);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function addCommentToWorkPackage($workPackageId, $mail, $mailInfo)
    {
        $commentBody = "Ersteller-Mail-Adresse: " . $mailInfo->fromAddress . "\n";
        $commentBody .= "Ersteller-Name: " . $mailInfo->fromName . "\n";
        $commentBody .= " ............................. " . "\n";
        $commentBody .= $mail->textPlain;

        //note
        $commentData = array();
        $commentBodyDataArray = array();
        $commentBodyDataArray['raw'] = $commentBody;
        $commentData['comment'] = $commentBodyDataArray;

        //send request
        $ch = curl_init(self::OPEN_PROJECT_API_URL . 'work_packages/' . intval($workPackageId) . '/activities');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . self::OPEN_PROJECT_API_KEY);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($commentData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function getUser($mail)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'apikey:' . self::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => self::OPEN_PROJECT_API_URL
                . "users?filters=[{%22login%22:{%22operator%22:%20%22=%22,%22values%22:[%22" . $mail . "%22]}}]"
        ));

        $resp = curl_exec($curl);
        curl_close($curl);
        return json_decode($resp);
    }

    public static function getWorkPackage($id)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'apikey:' . self::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => self::OPEN_PROJECT_API_URL . "work_packages/" . intval($id)
        ));

        $resp = curl_exec($curl);
        curl_close($curl);
        return json_decode($resp);
    }

    public static function getAllProjects()
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_USERPWD => 'apikey:' . self::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => self::OPEN_PROJECT_API_URL . "projects"
        ));

        $resp = curl_exec($curl);
        curl_close($curl);
        return json_decode($resp);
    }

    public static function getProjectAvailableAssignees($projectId)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'apikey:' . self::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => self::OPEN_PROJECT_API_URL . "projects/" . $projectId . "/available_assignees"
        ));

        $resp = curl_exec($curl);
        curl_close($curl);
        return json_decode($resp);
    }
    
    public static function sendMessageToRocketChat($message)
    {
        $data = array("message" => ["rid" => self::ROCKET_CHAT_ROOM_ID, "msg" => $message]);
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => self::ROCKET_CHAT_API_URL,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => json_encode($data),
          CURLOPT_HTTPHEADER => array(
            "X-Auth-Token: ".self::ROCKET_CHAT_AUTH_TOKEN,
            "X-User-Id: ".self::ROCKET_CHAT_USER_ID,
            "Content-type: application/json"
          ),
        ));
        
        curl_exec($curl);
        curl_close($curl);
    }

    public static function sendMessageToSlack($message)
    {
        $ch = curl_init(self::SLACK_API_URL);
        $data = http_build_query([
            "token" => self::SLACK_TOKEN,
            "channel" => "#varioous_support",
            "text" => $message,
            "username" => "Open-Project-Bot",
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function createAttachement($attachement, $ticketId)
    {
        $curl = curl_init(self::OPEN_PROJECT_API_URL . 'work_packages/' . $ticketId . '/attachments');
        $boundary = '----WebKitFormBoundary' . uniqid();
        $post_data = self::build_data_files($attachement, $boundary);

        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'apikey:' . self::OPEN_PROJECT_API_KEY,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_TIMEOUT => 200,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: multipart/form-data; boundary=" . $boundary
            ),
        ));
        curl_exec($curl);
        curl_close($curl);
    }

    private static function build_data_files($attachment, $delimeter)
    {
        $attachmentData = array();
        $attachmentData['fileName'] = $attachment->name;

        $data = '';
        $eol = "\r\n";
        $data .= "--" . $delimeter . $eol;
        $data .= 'Content-Disposition: form-data; name="metadata"' . $eol . $eol;
        $data .= json_encode($attachmentData) . $eol;

        $data .= "--" . $delimeter . $eol . 'Content-Disposition: form-data; name="file"; filename="'
            . $attachment->name . '"' . $eol . 'Content-Type: ' . mime_content_type($attachment->filePath) . $eol;

        $data .= $eol;
        $data .= file_get_contents($attachment->filePath) . $eol;

        $data .= "--" . $delimeter . "--";
        return $data;
    }
}

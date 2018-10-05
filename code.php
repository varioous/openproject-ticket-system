<?php

class OpenProject
{
    const SUPPORT_TICKET_ID = 1908;

    const SUPPORT_ROLE_ID = 1908;

    const ADMIN_ROLE_ID = 1908;

    const SUPPORT_DEFAULT_ASSIGN_USER = 1908;

    const JOBS_DEFAULT_ASSIGN_USER = 1908;

    const JOBS_DEFAULT_PROJECT = 1908;

    const OPEN_PROJECT_API_KEY = "XXXXXXXXXXX";

    const OPEN_PROJECT_API_VERSION = 3;

    const OPEN_PROJECT_API_URL = "XXXXXXXXX/api/v" . OpenProject::OPEN_PROJECT_API_VERSION . "/";

    public static function createTicket($mail, $mailInfo)
    {
        //data for ticket
        $ticketData = array();

        //type
        $ticketData['_type'] = 'WorkPackage';

        //check subject, cause subject can be empty
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
        $descriptionArray['raw'] .= " ............................. " . "\n";
        $descriptionArray['raw'] .= $mail->textPlain;
        $ticketData['description'] = $descriptionArray;

        //assignee
        $assigneeArray = array();
        $assigneeArray['href'] = "/api/v" . OpenProject::OPEN_PROJECT_API_VERSION . "/users/"
            . intval(OpenProject::SUPPORT_DEFAULT_ASSIGN_USER);
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
            //login via ssh to open project server
            $ssh = new Net_SSH2('XXXXXXX');
            $ssh->login('XXXXX', 'XXXXXXXXXX');
            //create project name and command to execute
            $projectName = explode("@", $mailInfo->fromAddress, 2);
            $projectIdentifier =
                strtolower('created-support-' . preg_replace('/[^a-z]/i', '', $projectName[0]) . '-' . rand(1, 99));
            $createProjectTask =
                'openproject run bundle exec rails runner "Project.create(identifier:  \'' . $projectIdentifier
                . '\' , name: \'Support - ' . ucfirst($projectName[0]) . '\');"';
            //execute command
            $ssh->exec($createProjectTask);
            $ssh->disconnect();

            //sleep -> wait that command is executed
            sleep(15);

            //load project id
            $projectsNew = self::getAllProjects();
            foreach ($projectsNew->_embedded->elements as $projectVal) {
                if (strcmp($projectVal->identifier, $projectIdentifier) == 0) {
                    $supportProjectId = $projectVal->id;
                }
            }

            //add user to project as member
            $memberCreateTask =
                'openproject run bundle exec rails runner "Member.create(project_id: ' . intval($supportProjectId)
                . ', role_ids: [' . intval(OpenProject::SUPPORT_ROLE_ID) . '], user_id: ' . intval($userId) . ');"';

            //also add default user to project as member
            $memberCreateTaskDefaultUser =
                'openproject run bundle exec rails runner "Member.create(project_id: ' . intval($supportProjectId)
                . ', role_ids: [' . intval(OpenProject::ADMIN_ROLE_ID) . '], user_id: '
                . intval(OpenProject::SUPPORT_DEFAULT_ASSIGN_USER) . ');"';

            //connect again
            $sshMember = new Net_SSH2('XXXXX');
            $sshMember->login('XXXXX', 'XXXXXXXX');
            $sshMember->exec($memberCreateTask);
            $sshMember->exec($memberCreateTaskDefaultUser);
            $sshMember->disconnect();

            //sleep -> wait that command is executed
            sleep(15);
        }

        //assign project data
        $projectArray = array();
        $projectArray['href'] = "/api/v" . OpenProject::OPEN_PROJECT_API_VERSION . "/projects/" . $supportProjectId;
        $ticketData['project'] = $projectArray;

        //send request
        $ch = curl_init(OpenProject::OPEN_PROJECT_API_URL . 'work_packages?notify=false');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY);
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
        $ch = curl_init(OpenProject::OPEN_PROJECT_API_URL . 'users');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY);
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
        $userArray['href'] = '/api/v' . OpenProject::OPEN_PROJECT_API_VERSION . '/users/' . intval($userId);
        $userData['user'] = $userArray;

        //send request
        $ch = curl_init(OpenProject::OPEN_PROJECT_API_URL . 'work_packages/' . intval($workPackageId) . '/watchers?notify=false');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY);
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

    public static function getUser($mail)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => OpenProject::OPEN_PROJECT_API_URL
                . "users?filters=[{%22login%22:{%22operator%22:%20%22=%22,%22values%22:[%22" . $mail . "%22]}}]"
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
            CURLOPT_USERPWD => 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => OpenProject::OPEN_PROJECT_API_URL . "projects"
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
            CURLOPT_USERPWD => 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY,
            CURLOPT_URL => OpenProject::OPEN_PROJECT_API_URL . "projects/" . $projectId . "/available_assignees"
        ));

        $resp = curl_exec($curl);
        curl_close($curl);
        return json_decode($resp);
    }

    public static function createAttachement($attachement, $ticketId)
    {
        $curl = curl_init(OpenProject::OPEN_PROJECT_API_URL . 'work_packages/' . $ticketId . '/attachments');
        $boundary = '----WebKitFormBoundary' . uniqid();
        $post_data = self::build_data_files($attachement, $boundary);

        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'apikey:' . OpenProject::OPEN_PROJECT_API_KEY,
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
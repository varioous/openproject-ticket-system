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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require 'code.php';

//connect string for support mailbox
const MAIL_SERVER_CONNECT_PATH = "xxxxxxxxx";
//username of mailbox
const MAIL_SERVER_USERNAME = "xxxxxxxxx";
//password of mailbox
const MAIL_SERVER_PASSWORD = "xxxxxxxxx";
//hostname of mail server
const MAIL_SERVER_HOSTNAME = "xxxxxxxxx";
//domain of open project server
const OPEN_PROJECT_DOMAIN = "xxxxxxxxx";

//username of mailbox for incoming job applications
const MAIL_SERVER_JOB_USERNAME = "xxxxxxxxx";
//password of job mailbox
const MAIL_SERVER_JOB_PASSWORD = "xxxxxxxxx";

$mailbox = new PhpImap\Mailbox('{' . MAIL_SERVER_CONNECT_PATH . '}INBOX', MAIL_SERVER_USERNAME, MAIL_SERVER_PASSWORD,
    __DIR__ . "/attachments");

$mailsIds = $mailbox->searchMailbox('ALL');

//go through all unread mails
foreach ($mailsIds as $mailId) {
    $mail = $mailbox->getMail($mailId, true);
    $mailHeader = $mailbox->getMailHeader($mailId);

    //check if mail already processed
    $mailInfos = $mailbox->getMailsInfo([$mailId]);

    if (intval($mailInfos[0]->flagged) == 0) {
        $isComment = false;
        //check if answer to existing ticket

        if (stripos($mailInfos[0]->subject, '{Spam?}') === false
            && stripos($mailInfos[0]->subject, '{Disarmed}') === false) {

            if (stripos($mailInfos[0]->subject, 'Task #') !== false) {
                //extract ticket number
                $startpos = stripos($mailInfos[0]->subject, 'Task #');
                $endpos = stripos($mailInfos[0]->subject, ':', $startpos);
                $ticketNumber = substr($mailInfos[0]->subject, $startpos + 6, $endpos - $startpos - 6);
                if (is_numeric($ticketNumber)) {
                    $workPackage = OpenProject::getWorkPackage($ticketNumber);
                    if ($workPackage->_type != "Error") {
                        //add comment
                        $isComment = true;
                        OpenProject::addCommentToWorkPackage($ticketNumber, $mail, $mailHeader);
                    }
                }
            }

            if (!$isComment) {
                //create ticket
                $createdId = OpenProject::createTicket($mail, $mailHeader);

                //send notification mail to sender
                $mail = new PHPMailer(true);

                //Server settings
                $mail->isSMTP();
                $mail->Host = MAIL_SERVER_HOSTNAME;
                $mail->SMTPAuth = true;
                $mail->Username = MAIL_SERVER_USERNAME;
                $mail->Password = MAIL_SERVER_PASSWORD;
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';
                $mail->isHTML(true);

                //Recipients
                $mail->setFrom(MAIL_SERVER_USERNAME);
                $mail->addAddress($mailHeader->fromAddress, $mailHeader->fromName);
                $mail->addReplyTo(MAIL_SERVER_USERNAME);

                $workPackageUrl = OPEN_PROJECT_DOMAIN . "/work_packages/" . $createdId;

                $firstColumn = 'vielen Dank für Ihre Anfrage betreffend <strong>' . $mailHeader->subject
                    . '</strong> bei unserer Supportabteilung.';
                if (!is_null($mailHeader->subject) && strlen($mailHeader->subject) > 0) {
                    $firstColumn = 'vielen Dank für Ihre Anfrage bei unserer Supportabteilung.';
                }

                $subject = 'Supportanfrage';
                if (!is_null($mailInfos[0]->subject) && strlen($mailInfos[0]->subject) > 0) {
                    //subject
                    $subject = $mailInfos[0]->subject;
                }

                OpenProject::sendMessageToSlack('Es wurde ein neues Support-Ticket von ' . $mailHeader->fromAddress
                    . ' mit dem Betreff "' . $subject . '" erstellt (' . $workPackageUrl . ').');

                //Content
                $mail->Subject = 'Ihre Supportanfrage [#' . $createdId . ']';
                $mail->Body = '<p>Guten Tag,<p>
<p>' . $firstColumn . ' Wir haben ein Ticket mit der Nummer <strong>#' . $createdId . '</strong> 
in unserem Ticket-System erstellt.</p>
<p>Wir werden uns bemühen, Ihr Anliegen schnellstmöglich zu bearbeiten.</p>
<p>Vielen Dank,<br>
Harald Holzmann und das varioous Supportteam</p>
<p>PS: Sie können den aktuellen Status jederzeit unter <a href="' . $workPackageUrl . '">' . $workPackageUrl
                    . '</a> einsehen.</p>';

                if ($mailHeader->fromAddress !== "info@varioous.at") {
                    $mail->send();
                }
            }

        }
        //mark mail as flagged -> means already processed
        $mailbox->setFlag([$mailId], '\\Flagged');

    }
}

$mailbox->disconnect();

//do the same for job mailbox
$mailbox =
    new PhpImap\Mailbox('{' . MAIL_SERVER_CONNECT_PATH . '}INBOX', MAIL_SERVER_JOB_USERNAME, MAIL_SERVER_JOB_PASSWORD,
        __DIR__ . "/attachments");

$mailsIds = $mailbox->searchMailbox('ALL');

//go through all unread mails
foreach ($mailsIds as $mailId) {
    $mail = $mailbox->getMail($mailId, true);
    $mailHeader = $mailbox->getMailHeader($mailId);

    //check if mail already proccessed
    $mailInfos = $mailbox->getMailsInfo([$mailId]);
    if (intval($mailInfos[0]->flagged) == 0) {
        //mail not flagged, so not yet processed

        if (stripos($mailInfos[0]->subject, '{Spam?}') === false
            && stripos($mailInfos[0]->subject, '{Disarmed}') === false) {

            //create ticket
            $createdId = OpenProject::createJobTicket($mail, $mailHeader);

            //send notification mail to sender
            $mail = new PHPMailer(true);

            //Server settings
            $mail->isSMTP();
            $mail->Host = MAIL_SERVER_HOSTNAME;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_SERVER_JOB_USERNAME;
            $mail->Password = MAIL_SERVER_JOB_PASSWORD;
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->isHTML(true);

            //Recipients
            $mail->setFrom(MAIL_SERVER_JOB_USERNAME);
            $mail->addAddress($mailHeader->fromAddress, $mailHeader->fromName);
            $mail->addReplyTo(MAIL_SERVER_JOB_USERNAME);

            //Content
            $mail->Subject = 'Ihre Bewerbung';
            $mail->Body = '<p>Sehr geehrte/r Herr/Frau Bewerber/in,<p>
<p>Wir danken Ihnen für die Zusendung Ihrer Bewerbung und das damit entgegengebrachte Vertrauen.</p>
<p>Die Bearbeitung der eingegangenen Bewerbungen wird einige Zeit in Anspruch nehmen. Wir bitten Sie um ein wenig Geduld. Nach eingehender Prüfung der Unterlagen werden wir uns unaufgefordert wieder mit Ihnen in Verbindung setzen.</p>
<p>Mit freundlichen Grüßen,<br>
Georg Wurz</p>';
            $mail->send();

            //mark mail as flagged -> means already processed
            $mailbox->setFlag([$mailId], '\\Flagged');
        }
    }
}
$mailbox->disconnect();
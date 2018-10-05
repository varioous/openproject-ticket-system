<?php

require 'vendor/autoload.php';
require 'code.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$mailbox = new PhpImap\Mailbox('{[SERVER]:[PORT]/imap/ssl}INBOX', '[USERNAME]', '[PASSWORD]', __DIR__ . "/attachments");
$mailsIds = $mailbox->searchMailbox('ALL');

//go through all unread mails
foreach ($mailsIds as $mailId) {
    $mail = $mailbox->getMail($mailId, true);
    $mailHeader = $mailbox->getMailHeader($mailId);

    //check if mail already proccessed
    $mailInfos = $mailbox->getMailsInfo([$mailId]);
    if (intval($mailInfos[0]->flagged) == 0) {

        //create ticket
        $createdId = OpenProject::createTicket($mail, $mailHeader);

        //send notification mail to sender
        $mail = new PHPMailer(true);

        //Server settings
        $mail->isSMTP();
        $mail->Host = '[SERVER]';
        $mail->SMTPAuth = true;
        $mail->Username = '[USERNAME]';
        $mail->Password = '[PASSWORD]';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);

        //Recipients
        $mail->setFrom('XXXXXXXXXX');
        $mail->addAddress($mailHeader->fromAddress, $mailHeader->fromName);
        $mail->addReplyTo('XXXXXXXXXX');

        //link to work package
        $workPackageUrl = "XXXXXXXXXXX/work_packages/" . $createdId;

        //first column of request (maybe subject is empty)
        $firstColumn = 'vielen Dank für Ihre Anfrage betreffend <strong>' . $mailHeader->subject
            . '</strong> bei unserer Supportabteilung.';
        if (!is_null($mailHeader->subject) && strlen($mailHeader->subject) > 0) {
            $firstColumn = 'vielen Dank für Ihre Anfrage bei unserer Supportabteilung.';
        }

        //Content of mail
        $mail->Subject = 'Ihre Supportanfrage [#' . $createdId . ']';
        $mail->Body =
            '<p>Guten Tag,<p><p>' . $firstColumn . ' Wir haben ein Ticket mit der Nummer <strong>#' . $createdId . '</strong> in unserem Ticket-System erstellt.</p>
            <p>Wir werden uns bemühen, Ihr Anliegen schnellstmöglich zu bearbeiten.</p>
            <p>Vielen Dank,<br>Harald Holzmann und das varioous Supportteam</p>
            <p>PS: Sie können den aktuellen Status jederzeit unter <a href="' . $workPackageUrl . '">' . $workPackageUrl . '</a> einsehen.</p>';

        //send mail
        $mail->send();

        //mark mail as flagged -> means already processed
        $mailbox->setFlag([$mailId], '\\Flagged');
    }
}
//disconnect
$mailbox->disconnect();
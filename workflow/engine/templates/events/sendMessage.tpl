/*
 * Autogenerated by Processmaker Daemon System
 * 
 * Generic Send Message trigger
 * {timestamp}
 */

$sRecipientTO  = "{TO}";
$sRecipientCC  = "{CC}";
$sRecipientBCC = "{BCC}";

/*Composing the message using PMFgetUserEmailAddress() PM function*/
$from     = '{from}';
$to       = PMFgetUserEmailAddress($sRecipientTO,  @@APPLICATION);
$cc       = PMFgetUserEmailAddress($sRecipientCC,  @@APPLICATION);
$bcc      = PMFgetUserEmailAddress($sRecipientBCC, @@APPLICATION);
$subject  = '{subject}';
$template = '{template}';

/*send using PMFSendMessage() PM function*/
PMFSendMessage(@@APPLICATION, $from, $to, $cc, $bcc, $subject, $template);
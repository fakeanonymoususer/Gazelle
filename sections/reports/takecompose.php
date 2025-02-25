<?php
authorize();


if (empty($_POST['toid'])) {
    error(404);
}

if ($Viewer->disablePm() && !isset($StaffIDs[$_POST['toid']])) {
    error(403);
}

$ConvID = false;
if (isset($_POST['convid']) && is_number($_POST['convid'])) {
    $ConvID = $_POST['convid'];
    $Subject = '';
    $ToID = (int)$_POST['toid'];
    $db = Gazelle\DB::DB();
    $db->prepared_query("
        SELECT UserID
        FROM pm_conversations_users
        WHERE UserID = ?
            AND ConvID = ?
        ", $Viewer->id(), $ConvID
    );
    if (!$db->has_results()) {
        error(403);
    }
} else {
    $ToID = (int)$_POST['toid'];
    $Subject = trim($_POST['subject']);
    if (empty($Subject)) {
        $Err = "You can't send a message without a subject.";
    }
}
if (!$ToID) {
    $Err = 'This recipient does not exist.';
}
$Body = trim($_POST['body'] ?? '');
if ($Body === '') {
    $Err = "You can't send a message without a body!";
}

if (!empty($Err)) {
    error($Err);
}

if ($ConvID) {
    (new Gazelle\Manager\User)->replyPM($ToID, $Viewer->id(), $Subject, $Body, $ConvID);
} else {
    (new Gazelle\Manager\User)->sendPM($ToID, $Viewer->id(), $Subject, $Body);
}

header('Location: reports.php');

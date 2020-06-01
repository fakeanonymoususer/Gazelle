<?php
authorize();

// Quick SQL injection check
if (!$_REQUEST['groupid'] || !is_number($_REQUEST['groupid'])) {
    error(404);
}
// End injection check

if (!check_perms('site_edit_wiki')) {
    error(403);
}

// Variables for database input
$UserID = $LoggedUser['ID'];
$GroupID = $_REQUEST['groupid'];

// Get information for the group log
$DB->query("
    SELECT VanityHouse
    FROM torrents_group
    WHERE ID = '$GroupID'");
if (!(list($OldVH) = $DB->next_record())) {
    error(404);
}

if (!empty($_GET['action']) && $_GET['action'] == 'revert') { // if we're reverting to a previous revision
    $RevisionID = $_GET['revisionid'];
    if (!is_number($RevisionID)) {
        error(0);
    }

    // to cite from merge: "Everything is legit, let's just confim they're not retarded"
    if (empty($_GET['confirm'])) {
        View::show_header();
?>
    <div class="center thin">
    <div class="header">
        <h2>Revert Confirm!</h2>
    </div>
    <div class="box pad">
        <form class="confirm_form" name="torrent_group" action="torrents.php" method="get">
            <input type="hidden" name="action" value="revert" />
            <input type="hidden" name="auth" value="<?=$LoggedUser['AuthKey']?>" />
            <input type="hidden" name="confirm" value="true" />
            <input type="hidden" name="groupid" value="<?=$GroupID?>" />
            <input type="hidden" name="revisionid" value="<?=$RevisionID?>" />
            <h3>You are attempting to revert to the revision <a href="torrents.php?id=<?=$GroupID?>&amp;revisionid=<?=$RevisionID?>"><?=$RevisionID?></a>.</h3>
            <input type="submit" value="Confirm" />
        </form>
    </div>
    </div>
<?php
        View::show_footer();
        die();
    }
} else { // with edit, the variables are passed with POST
    $Body = $_POST['body'];
    $Image = $_POST['image'];
    $ReleaseType = (int)$_POST['releasetype'];
    if (check_perms('torrents_edit_vanityhouse')) {
        $VanityHouse = (isset($_POST['vanity_house']) ? 1 : 0);
    } else {
        $VanityHouse = $OldVH;
    }

    if (($GroupInfo = $Cache->get_value('torrents_details_'.$GroupID)) && !isset($GroupInfo[0][0])) {
        $GroupCategoryID = $GroupInfo[0]['CategoryID'];
    } else {
        $DB->query("
            SELECT CategoryID
            FROM torrents_group
            WHERE ID = '$GroupID'");
        list($GroupCategoryID) = $DB->next_record();
    }
    if ($GroupCategoryID == 1 && !isset($ReleaseTypes[$ReleaseType]) || $GroupCategoryID != 1 && $ReleaseType) {
        error(403);
    }

    // Trickery
    if (!preg_match("/^".IMAGE_REGEX."$/i", $Image)) {
        $Image = '';
    }
    ImageTools::blacklisted($Image);
    $Summary = db_string($_POST['summary']);
}

// Insert revision
if (empty($RevisionID)) { // edit
    $DB->prepared_query("
        INSERT INTO wiki_torrents
               (PageID, Body, Image, UserID, Summary)
        VALUES (?,      ?,    ?,     ?,      ?)
        ", $GroupID, $Body, $Image, $UserID, $Summary
    );
    $DB->query("
        UPDATE torrents_group
        SET ReleaseType = '$ReleaseType'
        WHERE ID = '$GroupID'");
    Torrents::update_hash($GroupID);
}
else { // revert
    $DB->query("
        SELECT PageID, Body, Image
        FROM wiki_torrents
        WHERE RevisionID = '$RevisionID'");
    list($PossibleGroupID, $Body, $Image) = $DB->next_record();
    if ($PossibleGroupID != $GroupID) {
        error(404);
    }
    $DB->prepared_query("
        INSERT INTO wiki_torrents
               (PageID, Body, Image, UserID, Summary)
        SELECT  ?,      Body, Image, ?,      ?
        FROM wiki_torrents
        WHERE RevisionID = ?
        ", $GroupID, $UserID, "Reverted to revision $RevisionID",
            $RevisionID
    );
}

$RevisionID = $DB->inserted_id();

$Body = db_string($Body);
$Image = db_string($Image);

// Update torrents table (technically, we don't need the RevisionID column, but we can use it for a join which is nice and fast)
$DB->query("
    UPDATE torrents_group
    SET
        RevisionID = '$RevisionID',
        ".((isset($VanityHouse)) ? "VanityHouse = '$VanityHouse'," : '')."
        WikiBody = '$Body',
        WikiImage = '$Image'
    WHERE ID='$GroupID'");
// Log VH changes
if ($OldVH != $VanityHouse && check_perms('torrents_edit_vanityhouse')) {
    Torrents::write_group_log($GroupID, 0, $LoggedUser['ID'], 'Vanity House status changed to '.($VanityHouse ? 'true' : 'false'), 0);
}

// There we go, all done!

$Cache->delete_value('torrents_details_'.$GroupID);
$DB->prepared_query("
    SELECT concat('collage_', CollageID) as ck
    FROM collages_torrents
    WHERE GroupID = ?
    ", $GroupID
);
$Cache->deleteMulti($DB->collect('ck', false));

//Fix Recent Uploads/Downloads for image change
$DB->prepared_query("
    SELECT DISTINCT concat('user_recent_up_' , UserID) as ck
    FROM torrents AS t
    LEFT JOIN torrents_group AS tg ON (t.GroupID = tg.ID)
    WHERE tg.ID = ?
    ", $GroupID
);
$Cache->deleteMulti($DB->collect('ck', false));

$DB->prepared_query('
    SELECT ID FROM torrents WHERE GroupID = ?
    ', $GroupID
);
if ($DB->has_results()) {
    $IDs = $DB->collect('ID');
    $DB->prepared_query(
        sprintf("
            SELECT DISTINCT concat('user_recent_snatch_', uid) as ck
            FROM xbt_snatched
            WHERE fid IN (%s)
            ", implode(', ', array_fill(0, count($IDs), '?'))
        ), ...$IDs
    );
    $Cache->deleteMulti($DB->collect('ck', false));
}

header("Location: torrents.php?id=$GroupID");

<?php

namespace Gazelle;

class Artist extends BaseObject {
    final const pkName               = 'ArtistID';
    final const tableName            = 'artists_group';
    final const CACHE_REQUEST_ARTIST = 'artists_requests_%d';
    final const CACHE_TGROUP_ARTIST  = 'artists_groups_%d';

    protected const CACHE_PREFIX    = 'artist_%d';

    protected array $artistRole;

    /** All the groups */
    protected array $group = [];

    /** The roles an artist holds in a release */
    protected array $groupRole = [];

    /** Their groups, gathered into sections */
    protected array $section = [];

    protected Stats\Artist $stats;
    protected Artist\Similar $similar;

    public function __construct(
        protected int $id,
        protected int $revisionId = 0
    ) {}

    public function link(): string { return sprintf('<a href="%s">%s</a>', $this->url(), display_str($this->name())); }
    public function location(): string { return 'artist.php?id=' . $this->id; }

    protected function cacheKey(): string {
        return sprintf(self::CACHE_PREFIX, $this->id)
            . ($this->revisionId ? '_r' . $this->revisionId : '');
    }

    public function info(): array {
        $cacheKey = $this->cacheKey();
        $info = self::$cache->get_value($cacheKey);
        if ($info !== false) {
            $this->info = $info;
        } else {
            $sql = "
            SELECT ag.Name           AS name,
                wa.Image             AS image,
                wa.body              AS body,
                ag.VanityHouse       AS showcase,
                dg.artist_discogs_id AS discogs_id,
                dg.name              AS discogs_name,
                dg.stem              AS discogs_stem,
                dg.sequence,
                dg.is_preferred
            FROM artists_group AS ag
            LEFT JOIN artist_discogs AS dg ON (dg.artist_id = ag.ArtistID)
            ";
            if ($this->revisionId) {
                $sql .= "LEFT JOIN wiki_artists AS wa ON (wa.PageID = ag.ArtistID)";
                $cond = 'wa.RevisionID = ?';
                $args = [$this->revisionId];
            } else {
                $sql .= "LEFT JOIN wiki_artists AS wa USING (RevisionID)";
                $cond = 'ag.ArtistID = ?';
                $args = [$this->id];
            }
            $sql .= " WHERE $cond";
            $info = self::$db->rowAssoc($sql, ...$args);

            self::$db->prepared_query("
                SELECT AliasID AS alias_id,
                    Redirect   AS redirect_id,
                    Name       AS name
                FROM artists_alias
                WHERE ArtistID = ?
                ", $this->id
            );
            $info['alias'] = self::$db->to_array('alias_id', MYSQLI_ASSOC, false);

            self::$db->prepared_query("
                SELECT aa.name, aa.artist_attr_id
                FROM artist_attr aa
                INNER JOIN artist_has_attr aha USING (artist_attr_id)
                WHERE aha.artist_id = ?
                ", $this->id
            );
            $info['attr'] = self::$db->to_pair('name', 'artist_attr_id', false);

            $info['homonyms'] = (int)self::$db->scalar('
                SELECT count(*) FROM artist_discogs WHERE stem = ?
                ', $info['discogs_stem']
            );

            self::$cache->cache_value($cacheKey, $info, 3600);
            $this->info = $info;
        }

        // hydrate the Discogs object
        $this->info['discogs'] = new Util\Discogs(
            id:       (int)$this->info['discogs_id'],
            sequence: (int)$this->info['sequence'],
            name:     (string)$this->info['discogs_name'],
            stem:     (string)$this->info['discogs_stem'],
        );
        return $this->info;
    }

    public function loadArtistRole(): static {
        self::$db->prepared_query("
            SELECT ta.GroupID AS group_id,
                ta.Importance as artist_role,
                rt.ID as release_type_id
            FROM torrents_artists AS ta
            INNER JOIN torrents_group AS tg ON (tg.ID = ta.GroupID)
            INNER JOIN release_type AS rt ON (rt.ID = tg.ReleaseType)
            WHERE ta.ArtistID = ?
            ORDER BY tg.Year DESC, tg.Name, rt.ID
            ", $this->id
        );
        $this->artistRole = [
            ARTIST_MAIN => 0,
            ARTIST_GUEST => 0,
            ARTIST_REMIXER => 0,
            ARTIST_COMPOSER => 0,
            ARTIST_CONDUCTOR => 0,
            ARTIST_DJ => 0,
            ARTIST_PRODUCER => 0,
            ARTIST_ARRANGER => 0,
        ];

        while ([$groupId, $role, $releaseTypeId] = self::$db->next_record(MYSQLI_NUM, false)) {
            $role = (int)$role;
            $sectionId = match ($role) {
                ARTIST_ARRANGER => ARTIST_SECTION_ARRANGER,
                ARTIST_PRODUCER => ARTIST_SECTION_PRODUCER,
                ARTIST_COMPOSER => ARTIST_SECTION_COMPOSER,
                ARTIST_REMIXER => ARTIST_SECTION_REMIXER,
                ARTIST_GUEST => ARTIST_SECTION_GUEST,
                default => $releaseTypeId,
            };
            if (!isset($this->section[$sectionId])) {
                $this->section[$sectionId] = [];
            }
            $this->section[$sectionId][$groupId] = true;
            if (!isset($this->groupRole[$groupId])) {
                $this->groupRole[$groupId] = [];
            }
            $this->groupRole[$groupId][] = $role;
            ++$this->artistRole[$role];
        }
        return $this;
    }

    public function flush(): static {
        self::$db->prepared_query("
            SELECT DISTINCT concat('groups_artists_', GroupID)
            FROM torrents_artists
            WHERE ArtistID = ?
            ", $this->id
        );
        self::$cache->delete_multi([
            $this->cacheKey(),
            sprintf(self::CACHE_REQUEST_ARTIST, $this->id),
            sprintf(self::CACHE_TGROUP_ARTIST, $this->id),
            ...self::$db->collect(0, false)
        ]);
        unset($this->info);
        return $this;
    }

    public function artistRole(): array {
        if (!isset($this->artistRole)) {
            $this->loadArtistRole();
        }
        return $this->artistRole;
    }

    public function body(): ?string {
        return $this->info()['body'];
    }

    public function discogs(): Util\Discogs {
        return $this->info()['discogs'];
    }

    public function discogsIsPreferred(): bool {
        return $this->info()['is_preferred'];
    }

    public function groupIds(): array {
        if (!isset($this->groupIds)) {
            $this->loadArtistRole();
        }
        return array_keys($this->groupRole);
    }

    public function group(int $groupId): array {
        if (!isset($this->group)) {
            $this->loadArtistRole();
        }
        return $this->group[$groupId] ?? []; // FIXME
    }

    public function homonymCount(): int {
        return $this->info()['homonyms'];
    }

    public function image(): ?string {
        return $this->info()['image'];
    }

    public function isLocked(): bool {
        return $this->hasAttr('locked');
    }

    public function isShowcase(): bool {
        return $this->info()['showcase'] == 1;
    }

    public function label(): string {
        return "{$this->id} ({$this->name()})";
    }

    public function name(): string {
        return $this->info()['name'];
    }

    public function sections(): array {
        if (!isset($this->section)) {
            $this->loadArtistRole();
        }
        return $this->section;
    }

    public function similar(): Artist\Similar {
        return $this->similar ??= new Artist\Similar($this);
    }

    public function stats(): Stats\Artist {
        if (!isset($this->stats)) {
            $this->stats = new Stats\Artist($this->id);
        }
        return $this->stats;
    }

    public function hasAttr(string $name): bool {
        return isset($this->info()['attr'][$name]);
    }

    public function toggleAttr(string $attr, bool $flag): bool {
        $hasAttr = $this->hasAttr($attr);
        $toggled = false;
        if (!$flag && $hasAttr) {
            self::$db->prepared_query("
                DELETE FROM artist_has_attr
                WHERE artist_id = ?
                    AND artist_attr_id = (SELECT artist_attr_id FROM artist_attr WHERE name = ?)
                ", $this->id, $attr
            );
            $toggled = self::$db->affected_rows() === 1;
        } elseif ($flag && !$hasAttr) {
            self::$db->prepared_query("
                INSERT INTO artist_has_attr (artist_id, artist_attr_id)
                    SELECT ?, artist_attr_id FROM artist_attr WHERE name = ?
                ", $this->id, $attr
            );
            $toggled = self::$db->affected_rows() === 1;
        }
        if ($toggled) {
            $this->flush();
        }
        return $toggled;
    }

    public function createRevision(
        ?string $body,
        ?string $image,
        array   $summary,
        User    $user,
    ): int {
        self::$db->prepared_query("
            INSERT INTO wiki_artists
                   (PageID, Body, Image, UserID, Summary)
            VALUES (?,      ?,    ?,     ?,      ?)
            ", $this->id, $body, $image, $user->id(),
                implode(', ', array_filter($summary, fn($s) => !empty($s)))
        );
        $revisionId = self::$db->inserted_id();
        self::$db->prepared_query("
            UPDATE artists_group SET
                RevisionID = ?
            WHERE ArtistID = ?
            ", $revisionId, $this->id
        );
        $this->flush();
        return $revisionId;
    }

    /**
     * Revert to a prior revision of the artist metadata
     * (Which also creates a new revision).
     */
    public function revertRevision(int $revisionId, \Gazelle\User $user): int {
        self::$db->prepared_query("
            INSERT INTO wiki_artists
                  (Body, Image, PageID, UserID, Summary)
            SELECT Body, Image, ?,      ?,      ?
            FROM wiki_artists
            WHERE RevisionID = ?
            ", $this->id, $user->id(), "Reverted to revision $revisionId",
                $revisionId
        );
        $newRevId = self::$db->inserted_id();
        self::$db->prepared_query("
            UPDATE artists_group SET
                RevisionID = ?
            WHERE ArtistID = ?
            ", $newRevId, $this->id
        );
        $this->flush();
        return $newRevId;
    }

    public function revisionList(): array {
         self::$db->prepared_query("
            SELECT RevisionID AS revision,
                Summary       AS summary,
                Time          AS time,
                UserID        AS user_id
            FROM wiki_artists
            WHERE PageID = ?
            ORDER BY RevisionID DESC
            ", $this->id
        );
        return self::$db->to_array(false, MYSQLI_ASSOC, false);
    }

    public function tagLeaderboard(): array {
        self::$db->prepared_query("
            SELECT t.Name AS name,
                count(*)  AS total
            FROM torrents_artists tga
            INNER JOIN torrents_group tg ON (tg.ID = tga.GroupID)
            INNER JOIN torrents_tags ta USING (GroupID)
            INNER JOIN tags t ON (t.ID = ta.TagID)
            WHERE tg.CategoryID NOT IN (3, 7)
                AND tga.ArtistID = ?
            GROUP BY t.Name
            ORDER By 2 desc, t.Name
            LIMIT 10
            ", $this->id
        );
        return self::$db->to_array(false, MYSQLI_ASSOC, false);
    }

    public function rename(int $aliasId, string $name, Manager\Request $reqMan, User $user): int {
        self::$db->prepared_query("
            INSERT INTO artists_alias
                   (ArtistID, Name, UserID, Redirect)
            VALUES (?,        ?,    ?,      0)
            ", $this->id, $name, $user->id()
        );
        $targetId = self::$db->inserted_id();
        self::$db->prepared_query("
            UPDATE artists_alias SET Redirect = ? WHERE AliasID = ?
            ", $targetId, $aliasId
        );
        self::$db->prepared_query("
            UPDATE artists_group SET Name = ? WHERE ArtistID = ?
            ", $name, $this->id
        );

        // process artists in torrents
        self::$db->prepared_query("
            SELECT GroupID FROM torrents_artists WHERE AliasID = ?
            ", $aliasId
        );
        $groups = self::$db->collect('GroupID');
        self::$db->prepared_query("
            UPDATE IGNORE torrents_artists SET AliasID = ?  WHERE AliasID = ?
            ", $targetId, $aliasId
        );
        $tgroupMan = new Manager\TGroup;
        foreach ($groups as $groupId) {
            $tgroupMan->findById($groupId)?->refresh();
        }

        // process artists in requests
        self::$db->prepared_query("
            SELECT RequestID FROM requests_artists WHERE AliasID = ?
            ", $aliasId
        );
        $requests = self::$db->collect('RequestID');
        self::$db->prepared_query("
            UPDATE IGNORE requests_artists SET AliasID = ? WHERE AliasID = ?
            ", $targetId, $aliasId
        );
        foreach ($requests as $requestId) {
            $reqMan->findById($requestId)->updateSphinx();
        }
        $this->flush();
        return $targetId;
    }

    public function addAlias(string $name, int $redirect, User $user, Log $logger): int {
        self::$db->prepared_query("
            INSERT INTO artists_alias
                   (ArtistID, Name, Redirect, UserID)
            VALUES (?,        ?,    ?,        ?)
            ", $this->id, $name, $redirect, $user->id()
        );
        $aliasId = self::$db->inserted_id();
        $logger->general(
            "The alias $aliasId ($name) was added to the artist {$this->label()} by user {$user->label()}"
        );
        $this->flush();
        return $aliasId;
    }

    public function getAlias($name): int {
        $alias = array_keys(
            array_filter(
                $this->aliasList(),
                fn($a) => (strcasecmp($a['name'], $name) == 0)
            )
        );
        return empty($alias) ? $this->id : current($alias);
    }

    public function clearAliasFromArtist(int $aliasId, User $user, Log $logger): int {
        $alias = $this->aliasList()[$aliasId];
        self::$db->prepared_query("
            UPDATE artists_alias SET
                ArtistID = ?,
                Redirect = 0
            WHERE AliasID = ?
            ", $this->id, $aliasId
        );
        $affected = self::$db->affected_rows();
        if ($affected) {
            $this->flush();
            $logger->general(
                "Redirection from the alias $aliasId ({$alias['name']}) for the artist {$this->label()} was removed by user {$user->label()}"
            );
        }
        return $affected;
    }

    public function removeAlias(int $aliasId, User $user, Log $logger): int {
        self::$db->begin_transaction();
        $alias = $this->aliasList()[$aliasId];
        self::$db->prepared_query("
            DELETE FROM artists_alias WHERE AliasID = ?
            ", $aliasId
        );
        $affected = self::$db->affected_rows();
        if ($affected) {
            $this->flush();
            $logger->general(
                "The alias $aliasId ({$alias['name']}) for the artist {$this->label()}  was removed by user {$user->label()}"
            );
        }
        self::$db->commit();
        return $affected;
    }

    public function aliasList(): array {
        return $this->info()['alias'];
    }

    public function aliasNameList(): array {
        return array_values(array_map(fn($a) => $a['name'], $this->aliasList()));
    }

    public function aliasInfo(): array {
    /**
     * Build the alias info. We want all the non-redirecting aliases at the top
     * level, and gather their aliases together, and having everything sorted
     * alphabetically. This is harder than it seems.
     *  +---------+-----------+------------+
     *  | aliasId | aliasName | redirectId |
     *  +---------+-----------+------------+
     *  |     136 | alpha     |          0 |
     *  |      82 | bravo     |          0 |
     *  |     120 | charlie   |          0 |
     *  |     122 | delta     |          0 |
     *  |     134 | echo      |         82 |
     *  |     135 | foxtrot   |        122 |
     *  |      36 | golf      |        133 |
     *  |     133 | hotel     |        134 |
     *  |     140 | india     |        136 |
     *  +---------+-----------+------------+
     * alpha..delta are non-redirecting aliases. echo is an alias of bravo.
     * golf is an alias of hotel, which is an alias of echo, which is an alias of bravo.
     * This chaining will happen over time as aliases are added and removed and artists
     * are merged or renamed. The golf-hotel-echo-bravo chain is a worst case example of
     * an alias that points to another name that didn't exist when it was created.
     * This means that the chains cannot be resolved in a single pass. I think the
     * algorithm below covers all the edge cases.
     * In the end, the result is:
     *    alpha
     *      - india
     *    bravo
     *      - echo
     *      - golf
     *      - hotel
     *    charlie
     *    delta
     *      - foxtrot
     */
        self::$db->prepared_query("
            SELECT AliasID as aliasId, Name as aliasName, UserID as userId,  Redirect as redirectId
            FROM artists_alias
            WHERE ArtistID = ?
            ORDER BY Redirect, Name
            ", $this->id
        );
        $result = self::$db->to_array('aliasId', MYSQLI_ASSOC, false);

        // create the first level of redirections
        $map = [];
        foreach ($result as $aliasId => $info) {
            $map[$aliasId] = $info['redirectId'];
        }

        // go through the list again, and resolve the redirect chains
        foreach ($result as $aliasId => $info) {
            $redirect = $info['redirectId'];
            while (isset($map[$redirect]) && $map[$redirect] > 0) {
                $redirect = $map[$redirect];
            }
            $map[$aliasId] = $redirect;
        }

        // go through the list and tie the alias to its non-redirecting ancestor
        $userMan = new Manager\User;
        $alias = [];
        foreach ($result as $aliasId => $info) {
            if ($info['redirectId']) {
                $redirect = $map[$aliasId];
                $alias[$redirect]['alias'][] = [
                    'alias_id' => $aliasId,
                    'name'     => $info['aliasName'],
                    'user'     => $userMan->findById($info['userId']),
               ];
            } else {
                $alias[$aliasId] = [
                    'alias'    => [],
                    'alias_id' => $aliasId,
                    'name'     => $info['aliasName'],
                    'user'     => $userMan->findById($info['userId']),
                ];
            }
        }

        // the aliases may need to be sorted
        foreach ($alias as &$a) {
            if ($a['alias']) {
                uksort($a['alias'], fn ($x, $y) => strtolower($a['alias'][$x]['name']) <=> strtolower($a['alias'][$y]['name']));
            }
        }
        return $alias;
    }

    public function requestIdUsage(): array {
        self::$db->prepared_query("
            SELECT r.ID
            FROM requests AS r
            INNER JOIN requests_artists AS ra ON (ra.RequestID = r.ID)
            WHERE ra.ArtistID = ?
            ", $this->id
        );
        return self::$db->collect(0, false);
    }

    public function tgroupIdUsage(): array {
        self::$db->prepared_query("
            SELECT tg.ID
            FROM torrents_group AS tg
            INNER JOIN torrents_artists AS ta ON (ta.GroupID = tg.ID)
            WHERE ta.ArtistID = ?
            ", $this->id
        );
        return self::$db->collect(0, false);
    }

    public function usageTotal(): int {
        return count($this->requestIdUsage()) + count($this->tgroupIdUsage());
    }

    /**
     * Modify an artist. If the body or image fields are edited, or any other
     * change that has to appear in the history, a revision is created.
     * Since a revision requires the user who made the edit to be recorded,
     * the user is passed in as another field to update.
     * The body, image, summary and updater  fields are then cleared so that
     * the BaseObject method can do its job.
     */

    public function modify(): bool {
        // handle the revision of body and image
        $revisionData = [];
        $summary      = [];
        if ($this->field('body') !== null) {
            $body = $this->clearField('body');
            if (is_string($body)) {
                $revisionData['body'] = $body;
                $summary[] = 'description changed (len=' . mb_strlen($body) . ')';
            }
        }
        if ($this->field('image') !== null) {
            $image = $this->clearField('image');
            if (is_string($image)) {
                $revisionData['image'] = $image;
                $summary[] = "image changed to '$image'";
            }
        }
        $notes = $this->clearField('summary');
        if (is_array($notes)) {
            $summary = array_merge($summary, $notes);
        }
        $updated = false;
        if ($revisionData || $summary) {
            $this->setField('RevisionID',
                $this->createRevision(
                    body:    $revisionData['body'] ?? $this->body(),
                    image:   $revisionData['image'] ?? $this->image(),
                    summary: $summary,
                    user:    $this->updateUser,
                )
            );
            $updated = true;
        }

        // handle Discogs
        $discogs = $this->clearField('discogs');
        if ($discogs) {
            $updated = true;
            if ($discogs->sequence() > 0) {
                $this->setDiscogsRelation($discogs);
            } else {
                $this->removeDiscogsRelation();
            }
        }

        $parentUpdated = parent::modify();
        $this->flush();
        return $parentUpdated || $updated;
    }

    /**
     * Sets the Discogs ID for the artist and returns the number of affected rows.
     */
    public function setDiscogsRelation(Util\Discogs $discogs): int {
        // We only run this query when artist_discogs_id has changed, so the collision
        // should only happen on the UNIQUE(artist_id) index
        self::$db->prepared_query("
            INSERT INTO artist_discogs
                   (artist_discogs_id, artist_id, is_preferred, sequence, stem, name, user_id)
            VALUES (?,                 ?,         ?,            ?,        ?,    ?,    ?)
            ON DUPLICATE KEY UPDATE
                artist_discogs_id = VALUES(artist_discogs_id),
                is_preferred      = VALUES(is_preferred),
                sequence          = VALUES(sequence),
                stem              = VALUES(stem),
                name              = VALUES(name),
                user_id           = VALUES(user_id)
            ", $discogs->id(), $this->id, (int)($this->homonymCount() == 0),
            $discogs->sequence(), $discogs->stem(), $discogs->name(), $this->updateUser->id()
        );
        return self::$db->affected_rows();
    }

    public function removeDiscogsRelation(): int {
        self::$db->prepared_query('
            DELETE FROM artist_discogs WHERE artist_id = ?
            ', $this->id
        );
        return self::$db->affected_rows();
    }


    /**
     * Deletes an artist and their wiki and tags.
     * Does NOT delete their requests or torrents.
     */
    public function remove(User $user, Log $logger): int {
        $qid  = self::$db->get_query_id();
        $id   = $this->id;
        $name = $this->name();

        self::$db->begin_transaction();
        self::$db->prepared_query("DELETE FROM artists_alias WHERE ArtistID = ?", $id);
        self::$db->prepared_query("DELETE FROM artists_group WHERE ArtistID = ?", $id);
        self::$db->prepared_query("DELETE FROM artists_tags WHERE ArtistID = ?", $id);
        self::$db->prepared_query("DELETE FROM wiki_artists WHERE PageID = ?", $id);

        (new \Gazelle\Manager\Comment)->remove('artist', $id);
        $logger->general("Artist $id ($name) was deleted by " . $user->username());
        self::$db->commit();

        self::$cache->delete_value('zz_a_' . $id);
        self::$cache->delete_value('artist_' . $id);
        self::$cache->delete_value('artist_groups_' . $id);
        self::$cache->decrement('stats_artist_count');

        self::$db->set_query_id($qid);
        return 1;
    }

    /* STATIC METHODS - for when you do not yet have an ID, e.g. during creation */
    /**
     * Collapse whitespace and directional markers, because people copypaste carelessly.
     * TODO: make stricter, e.g. on all whitespace characters or Unicode normalisation
     */
    public static function sanitize(string $name): ?string {
        // \u200e is &lrm;
        $name = preg_replace('/^(?:\xE2\x80\x8E|\s)+/', '', $name);
        $name = preg_replace('/(?:\xE2\x80\x8E|\s)+$/', '', $name);
        return preg_replace('/ +/', ' ', $name);
    }
}

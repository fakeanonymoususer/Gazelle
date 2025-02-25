<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../../lib/bootstrap.php');
require_once(__DIR__ . '/../helper.php');

class TagTest extends TestCase {
    protected const PREFIX = 'phpunit.';
    protected \Gazelle\User   $user;
    protected \Gazelle\TGroup $tgroup;

    public function tearDown(): void {
        if (isset($this->user)) {
            if (isset($this->tgroup)) {
                Helper::removeTGroup($this->tgroup, $this->user);
            }
            $this->user->remove();
        }
        $db = \Gazelle\DB::DB();
        $db->prepared_query("
            DELETE FROM tag_aliases WHERE BadTag LIKE 'phpunit.%'
        ");
        $db->prepared_query("
            DELETE t, tt, ttv
            FROM tags t
            LEFT JOIN torrents_tags tt ON (tt.TagID = t.ID)
            LEFT JOIN torrents_tags_votes ttv ON (ttv.TagID = t.ID)
            WHERE t.Name like 'phpunit.%'
        ");
    }

    public function testSanitize(): void {
        $manager = new Gazelle\Manager\Tag;
        $this->assertEquals('trim', $manager->sanitize(' trim '), 'tag-sanitize-trim');
        $this->assertEquals('lower', $manager->sanitize('Lower'), 'tag-sanitize-lower');
        $this->assertEquals('heavy.metal', $manager->sanitize('heavy metal'), 'tag-sanitize-internal-space');
        $this->assertEquals('post.rock', $manager->sanitize('post rock'), 'tag-sanitize-internal-space');
        $this->assertEquals('alpha1', $manager->sanitize('a@l,p#_h"a!1'), 'tag-sanitize-alphanum');
        $this->assertEquals('dot.dot', $manager->sanitize('dot...dot'), 'tag-sanitize-double-dot');
    }

    public function testNormalize(): void {
        $manager = new Gazelle\Manager\Tag;
        $this->assertEquals('dub', $manager->normalize('Dub dub  DUB! '), 'tag-normalize-dup');
        $this->assertEquals('neo.folk', $manager->normalize('neo...folk neo-folk'), 'tag-normalize-more');
        $this->assertEquals('pop rock', $manager->normalize(' pop rock rock pop Rock'), 'tag-normalize-two');
    }

    public function testCreate(): void {
        $manager = new Gazelle\Manager\Tag;
        $name    = self::PREFIX . randomString(5);
        $this->assertNull($manager->lookup($name), 'tag-lookup-fail');

        $this->user = Helper::makeUser('tag.' . randomString(6), 'tag');
        $tagId      = $manager->create($name, $this->user);
        $tag        = $manager->findById($tagId);
        $this->assertInstanceOf(\Gazelle\Tag::class, $tag, 'tag-find-by-id');
        $this->assertEquals($tagId, $tag->id(), 'tag-is-id');
        $this->assertEquals($tagId, $manager->lookup($name), 'tag-method-lookup');
        $this->assertEquals($name, $manager->name($tagId), 'tag-method-name');
        $this->assertEquals($name, $manager->findByName($name)->name(), 'tag-find-by-name');
        $this->assertEquals($tagId, $manager->create($name, $this->user), 'tag-create-again');
        $this->assertEquals($tagId, $manager->lookup($name), 'tag-lookup-success');

        // rename
        $new = "$name." . randomString(4);
        $this->assertNull($manager->lookup($new), 'tag-lookup-new-fail');
        $this->assertEquals(1, $manager->rename($tagId, $new, $this->user), 'tag-rename');
        $this->assertEquals($tagId, $manager->lookup($new), 'tag-lookup-new-success');

        // Is empty because vote counts below 10 are ignored,
        // but at least we know the SQL is syntactically valid.
        $this->assertCount(0, $manager->userTopTagList($this->user->id()), 'tag-user-count');
    }

    public function testAlias(): void {
        $manager    = new Gazelle\Manager\Tag;
        $this->user = Helper::makeUser('tag.' . randomString(6), 'tag');

        $badId  = $manager->create(self::PREFIX . randomString(10), $this->user);
        $goodId = $manager->create(self::PREFIX . randomString(10), $this->user);
        $this->assertNotEquals($badId, $goodId, 'tag-just-try-again');
        $bad  = $manager->findById($badId);
        $good = $manager->findById($goodId);
        $aliasId =  $manager->createAlias($bad->name(), $good->name());
        $this->assertEquals($aliasId, $manager->lookupBad($bad->name()), 'tag-lookup-bad');
        $notgood = $good->name() . '.nope';
        $this->assertEquals(1, $manager->modifyAlias($aliasId, $notgood, $good->name()), 'tag-rename-alias');
        $list = array_filter(
            $manager->aliasList(),
            fn($t) => $t['BadTag'] == strtr($notgood, '.', '_'),
        );
        $this->assertCount(1, $list, 'tag-alias-list');
        $this->assertEquals($good->name(), $manager->resolve($notgood), 'tag-resolve-alias');
        $this->assertEquals(1, $manager->removeAlias($aliasId), 'tag-remove-alias');
    }

    public function testOfficial(): void {
        $manager    = new Gazelle\Manager\Tag;
        $this->user = Helper::makeUser('tag.' . randomString(6), 'tag');
        $tagId      = $manager->create(self::PREFIX . randomString(10), $this->user);
        $this->assertEquals($tagId, $manager->officialize($manager->name($tagId), $this->user), 'tag-officalize-existing');
        $tagName = $manager->name($tagId);
        $list = array_filter($manager->genreList(), fn($t) => $t == $tagName);
        $this->assertCount(1, $list, 'tag-genre-list');

        $officialId = $manager->officialize(self::PREFIX . 'off.' . randomString(10), $this->user);
        $this->assertNotEquals($tagId, $officialId, 'tag-officialize-new');
        $officialName = $manager->name($officialId);
        $list = array_filter(
            $manager->officialList(),
            fn($t) => $t['name'] == $officialName
        );
        $this->assertCount(1, $list, 'tag-official-list');
        $this->assertEquals(1, $manager->unofficialize([$officialId]), 'tag-unofficialize');
        $this->assertCount(
            0,
            array_filter($manager->officialList(), fn($t) => $t['name'] == $officialName),
            'tag-empty-official-list'
        );
    }

    public function testTGroup(): void {
        $this->user   = Helper::makeUser('tag.' . randomString(8), 'tag.tgroup');
        $this->tgroup = Helper::makeTGroupMusic(
            name:       'phpunit tag ' . randomString(6),
            artistName: [[ARTIST_MAIN], ['Tag Girl ' . randomString(12)]],
            tagName:    ['phpunit.electronic', 'phpunit.folk', 'phpunit.disco'],
            user:       $this->user,
        );
        Helper::makeTorrentMusic(
            tgroup: $this->tgroup,
            user:   $this->user,
        );

        $manager = new Gazelle\Manager\Tag;
        $folkId  = $manager->lookup('phpunit.folk');
        $this->assertFalse(
            $manager->torrentTagHasVote($folkId, $this->tgroup->id(), $this->user->id()),
            'tag-has-no-vote'
        );
        $result  = $manager->torrentLookup($manager->lookup('phpunit.electronic'));
        $item    = current($result);
        $this->assertCount(1, $result, 'tag-torrent-lookup');
        $this->assertEquals($this->tgroup->id(), $item['torrentGroupId'], 'tag-found-tgroup');
        $this->assertEquals(
            1,
            $manager->createTorrentTagVote(
                $manager->lookup('phpunit.folk'),
                $this->tgroup->id(),
                $this->user->id(),
                'up'
            ),
            'tag-tgroup-vote'
        );
        $db = Gazelle\DB::DB();
        $this->assertTrue(
            $manager->torrentTagHasVote($folkId, $this->tgroup->id(), $this->user->id()),
            'tag-has-no-vote'
        );
        // not enough uses to have a meaninful result, but at least the query is run
        $this->assertCount(0, $manager->autocompleteAsJson('phpun'), 'tag-autocomplete');
        $this->assertCount(0, $manager->requestLookup($folkId), 'tag-request-lookup');
    }

    public function testTag(): void {
        $this->user = Helper::makeUser('tag.' . randomString(8), 'tag.tgroup');
        $manager    = new Gazelle\Manager\Tag;
        $name       = self::PREFIX . randomString(10);
        $tagId      = $manager->create($name, $this->user);
        $tag        = $manager->findById($tagId);
        $this->assertInstanceOf(\Gazelle\Tag::class, $tag, 'tag-instance-find');
        $this->assertEquals($name, $tag->name(), 'tag-instance-name');
        $this->assertEquals(
            "<a href=\"torrents.php?taglist=$name\">$name</a>",
            $tag->link(),
            'tag-instance-link'
        );
        $this->assertEquals("torrents.php?taglist=$name", $tag->location(), 'tag-instance-location');
        $this->assertEquals('other', $tag->type(), 'tag-instance-table-name');
        $manager->officialize($name, $this->user);
        $this->assertEquals('genre', $tag->flush()->type(), 'tag-instance-genre');
        $this->assertEquals(1, $tag->uses(), 'tag-instance-uses');
        $this->assertEquals($this->user->id(), $tag->userId(), 'tag-instance-creator');
    }
}

<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../../lib/bootstrap.php');

class FileStorageTest extends TestCase {
    public function testTorrentPath(): void {
        $filer = new Gazelle\File\Torrent;
        $this->assertEquals(STORAGE_PATH_TORRENT . '/10/00/1.torrent',     $filer->path(1),     'file-torrent-00001');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/01/00/10.torrent',    $filer->path(10),    'file-torrent-00010');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/00/10/100.torrent',   $filer->path(100),   'file-torrent-00100');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/00/01/1000.torrent',  $filer->path(1000),  'file-torrent-01000');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/00/00/10000.torrent', $filer->path(10000), 'file-torrent-10000');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/10/00/10001.torrent', $filer->path(10001), 'file-torrent-10001');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/20/00/10002.torrent', $filer->path(10002), 'file-torrent-10002');

        $this->assertEquals(STORAGE_PATH_TORRENT . '/43/21/1234.torrent',  $filer->path(1234),  'file-torrent-1234');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/53/21/1235.torrent',  $filer->path(1235),  'file-torrent-1235');
        $this->assertEquals(STORAGE_PATH_TORRENT . '/43/21/81234.torrent', $filer->path(81234), 'file-torrent-81234');
    }

    public function testRipLogPath(): void {
        $filer = new Gazelle\File\RipLog;
        $this->assertEquals(STORAGE_PATH_RIPLOG  . '/71/00/17_34.log',     $filer->path([17, 34]), 'file-riplog-17-14');
        $this->assertEquals(STORAGE_PATH_RIPLOG  . '/73/16/16137_707.log', $filer->path([16137, 707]), 'file-riplog-16137-707');
        $this->assertEquals(STORAGE_PATH_RIPLOG  . '/73/16/16137_708.log', $filer->path([16137, 708]), 'file-riplog-16137-708');
    }

    public function testRipLogHTMLPath(): void {
        $filer = new Gazelle\File\RipLogHTML;
        $this->assertEquals(STORAGE_PATH_RIPLOGHTML  . '/72/00/27_44.html',     $filer->path([27, 44]), 'file-html-27-44');
        $this->assertEquals(STORAGE_PATH_RIPLOGHTML  . '/73/16/26137_807.html', $filer->path([26137, 807]), 'file-html-26137-807');
        $this->assertEquals(STORAGE_PATH_RIPLOGHTML  . '/73/16/26137_808.html', $filer->path([26137, 808]), 'file-html-26137-808');
    }

    public function testContestsRipLogHTML(): void {
        $filer = new Gazelle\File\RipLogHTML;
        $id    = [2651337, 306];
        $this->assertFalse($filer->exists($id), 'file-h-not-exists');
        $this->assertFalse($filer->get($id),    'file-h-not-get');
        $this->assertFalse($filer->remove($id), 'file-h-not-remove');

        $text = "<h1>phpunit test " . randomString() . "</h1>";
        $this->assertTrue($filer->put($text, $id), 'file-h-put-ok');

        $this->assertTrue($filer->exists($id),       'file-h-exists-ok');
        $this->assertEquals($text, $filer->get($id), 'file-h-get-ok');
        $this->assertTrue($filer->remove($id),       'file-h-remove-ok');
        $this->assertFalse($filer->get($id),  'file-h-get-after-remove');
    }

    public function testContestsRipLog(): void {
        /**
         * Gazelle\File\RipLog cannot easily be unit-tested, as PHP goes to great
         * lengths to ensure there is no funny business happening during file
         * uploads and there is no easy way to mock it.
         */
        $text  = "This is a phpunit log file";
        $id    = [496801, 31334];
        $filer = new Gazelle\File\RipLog;
        $this->assertNotEquals($text, $filer->get($id), 'file-r-get-nok');
        $this->assertFalse($filer->exists($id),         'file-r-exists-nok');
    }

    public function testContestsTorrent(): void {
        $filer = new Gazelle\File\Torrent;
        $text  = "This is a phpunit torrent file";
        $id    = 906622;
        $this->assertTrue($filer->put($text, $id),   'file-t-put-ok');
        $this->assertEquals($text, $filer->get($id), 'file-t-get-ok');
        $this->assertTrue($filer->remove($id),       'file-t-put-ok');
    }
}

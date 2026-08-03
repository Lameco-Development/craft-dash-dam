<?php

namespace lameco\dash\tests\integration;

use craft\errors\FsException;
use lameco\dash\fs\DashFs;

/**
 * Serving a Dash asset means trusting that its "preview" is the original file. That holds
 * for every image in the library today, but Dash's own docs describe an animated preview
 * for video — so the read path verifies rather than assumes.
 */
final class PreviewIntegrityTest extends IntegrationTestCase
{
    private const DASH_ID = 'aaaa1111-0000-4000-8000-000000000001';
    private const PREVIEW_URL = 'https://fake.dash/preview/aaaa1111';
    private const PATH = 'Beeldbank/Zorg/foto-zorg~aaaa1111.jpg';

    public function testReadServesBytesThatMatchTheChecksum(): void
    {
        $body = FakeDashApi::bodyFor(self::PREVIEW_URL);
        $this->dash->replaceFile(self::DASH_ID, md5($body));
        DashFs::clearCache();

        self::assertSame($body, $this->dashFs()->read(self::PATH));
    }

    public function testReadRefusesBytesThatAreNotTheOriginal(): void
    {
        $this->dash->replaceFile(self::DASH_ID, str_repeat('0', 32));
        DashFs::clearCache();

        $this->expectException(FsException::class);
        $this->expectExceptionMessageMatches('/are not the original/');
        $this->dashFs()->read(self::PATH);
    }

    /**
     * A checksum Dash did not express as an md5 must read as "cannot verify" — failing
     * closed on an unrecognised digest would take every image on the site down.
     */
    public function testReadAcceptsAnUnrecognisedChecksumFormat(): void
    {
        $this->dash->replaceFile(self::DASH_ID, 'sha256:not-an-md5');
        DashFs::clearCache();

        self::assertSame(FakeDashApi::bodyFor(self::PREVIEW_URL), $this->dashFs()->read(self::PATH));
    }

    private function dashFs(): DashFs
    {
        $fs = $this->volume()->getFs();
        self::assertInstanceOf(DashFs::class, $fs);

        return $fs;
    }
}

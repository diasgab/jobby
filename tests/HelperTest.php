<?php

namespace Jobby\Tests;

use Jobby\Exception;
use Jobby\InfoException;
use PHPUnit\Framework\TestCase;
use Countable;
use Swift_Mailer;
use Swift_NullTransport;
use Jobby\Helper;
use Jobby\Jobby;

/**
 * @coversDefaultClass Jobby\Helper
 */
class HelperTest extends TestCase
{
    private Helper $helper;

    private string $tmpDir;

    private string $lockFile;

    private string $copyOfLockFile;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->helper = new Helper();
        $this->tmpDir = $this->helper->getTempDir();
        $this->lockFile = $this->tmpDir . '/test.lock';
        $this->copyOfLockFile = $this->tmpDir . "/test.lock.copy";
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        unset($_SERVER['APPLICATION_ENV']);
    }

    /**
     * @param string $input
     * @param string $expected
     *
     * @dataProvider dataProviderTestEscape
     */
    public function testEscape($input, $expected)
    {
        $actual = $this->helper->escape($input);
        static::assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function dataProviderTestEscape()
    {
        return [
            ['lower', 'lower'],
            ['UPPER', 'upper'],
            ['0123456789', '0123456789'],
            ['with    spaces', 'with_spaces'],
            ['invalid!@#$%^&*()chars', 'invalidchars'],
            ['._-', '._-'],
        ];
    }

    /**
     * @covers ::getPlatform
     */
    public function testGetPlatform()
    {
        $actual = $this->helper->getPlatform();
        static::assertContains($actual, [Helper::UNIX, Helper::WINDOWS]);
    }

    /**
     * @covers ::getPlatform
     */
    public function testPlatformConstants()
    {
        static::assertNotEquals(Helper::UNIX, Helper::WINDOWS);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     * @doesNotPerformAssertions
     */
    public function testAquireAndReleaseLock()
    {
        $this->helper->acquireLock($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     */
    public function testLockFileShouldContainCurrentPid()
    {
        $this->helper->acquireLock($this->lockFile);

        //on Windows, file locking is mandatory not advisory, so you can't do file_get_contents on a locked file
        //therefore, we need to make a copy of the lock file in order to read its contents
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            copy($this->lockFile, $this->copyOfLockFile);
            $lockFile = $this->copyOfLockFile;
        } else {
            $lockFile = $this->lockFile;
        }

        static::assertEquals(getmypid(), file_get_contents($lockFile));

        $this->helper->releaseLock($this->lockFile);
        static::assertEmpty(file_get_contents($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfFileDoesNotExists()
    {
        unlink($this->lockFile);
        static::assertFalse(file_exists($this->lockFile));
        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfFileIsEmpty()
    {
        file_put_contents($this->lockFile, '');
        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfItContainsAInvalidPid()
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            static::markTestSkipped("Test relies on posix_ functions");
        }

        file_put_contents($this->lockFile, 'invalid-pid');
        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testGetLocklifetime()
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            static::markTestSkipped("Test relies on posix_ functions");
        }

        $this->helper->acquireLock($this->lockFile);

        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        static::assertEquals(1, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        static::assertEquals(2, $this->helper->getLockLifetime($this->lockFile));

        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::releaseLock
     */
    public function testReleaseNonExistin()
    {
        $this->expectException(Exception::class);
        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     */
    public function testExceptionIfAquireFails()
    {
        $this->expectException(InfoException::class);
        $fh = fopen($this->lockFile, 'r+');
        static::assertTrue(is_resource($fh));

        $res = flock($fh, LOCK_EX | LOCK_NB);
        static::assertTrue($res);

        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     */
    public function testAquireLockShouldFailOnSecondTry()
    {
        $this->expectException(Exception::class);
        $this->helper->acquireLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::getTempDir
     */
    public function testGetTempDir()
    {
        $valid = [sys_get_temp_dir(), getcwd()];
        foreach (['TMP', 'TEMP', 'TMPDIR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $valid[] = $_SERVER[$key];
            }
        }

        $actual = $this->helper->getTempDir();
        static::assertContains($actual, $valid);
    }

    /**
     * @covers ::getApplicationEnv
     */
    public function testGetApplicationEnv()
    {
        $_SERVER['APPLICATION_ENV'] = 'foo';

        $actual = $this->helper->getApplicationEnv();
        static::assertEquals('foo', $actual);
    }

    /**
     * @covers ::getApplicationEnv
     */
    public function testGetApplicationEnvShouldBeNullIfUndefined()
    {
        $actual = $this->helper->getApplicationEnv();
        static::assertNull($actual);
    }

    /**
     * @covers ::getHost
     */
    public function testGetHostname()
    {
        $actual = $this->helper->getHost();
        static::assertContains($actual, [gethostname(), php_uname('n')]);
    }

    /**
     * @covers ::sendMail
     * @covers ::getCurrentMailer
     */
    public function testSendMail()
    {
        $mailer = $this->getSwiftMailerMock();
        $mailer->expects(static::once())
            ->method('send')
        ;

        $jobby = new Jobby();
        $config = $jobby->getDefaultConfig();
        $config['output'] = 'output message';
        $config['recipients'] = 'a@a.com,b@b.com';

        $helper = new Helper($mailer);
        $mail = $helper->sendMail('job', $config, 'message');

        $host = $helper->getHost();
        $email = "jobby@$host";
        static::assertContains('job', $mail->getSubject());
        static::assertContains("[$host]", $mail->getSubject());
        static::assertEquals(1, is_countable($mail->getFrom()) ? count($mail->getFrom()) : 0);
        static::assertEquals('jobby', current($mail->getFrom()));
        static::assertEquals($email, current(array_keys($mail->getFrom())));
        static::assertEquals($email, current(array_keys($mail->getSender())));
        static::assertContains($config['output'], $mail->getBody());
        static::assertContains('message', $mail->getBody());
    }

    /**
     * @return Swift_Mailer
     */
    private function getSwiftMailerMock()
    {
        return $this->getMockBuilder('Swift_Mailer')
            ->setConstructorArgs([new Swift_NullTransport()])
            ->getMock();
    }

    /**
     * @return void
     */
    public function testItReturnsTheCorrectNullSystemDeviceForUnix()
    {
        /** @var Helper $helper */
        $helper = $this->createPartialMock('\\' . Helper::class, ["getPlatform"]);
        $helper->expects(static::once())
            ->method("getPlatform")
            ->willReturn(Helper::UNIX);

        static::assertEquals("/dev/null", $helper->getSystemNullDevice());
    }

    /**
     * @return void
     */
    public function testItReturnsTheCorrectNullSystemDeviceForWindows()
    {
        /** @var Helper $helper */
        $helper = $this->createPartialMock('\\' . Helper::class, ["getPlatform"]);
        $helper->expects(static::once())
               ->method("getPlatform")
               ->willReturn(Helper::WINDOWS);

        static::assertEquals("NUL", $helper->getSystemNullDevice());
    }
}

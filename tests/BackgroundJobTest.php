<?php

namespace Jobby\Tests;

use PHPUnit\Framework\TestCase;
use Jobby\BackgroundJob;
use Jobby\Helper;
use Opis\Closure\SerializableClosure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @coversDefaultClass Jobby\BackgroundJob
 */
class BackgroundJobTest extends TestCase
{
    public const JOB_NAME = 'name';

    /**
     * @var string
     */
    private $logFile;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->logFile = __DIR__ . '/_files/BackgroundJobTest.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->helper = new Helper();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function runProvider()
    {
        $echo = function () {
            echo 'test';

            return true;
        };
        $uid = function () {
            echo getmyuid();

            return true;
        };
        $job = ['closure' => $echo];

        return [
            'diabled, not run'       => [$job + ['enabled' => false], ''],
            'normal job, run'         => [$job, 'test'],
            'wrong host, not run'    => [$job + ['runOnHost' => 'something that does not match'], ''],
            'current user, run,'     => [['closure' => $uid], getmyuid()],
        ];
    }

    /**
     * @covers ::getConfig
     */
    public function testGetConfig()
    {
        $job = new BackgroundJob('test job',[]);
        static::assertInternalType('array', $job->getConfig());
    }

    /**
     * @dataProvider runProvider
     *
     * @covers ::run
     *
     * @param array  $config
     * @param string $expectedOutput
     */
    public function testRun($config, $expectedOutput)
    {
        $this->runJob($config);

        static::assertEquals($expectedOutput, $this->getLogContent());
    }

    /**
     * @covers ::runFile
     */
    public function testInvalidCommand()
    {
        $this->runJob(['command' => 'invalid-command']);

        static::assertContains('invalid-command', $this->getLogContent());

        if ($this->helper->getPlatform() === Helper::UNIX) {
            static::assertContains('not found', $this->getLogContent());
            static::assertContains("ERROR: Job exited with status '127'", $this->getLogContent());
        } else {
            static::assertContains('not recognized as an internal or external command', $this->getLogContent());
        }
    }

    /**
     * @covers ::runFunction
     */
    public function testClosureNotReturnTrue()
    {
        $this->runJob(
            [
                'closure' => function () {
                    return false;
                },
            ]
        );

        static::assertContains('ERROR: Closure did not return true! Returned:', $this->getLogContent());
    }

    /**
     * @covers ::getLogFile
     */
    public function testHideStdOutByDefault()
    {
        ob_start();
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => null,
            ]
        );
        $content = ob_get_contents();
        ob_end_clean();

        static::assertEmpty($content);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldCreateLogFolder()
    {
        $logfile = dirname($this->logFile) . '/foo/bar.log';
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => $logfile,
            ]
        );

        $dirExists = file_exists(dirname($logfile));
        $isDir = is_dir(dirname($logfile));

        unlink($logfile);
        rmdir(dirname($logfile));

        static::assertTrue($dirExists);
        static::assertTrue($isDir);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldSplitStderrAndStdout()
    {
        $dirname = dirname($this->logFile);
        $stdout = $dirname . '/stdout.log';
        $stderr = $dirname . '/stderr.log';
        $this->runJob(
            [
                'command' => "(echo \"stdout output\" && (>&2 echo \"stderr output\"))",
                'output_stdout' => $stdout,
                'output_stderr' => $stderr,
            ]
        );

        static::assertContains('stdout output', @file_get_contents($stdout));
        static::assertContains('stderr output', @file_get_contents($stderr));

        unlink($stderr);
        unlink($stdout);

    }

    /**
     * @covers ::mail
     */
    public function testNotSendMailOnMissingRecipients()
    {
        $helper = $this->createMock(Helper::class);
        $helper->expects(static::never())
            ->method('sendMail')
        ;

        $this->runJob(
            [
                'closure'    => function () {
                    return false;
                },
                'recipients' => '',
            ],
            $helper
        );
    }

    /**
     * @covers ::mail
     */
    public function testMailShouldTriggerHelper()
    {
        $helper = $this->createPartialMock(Helper::class, ['sendMail']);
        $helper->expects(static::once())
            ->method('sendMail')
        ;

        $this->runJob(
            [
                'closure'    => function () {
                    return false;
                },
                'recipients' => 'test@example.com',
            ],
            $helper
        );
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntime()
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            static::markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createPartialMock(Helper::class, ['getLockLifetime']);
        $helper->expects(static::once())
            ->method('getLockLifetime')
            ->will(static::returnValue(0))
        ;

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        static::assertEmpty($this->getLogContent());
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntimeShouldFailIsExceeded()
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            static::markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createPartialMock(Helper::class, ['getLockLifetime']);
        $helper->expects(static::once())
            ->method('getLockLifetime')
            ->will(static::returnValue(2))
        ;

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        static::assertContains('MaxRuntime of 1 secs exceeded! Current runtime: 2 secs', $this->getLogContent());
    }

    /**
     * @dataProvider haltDirProvider
     * @covers       ::shouldRun
     *
     * @param bool $createFile
     * @param bool $jobRuns
     */
    public function testHaltDir($createFile, $jobRuns)
    {
        $dir = __DIR__ . '/_files';
        $file = $dir . '/' . static::JOB_NAME;

        $fs = new Filesystem();

        if ($createFile) {
            $fs->touch($file);
        }

        $this->runJob(
            [
                'haltDir' => $dir,
                'closure' => function () {
                    echo 'test';

                    return true;
                },
            ]
        );

        if ($createFile) {
            $fs->remove($file);
        }

        $content = $this->getLogContent();
        static::assertEquals($jobRuns, is_string($content) && !empty($content));
    }

    public function haltDirProvider()
    {
        return [
            [true, false],
            [false, true],
        ];
    }

    /**
     * @param array  $config
     * @param Helper $helper
     */
    private function runJob(array $config, Helper $helper = null)
    {
        $config = $this->getJobConfig($config);

        $job = new BackgroundJob(self::JOB_NAME, $config, $helper);
        $job->run();
    }

    /**
     * @param array $config
     *
     * @return array
     */
    private function getJobConfig(array $config)
    {
        $helper = new Helper();

        if (isset($config['closure'])) {
            $wrapper = new SerializableClosure($config['closure']);
            $config['closure'] = serialize($wrapper);
        }

        return array_merge(
            [
                'enabled'    => 1,
                'haltDir'    => null,
                'runOnHost'  => $helper->getHost(),
                'dateFormat' => 'Y-m-d H:i:s',
                'schedule'   => '* * * * *',
                'output'     => $this->logFile,
                'maxRuntime' => null,
                'runAs'      => null,
            ],
            $config
        );
    }

    /**
     * @return string
     */
    private function getLogContent()
    {
        return @file_get_contents($this->logFile);
    }
}

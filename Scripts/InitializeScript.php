<?php

declare(strict_types=1);

namespace Ochorocho\Tdk\Scripts;

use Composer\Script\Event;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class InitializeScript extends BaseScript
{
    public static function enableHooks(Event $event)
    {
        $questions = [
            [
                'method' => 'enableCommitMessageHook',
                'message' => 'Setup Commit Message Hook? [<fg=cyan;options=bold>y</>/n] ',
                'default' => true
            ],
            [
                'method' => 'enablePreCommitHook',
                'message' => 'Setup Pre Commit Hook? [<fg=cyan;options=bold>y</>/n] ',
                'default' => true
            ],
        ];

        $force = (bool)(GitScript::getArguments($event->getArguments())['force'] ?? getenv('TDK_HOOK_FORCE_CREATE') ?? false);
        foreach ($questions as $question) {
            if ($force) {
                $answer = true;
            } else {
                $answer = $event->getIO()->askConfirmation($question['message'], $question['default']);
            }

            if ($answer) {
                $method = $question['method'];
                static::$method($event);
            }
        }
    }

    public static function removeHooks(Event $event)
    {
        $filesystem = new Filesystem();
        $filesystem->remove([
            self::$coreDevFolder . '/.git/hooks/pre-commit',
            self::$coreDevFolder . '/.git/hooks/commit-msg',
        ]);
    }

    private static function enableCommitMessageHook(Event $event)
    {
        $filesystem = new Filesystem();

        try {
            $targetCommitMsg = self::$coreDevFolder . '/.git/hooks/commit-msg';
            $filesystem->copy(self::$coreDevFolder . '/Build/git-hooks/commit-msg', $targetCommitMsg);

            if (!is_executable($targetCommitMsg)) {
                $filesystem->chmod($targetCommitMsg, 0755);
            }

            $event->getIO()->write('<info>Created Commit Message Hook</info>');
        } catch (IOException $e) {
            $event->getIO()->writeError('<warning>Exception:enableCommitMessageHook:' . $e->getMessage() . '</warning>');
        }
    }

    private static function enablePreCommitHook(Event $event)
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }
        $filesystem = new Filesystem();
        try {
            $targetPreCommit = self::$coreDevFolder . '/.git/hooks/pre-commit';
            $filesystem->copy(self::$coreDevFolder . '/Build/git-hooks/unix+mac/pre-commit', $targetPreCommit);

            if (!is_executable($targetPreCommit)) {
                $filesystem->chmod($targetPreCommit, 0755);
            }

            $event->getIO()->write('<info>Created Pre Commit Hook</info>');
        } catch (IOException $e) {
            $event->getIO()->writeError('<warning>Exception:enablePreCommitHook:' . $e->getMessage() . '</warning>');
        }
    }

    public static function createDdevConfig(Event $event)
    {
        // Only ask for ddev config if ddev command is available
        $windows = strpos(PHP_OS, 'WIN') === 0;
        $test = $windows ? 'where' : 'command -v';

        if (is_executable(trim(shell_exec($test . ' ddev') ?? ''))) {
            $ddevProjectName = GitScript::getArguments($event->getArguments())['project-name'] ?? getenv('TDK_CREATE_DDEV_PROJECT_NAME') ?? false;
            if (!$ddevProjectName) {
                $createConfig = $event->getIO()->askConfirmation('Create a basic ddev config [<fg=cyan;options=bold>y</>/n] ?', true);
                if (!$createConfig) {
                    $event->getIO()->write('<warning>Aborted! No ddev config created.</warning>');
                    return 0;
                }
            }

            $validator = self::validateDdevProjectName();

            if (!$ddevProjectName) {
                $defaultProjectName = basename(getcwd());
                $ddevProjectName = $event->getIO()->askAndValidate('Choose a ddev project name [default: ' . $defaultProjectName . '] :', $validator, 2, $defaultProjectName);
            } else {
                try {
                    $ddevProjectName = $validator($ddevProjectName);
                } catch (\UnexpectedValueException $e) {
                    $event->getIO()->write('<error>' . $e->getMessage() . '</error>');
                    return 1;
                }
            }

            if ($fileContent = file_get_contents(self::$coreDevFolder . '/composer.json')) {
                $json = json_decode($fileContent, true, 512, JSON_THROW_ON_ERROR);
                preg_match_all('/[0-9].[0-9]/', $json['require']['php'], $versions);
                $phpVersion = $versions[0][0];
            } else {
                $phpVersion = '8.1';
            }

            $ddevCommand = 'ddev config --docroot public --project-name ' . $ddevProjectName . ' --web-environment-add TYPO3_CONTEXT=Development --project-type typo3 --php-version ' . $phpVersion . ' --create-docroot 1> /dev/null';
            exec($ddevCommand, $output, $statusCode);

            return $statusCode;
        }

        return 0;
    }

    public static function removeFilesAndFolders(Event $event): void
    {
        $filesToDelete = [
            'composer.lock',
            'public/index.php',
            'public/typo3',
            self::$coreDevFolder,
            'var',
        ];

        $force = GitScript::getArguments($event->getArguments())['force'] ?? false;

        if ($force) {
            $answer = true;
        } else {
            $answer = $event->getIO()->askConfirmation('Really want to delete ' . implode(', ', $filesToDelete) . '? [y/<fg=cyan;options=bold>n</>] ', false);
        }

        if ($answer) {
            $filesystem = new Filesystem();
            $filesystem->remove($filesToDelete);
            $event->getIO()->write('<info>Done deleting files.</info>');
        }
    }

    public static function showSummary(Event $event): void
    {
        $coreFolder = self::$coreDevFolder;
        $summary = <<<EOF

💡For more Details read the docs:
* Setting up Gerrit (ssh):
  https://docs.typo3.org/m/typo3/guide-contributionworkflow/master/en-us/Account/GerritAccount.html
* Git Setup:
  https://docs.typo3.org/m/typo3/guide-contributionworkflow/master/en-us/Setup/Git/Index.html
* Setup your IDE:
  https://docs.typo3.org/m/typo3/guide-contributionworkflow/master/en-us/Setup/SetupIde.html
* runTests.sh docs still apply, but don't forget to cd into '$coreFolder':
  https://docs.typo3.org/m/typo3/guide-contributionworkflow/master/en-us/Testing/Index.html

<fg=yellow;options=bold>To be able to push to Gerrit, you need to add your public key, see https://review.typo3.org/settings/#SSHKeys</>
EOF;

        $event->getIO()->write($summary);
    }

    public static function done(Event $event): void
    {
        $event->getIO()->write('<info>🎉 Happy days ... TYPO3 Composer CoreDev Setup done!</info>');
    }

    public static function doctor(Event $event): void
    {
        $filesystem = new Filesystem();

        // Test for existing repository
        if ($filesystem->exists(self::$coreDevFolder . '/.git')) {
            $event->getIO()->write('<fg=green;options=bold>✔</> Repository exists.');
        } else {
            $event->getIO()->write('<fg=red;options=bold>✘</> TYPO3 Repository not in place, please run "composer tdk:clone"');
        }

        // Test if hooks are set up
        if ($filesystem->exists([
            self::$coreDevFolder . '/.git/hooks/pre-commit',
            self::$coreDevFolder . '/.git/hooks/commit-msg',
        ])) {
            $event->getIO()->write('<fg=green;options=bold>✔</> All hooks are in place.');
        } else {
            $event->getIO()->write('<fg=red;options=bold>✘</> Hooks are missing please run "composer tdk:enable-hooks".');
        }

        // Test git push url
        $process = new ProcessExecutor();
        $command = 'git config --get remote.origin.pushurl';
        $process->execute($command, $output, self::$coreDevFolder);

        preg_match('/^ssh:\/\/(.*)@review\.typo3\.org/', $output, $matches);
        if (!empty($matches)) {
            $event->getIO()->write('<fg=green;options=bold>✔</> Git "remote.origin.pushurl" seems correct.');
        } else {
            $event->getIO()->write('<fg=red;options=bold>✘</> Git "remote.origin.pushurl" not set correctly, please run "composer tdk:set-git-config".');
        }

        // Test commit template
        $commandTemplate = 'git config --get commit.template';
        $process->execute($commandTemplate, $outputTemplate, self::$coreDevFolder);

        if (!empty($outputTemplate) && $filesystem->exists(trim($outputTemplate))) {
            $event->getIO()->write('<fg=green;options=bold>✔</> Git "commit.template" is set to ' . trim($outputTemplate) . '.');
        } else {
            $event->getIO()->write('<fg=red;options=bold>✘</> Git "commit.template" not set or file does not exist, please run "composer tdk:set-commit-template"');
        }

        // Test vendor folder
        if ($filesystem->exists('vendor')) {
            $event->getIO()->write('<fg=green;options=bold>✔</> Vendor folder exists.');
        } else {
            $event->getIO()->write('<fg=red;options=bold>✘</> Vendor folder is missing, please run "composer install"');
        }
    }
}

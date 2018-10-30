<?php

namespace DrupalKit\Docksal\Command;

use DrupalKit\Docksal\Util\DrushCommand;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Sync class.
 *
 */
class Sync extends DrushCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setName('sync')
      ->setDescription('Drupal Synchronization System.')
      ->addOption('confirm', 'y', InputOption::VALUE_NONE, 'Auto confirm actions.')
      ->addOption('site', 's', InputOption::VALUE_OPTIONAL, 'The site to sync.')
      ->addOption('environment_from', 'ef', InputOption::VALUE_OPTIONAL, 'The environment to import from.')
      ->addOption('environment_as', 'ea', InputOption::VALUE_OPTIONAL, 'The environment to import as.', 'local')
      ->addOption('skip-dump', 'sd', InputOption::VALUE_NONE, 'Skip MySQL dump file creation.')
      ->addOption('skip-dump-recent', 'sdr', InputOption::VALUE_NONE, 'Skip MySQL dump file creation if file is recent.')
      ->addOption('skip-import', 'si', InputOption::VALUE_NONE, 'Skip database import.')
      ->addOption('skip-files', 'sf', InputOption::VALUE_NONE, 'Skip file rsync.')
      ->addOption('skip-composer', 'sc', InputOption::VALUE_NONE, 'Skip composer dependency install.')
      ->addOption('skip-reset', 'sr', InputOption::VALUE_NONE, 'Skip Drupal reset.')
      ->addOption('dump-dir', 'dd', InputOption::VALUE_REQUIRED, 'Specify dump directory.');

    $this->title = "Environment Synchronization";
  }

  /**
   * {@inheritdoc}
   */
  protected function exec(InputInterface $input, OutputInterface $output) {

    $defaults = [
      'site' => 'www',
      'environment_from' => 'remote_stage',
      'environment_as' => 'local'
    ];


    // Get site.
    $site = $input->getOption('site');
    $site_options = $this->getSites();
    if (!$site || !in_array($site, $site_options)) {
      $site = $this->message->choice("Please select the source site", $site_options, 'www');
    }
    $this->message->success('Site: ' . $site);

    // Get source environment,
    $environment_from = $input->getOption('environment_from');
    $environment_options = $this->getSiteEnvironments($site);
    if (!$environment_from || !in_array($environment_from, $environment_options)) {
      $environment_from = $this->message->choice("Please select the source environment", $environment_options, 'remote_stage');
    }
    $this->message->success('Environment from: ' . $environment_from);

    // Get reset environment.
    $environment_as = $input->getOption('environment_as');
    $this->message->success('Environment as: ' . $environment_as);
    if (!$environment_as || !in_array($environment_as, $environment_options)) {
      $environment_from = $this->message->choice("Please select the environment to import as", $environment_options, 'local');
    }

    // Validate aliases.
    $alias = $this->findDrushAlias("{$site}.{$environment_from}");
    $local_alias = $this->findDrushAlias("{$site}.local");

    // Confirm that the user wants to continue.
    $confirm = $input->getOption('confirm');
    if (!$confirm) {
      if (!$this->message->confirm('Confirm overwrite of database?')) {
        return;
      }
    }

    // Run composer install.
    if (!$input->getOption('skip-composer')) {
      $this->message->step_header('Composer Install');
      chdir($_SERVER['PROJECT_ROOT']);
      $this->message->step_status('Installing Dependencies');
      list($cmd, $res, $out) = $this->runner('composer install --prefer-dist -v -o 2>&1', $output->isVerbose());
      $this->message->step_finish();
    }

    // Run database functions.
    $dump_directory = $input->getOption('dump-dir') ? : '/tmp';
    if ($dump_directory === '/tmp') {
      $dump_file = '/tmp/' . $site . '.' . $environment_from . '.sql';
    }
    else {
      $dump_file = $_SERVER['PROJECT_ROOT'] . '/' . $dump_directory . '/' . $site . '.' . $environment_from . '.sql';
    }

    // Dump Database.
    if (!$input->getOption('skip-dump')) {
      chdir($_SERVER['PROJECT_ROOT']);

      $this->message->step_header('Database Dump');

      // Create directory if it doesn't exist.
      $this->message->step_status('Validating dump directory');
      if (!file_exists($dump_directory)) {
        mkdir($dump_directory);
      }

      // Dump file if it doesn't exist of old dump is old.
      if (!file_exists($dump_file) || !$input->getOption('skip-dump-recent') || $input->getOption('skip-dump-recent') && ((time() - filemtime($dump_file)) > 60)) {
        $this->message->step_status('Clearing Remote Cache');
        $this->runDrush('cr  2>&1', $alias, NULL, $output->isVerbose());

        $this->message->step_status('Dumping Remote Database');
        $this->runDrush('sql:dump --ssh-options="-o PasswordAuthentication=no -o LogLevel=QUIET" --skip-tables-key=common --skip-tables-list=cache,cache_* > ' . $dump_file, $alias);

        if ($input->hasOption('dump-convert') && $input->getOption('dump-convert')) {
          $this->message->step_status('Converting to MyISAM Format');
          $this->runner('sed -i.bak \'s/InnoDB/MyISAM/g\' ' . $dump_file);
        }

        $this->message->step_status('Finishing');
        $this->message->step_finish();

        chdir($_SERVER['PROJECT_ROOT']);
      }
      else {
        $this->message->step_status('Skipping');
        $this->message->step_finish();
      }
    }

    // Import database.
    if (!$input->getOption('skip-import')) {
      chdir($_SERVER['PROJECT_ROOT']);

      $this->message->step_header('Database Import');

      if (file_exists($dump_file)) {
        $this->message->step_status('Dropping Local Database');
        $this->runDrush('sql-drop -y', $local_alias, NULL, $output->isVerbose());

        $this->message->step_status('Importing Database from File');
        // Add prefix to compensate for drush root if the path is not absolute.
        $this->runDrush('sql-cli < ' . $dump_file, $local_alias, NULL, $output->isVerbose());

        $this->message->step_finish();
        chdir($_SERVER['PROJECT_ROOT']);
      }
      else {
        $this->message->step_status('Skipping');
        $this->message->step_finish();
      }
    }

    // Sync files from source environment.
    if (!$input->getOption('skip-files') && FALSE) {
      $this->message->step_header('File Import');
      chdir($_SERVER['PROJECT_ROOT'] . '/' . $_SERVER['DOCROOT']);

      $this->message->step_status('Syncing files');
      $this->runDrush('-y --exclude-paths=files/styles:files/js:files/css rsync --progress --delete -v :%files sites/default', $local_alias, NULL, $output->isVerbose());

      $this->message->step_finish();
      chdir($_SERVER['PROJECT_ROOT']);
    }

    // Run environment reset.
    if (!$input->getOption('skip-reset')) {
      $this->message->step_header('Drupal Reset');

      chdir($_SERVER['PROJECT_ROOT']);
      $reset_config = Yaml::parse(file_get_contents('.docksal/configuration.reset.yml'));
      chdir($_SERVER['PROJECT_ROOT'] . '/' . $_SERVER['DOCROOT']);

      // Go through each available command.
      foreach ($reset_config['commands'] as $command) {
        // Condition Check
        // @todo where is this information is coming from. Drush? What's there? Is this
        if (array_key_exists('condition', $command) && $command['condition'] != '') {
          $condition = explode('.', $command['condition']);
          switch ($condition[0]) {
            case 'site':
              break;
            case 'environment':
              break;
          }
        }

        // Let the user know which command is running.
        if (!array_key_exists('name', $command) || empty($command['name'])) {
          $command['name'] = 'Missing command name';
        }
        $this->message->step_status($command['name']);

        // Run drush command if not empty.
        if (array_key_exists('drush', $command) && $command['drush'] !== '') {
          // Token Replacement.
          $environment_id = $environment_as;
          $find = ['%ah_environment_id%', '%environment_id%', '%site_id%'];
          $replace = [$environment_id, $environment_id, $site];
          $command['run'] = str_replace($find, $replace, $command['run']);
          list($cmd, $res, $out) = $this->runDrush($command['drush'] . ' 2>&1', $local_alias, $environment_as, $output->isVerbose());
        }
        // Or run another command if not empty.
        elseif (array_key_exists('run', $command) && $command['run'] !== '') {
          // Token Replacement.
          $environment_id = $environment_as;
          $site_uri = $this->getDrushAliasData($local_alias, 'uri');

          $find = ['%ah_environment_id%', '%environment_id%', '%site_id%', '%site_uri%', '%drush_alias%'];
          $replace = [$environment_id, $environment_id, $site, $site_uri, $local_alias];
          $command['run'] = str_replace($find, $replace, $command['run']);

          list($cmd, $res, $out) = $this->runner($command['run'] . ' 2>&1', $output->isVerbose());
        }
        // Otherwise skip.
        else {
          $res = 0;
        }

        if ($res > 0) {
          $this->message->error($out);
          $this->message->step_finish();
          exit(1);
        }
      }

      $this->message->step_finish();
      chdir($_SERVER['PROJECT_ROOT']);
    }

  }
}

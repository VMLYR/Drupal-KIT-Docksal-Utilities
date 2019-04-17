<?php

namespace DrupalKit\Docksal\Command;

use Drupal\Component\Utility\Html;
use DrupalKit\Docksal\Util\DocksalCommand;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InitTheme extends DocksalCommand {

  protected function themes() {
    return [
      'skeleto' => [
        'title' => 'Skeleto',
        'description' => 'A bare-bones scaffolding theme.',
        'theme_repo' => 'https://github.com/VML/Drupal-Theme-Skeleto.git',
        'theme_repo_branch' => 'master',
        'source_repo' => 'https://github.com/VML/Drupal-Theme-Source-Skeleto.git',
        'source_repo_branch' => 'master'
      ],
      'denim' => [
        'title' => 'Denim',
        'description' => 'A feature-filled scaffolding theme that works well with the Kastoro profile.',
        'theme_repo' => 'https://github.com/VML/Drupal-Theme-Denim.git',
        'theme_repo_branch' => 'master',
        'source_repo' => 'https://github.com/VML/Drupal-Theme-Source-Denim.git',
        'source_repo_branch' => 'master'
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setName('init-theme')
      ->setDescription('Theme initialization from scaffold themes.');

    $this->title = 'Theme Scaffolder';
  }

  /**
   * {@inheritdoc}
   */
  protected function exec(InputInterface $input, OutputInterface $output) {
    $this->message->setOverwrite(FALSE);

    // Get config.
    $repo_config = $this->themes();

    // Get requested theme to scaffold from.
    $theme_options = [];
    foreach ($repo_config as $id => $theme_option) {
      $theme_options[$id] = "{$theme_option['title']}: {$theme_option['description']}";
    }
    $theme_option_key = $this->message->choice('Which theme be scaffolded?', $theme_options);

    // Requested name.
    $name_requested = $this->message->ask('What should the new theme be called?');
    $name_cleaned = Html::cleanCssIdentifier(strtolower($name_requested), [
      ' ' => '_',
      '_' => '_',
      '/' => '_',
      '[' => '_',
      ']' => '_',
    ]);

    // Set up variables.
    $dir_source = "{$_ENV['PROJECT_ROOT']}/source/custom";
    $dir_themes = "{$_ENV['PROJECT_ROOT']}/{$_ENV['DOCROOT']}/themes/custom";

    $this->message->step_header('Validating directories');

    // Create custom theme folder if it doesn't exist.
    $this->message->step_status('Drupal themes custom');
    if (!is_dir($dir_themes)) {
      mkdir($dir_themes, 0755, TRUE);
    }
    $this->message->step_finish();

    // Validate destination theme directory doesn't already exist.
    $this->message->step_status('Requested theme');
    chdir($dir_themes);
    if (is_dir($name_cleaned)) {
      $this->message->error("{$name_cleaned} theme already exists in themes/custom");
      return;
    }
    $this->message->step_finish();

    // Create themes directory if it doesn't exist.
    $this->message->step_status('Source themes');
    if (!is_dir($dir_source)) {
      mkdir($dir_source, 0755, TRUE);
    }
    $this->message->step_finish();

    // Exit early if the directory already exists.
    $this->message->step_status('Requested source');
    chdir($dir_source);
    if (is_dir($name_cleaned)) {
      $this->message->error("{$name_cleaned} source directory already exists");
      return;
    }
    $this->message->step_finish();

    // Create theme.
    $this->message->step_header('Creating theme');
    chdir($dir_themes);

    // Get theme relevant variables.
    $repo_address = $repo_config[$theme_option_key]['theme_repo'];
    $repo_branch = $repo_config[$theme_option_key]['theme_repo_branch'];

    // Clone repository.
    $this->message->step_status('Cloning theme');
    $this->runner("git clone --depth=1 --branch={$repo_branch} {$repo_address} {$name_cleaned}");
    chdir($name_cleaned);
    $this->message->step_finish();

    // Clean and rename old name to new.
    $this->message->step_status('Cleaning and renaming theme');
    $this->runner('rm -rf .git');
    $this->runner('rm -rf .gitignore');
    $this->runner('rm -rf README.md');
    $this->runner("find . -type f -exec sed -i 's/{$theme_option_key}/{$name_cleaned}/g' {} +");
    $this->runner("rename 's/{$theme_option_key}/{$name_cleaned}/g' *");
    // @todo add in renaming from 'Chowder' to new theme name.
    $this->message->step_finish();

    // Create theme source.
    $this->message->step_header('Creating source');
    chdir($dir_source);
    $source_address = $repo_config[$theme_option_key]['source_repo'];
    $source_branch = $repo_config[$theme_option_key]['source_repo_branch'];

    // Clone repository.
    $this->message->step_status('Cloning source');
    $this->runner("git clone --depth=1 --branch={$source_branch} {$source_address} {$name_cleaned}");
    chdir($name_cleaned);
    $this->message->step_finish();

    // Clean and rename old name to new.
    $this->message->step_status('Cleaning and renaming theme');
    $this->runner('rm -rf .git');
    $this->runner('rm -rf .gitignore');
    $this->runner('rm -rf README.md');
    $this->runner("find . -type f -exec sed -i 's/{$theme_option_key}/{$name_cleaned}/g' {} +");
    $this->runner("rename 's/{$theme_option_key}/{$name_cleaned}/g' *");
    $this->message->step_finish();

    $this->message->success("Theme created! Please update Title and Description in the theme's {$name_cleaned}.info.yml file.");

  }
}

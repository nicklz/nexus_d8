<?php

namespace Drupal\config\Controller;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Diff\DiffFormatter;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Drupal\system\FileDownloadController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for config module routes.
 */
class ConfigController implements ContainerInjectionInterface {

  /**
   * The target storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $targetStorage;

  /**
   * The source storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $sourceStorage;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The export storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $exportStorage;

  /**
   * The file download controller.
   *
   * @var \Drupal\system\FileDownloadController
   */
  protected $fileDownloadController;

  /**
   * The diff formatter.
   *
   * @var \Drupal\Core\Diff\DiffFormatter
   */
  protected $diffFormatter;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.storage'),
      $container->get('config.storage.sync'),
      $container->get('config.manager'),
      new FileDownloadController(),
      $container->get('diff.formatter'),
      $container->get('file_system'),
      $container->get('config.storage.export')
    );
  }

  /**
   * Constructs a ConfigController object.
   *
   * @param \Drupal\Core\Config\StorageInterface $target_storage
   *   The target storage.
   * @param \Drupal\Core\Config\StorageInterface $source_storage
   *   The source storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The config manager.
   * @param \Drupal\system\FileDownloadController $file_download_controller
   *   The file download controller.
   * @param \Drupal\Core\Diff\DiffFormatter $diff_formatter
   *   The diff formatter.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\Core\Config\StorageInterface $export_storage
   *   The export storage.
   */
  public function __construct(StorageInterface $target_storage, StorageInterface $source_storage, ConfigManagerInterface $config_manager, FileDownloadController $file_download_controller, DiffFormatter $diff_formatter, FileSystemInterface $file_system, StorageInterface $export_storage = NULL) {
    $this->targetStorage = $target_storage;
    $this->sourceStorage = $source_storage;
    $this->configManager = $config_manager;
    $this->fileDownloadController = $file_download_controller;
    $this->diffFormatter = $diff_formatter;
    $this->fileSystem = $file_system;
    if (is_null($export_storage)) {
      @trigger_error('The config.storage.export service must be passed to ConfigController::__construct(), it is required before Drupal 9.0.0. See https://www.drupal.org/node/3037022.', E_USER_DEPRECATED);
      $export_storage = \Drupal::service('config.storage.export');
    }
    $this->exportStorage = $export_storage;
  }

  /**
   * Downloads a tarball of the site configuration.
   */
  public function downloadExport() {
    try {
      $this->fileSystem->delete(file_directory_temp() . '/config.tar.gz');
    }
    catch (FileException $e) {
      // Ignore failed deletes.
    }

    $archiver = new ArchiveTar(file_directory_temp() . '/config.tar.gz', 'gz');
    // Add all contents of the export storage to the archive.
    foreach ($this->exportStorage->listAll() as $name) {
      $archiver->addString("$name.yml", Yaml::encode($this->exportStorage->read($name)));
    }
    // Get all  data from the remaining collections.
    foreach ($this->exportStorage->getAllCollectionNames() as $collection) {
      $collection_storage = $this->exportStorage->createCollection($collection);
      foreach ($collection_storage->listAll() as $name) {
        $archiver->addString(str_replace('.', '/', $collection) . "/$name.yml", Yaml::encode($collection_storage->read($name)));
      }
    }

    $request = new Request(['file' => 'config.tar.gz']);
    return $this->fileDownloadController->download($request, 'temporary');
  }

  /**
   * Shows diff of specified configuration file.
   *
   * @param string $source_name
   *   The name of the configuration file.
   * @param string $target_name
   *   (optional) The name of the target configuration file if different from
   *   the $source_name.
   * @param string $collection
   *   (optional) The configuration collection name. Defaults to the default
   *   collection.
   *
   * @return string
   *   Table showing a two-way diff between the active and staged configuration.
   */
  public function diff($source_name, $target_name = NULL, $collection = NULL) {
    if (!isset($collection)) {
      $collection = StorageInterface::DEFAULT_COLLECTION;
    }
    $diff = $this->configManager->diff($this->targetStorage, $this->sourceStorage, $source_name, $target_name, $collection);
    $this->diffFormatter->show_header = FALSE;

    $build = [];

    $build['#title'] = t('View changes of @config_file', ['@config_file' => $source_name]);
    // Add the CSS for the inline diff.
    $build['#attached']['library'][] = 'system/diff';

    $build['diff'] = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['diff'],
      ],
      '#header' => [
        ['data' => t('Active'), 'colspan' => '2'],
        ['data' => t('Staged'), 'colspan' => '2'],
      ],
      '#rows' => $this->diffFormatter->format($diff),
    ];

    $build['back'] = [
      '#type' => 'link',
      '#attributes' => [
        'class' => [
          'dialog-cancel',
        ],
      ],
      '#title' => "Back to 'Synchronize configuration' page.",
      '#url' => Url::fromRoute('config.sync'),
    ];

    return $build;
  }

}

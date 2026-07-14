<?php

namespace Drupal\entity_usage\UrlToEntityIntegrations;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\entity_usage\Events\Events;
use Drupal\entity_usage\Events\UrlToEntityEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Determines if the URL points to a public file managed as a file entity.
 */
class PublicFileIntegration implements EventSubscriberInterface {

  /**
   * The regex pattern to match requests to the public files directory.
   */
  private string $publicFilePattern;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'stream_wrapper.public')]
    StreamWrapperInterface $publicStream,
    ConfigFactoryInterface $configFactory,
  ) {
    $baseUrl = $publicStream->getExternalUrl();
    $parsed = parse_url($baseUrl);

    if (isset($parsed['path'])) {
      // If the public stream is a local stream, we need to remove the base path
      // if Drupal is installed in a subdirectory.
      if ($publicStream instanceof LocalStream) {
        $config = $configFactory->get('entity_usage.settings');
        foreach ($config->get('site_domains') ?? [] as $site_domain) {
          $site_domain = rtrim($site_domain, "/");
          $host_pattern = str_replace('.', '\.', $site_domain) . "/";
          $host_pattern = "/" . str_replace("/", '\/', $host_pattern) . "/";
          if (preg_match($host_pattern, $baseUrl)) {
            if (preg_match('/^[^\/]+(\/.+)/', $site_domain, $matches)) {
              $sub_directory = $matches[1];
              if (str_starts_with($parsed['path'], $sub_directory)) {
                $parsed['path'] = substr($parsed['path'], strlen($sub_directory));
              }
            }
            break;
          }
        }
      }
      $this->publicFilePattern = '{^' . preg_quote(rtrim($parsed['path'], '/'), '{}') . '/}';
    }
    else {
      throw new \LogicException('The public stream wrapper does not provide a valid external URL.');
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [Events::URL_TO_ENTITY => ['getFileFromPath', 500]];
  }

  /**
   * Determines if the URL points to a public file managed as a file entity.
   *
   * @param \Drupal\entity_usage\Events\UrlToEntityEvent $event
   *   The event.
   */
  public function getFileFromPath(UrlToEntityEvent $event): void {
    if (!$event->isEntityTypeTracked('file')) {
      return;
    }

    $url = $event->getRequest()->getPathInfo();
    if (preg_match($this->publicFilePattern, $url)) {
      // Check if we can map the link to a public file.
      $file_uri = preg_replace($this->publicFilePattern, 'public://', urldecode($url));
      $files = $this->entityTypeManager->getStorage('file')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uri', $file_uri)
        ->range(0, 1)
        ->execute();
      if (!empty($files)) {
        // File entity found.
        $event->setEntityInfo('file', reset($files));
      }
    }
  }

}

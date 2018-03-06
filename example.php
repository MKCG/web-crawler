<?php

require_once 'vendor/autoload.php';

use Behat\Mink\Mink,
    Behat\Mink\Session,
    Behat\Mink\Driver\GoutteDriver,
    Behat\Mink\Driver\Goutte\Client as GoutteClient,
    Behat\Mink\Element\DocumentElement,
    Behat\Mink\Driver\Selenium2Driver,
    Behat\Mink\Exception\UnsupportedDriverActionException;

use Selenium\Client as SeleniumClient;

use MKCG\Crawler\Crawler;


$url = $_SERVER['argv'][1];
$authorizedHosts = isset($_SERVER['argv'][2])
    ? explode(',', $_SERVER['argv'][2])
    : [parse_url($url, PHP_URL_HOST)];

$driver = isset($_SERVER['argv'][3]) && $_SERVER['argv'][3] === 'selenium'
    ? new Selenium2Driver('chrome', ['browserName' => 'chrome', 'version' => '', 'platform' => 'ANY'])
    : new GoutteDriver(new GoutteClient());

$mink = new Mink(['default' => new Session($driver)]);
$mink->setDefaultSessionName('default');

(new Crawler($mink))
    ->onEvent(Crawler::EVENT_URL_VISITED, function(string $url, Session $session) {
        echo "VISITED - $url\n";
    })
    ->onEvent(Crawler::EVENT_URL_VISITED, function(string $url, Session $session) {
        try {
            $statusCode = $session->getStatusCode();
        } catch (UnsupportedDriverActionException $e) {
            return;
        }

        if ($statusCode >= 400) {
            echo "INVALID - $url\n";
        }
    })
    ->onEvent(Crawler::EVENT_URL_VISITED, function(string $url, Session $session) {
        $currentUrl = $session->getCurrentUrl();

        if ($url !== $currentUrl) {
            echo "REDIRECTED - From $url To $currentUrl\n";
        }    
    })
    ->onEvent(Crawler::EVENT_URL_VISITED, function (string $url, Session $session) {
        try {
            $screenshot = $session->getScreenshot();
        } catch (UnsupportedDriverActionException $e) {
            return;
        }

        $filepath = '/tmp/' . uniqid() . '.png';

        file_put_contents($filepath, $screenshot);
        echo "SCREENSHOT $filepath\n";
    })
    ->onEvent(Crawler::EVENT_PAGE_RETRIEVED, function (DocumentElement $page) {
        foreach ($page->findAll('css', 'link') as $link) {
            $href = $link->getAttribute('href');
            $path = parse_url($href, PHP_URL_PATH);

            $extension = explode('.', $path);

            if (!isset($extension[1])) {
                continue;
            }

            $extension = array_pop($extension);
            $extension = strtoupper($extension);

            switch ($extension) {
                case 'CSS':
                    echo "    Stylesheets - $href\n";
                    break;
                case 'XML' && strpos($href, 'rss') !== false:
                    echo "    RSS Feed - $href\n";
                    break;
                case 'JPG':
                case 'PNG':
                    echo "    IMAGE - $href\n";
                    break;
                default:
                    echo "    $extension - $href\n";
                    break;
            }
        }
    })
    ->onEvent(Crawler::EVENT_URL_FOUND, function (string $url) {
        echo "FOUND - $url\n";

        return strpos($url, '//') !== 0;
    })
    ->setAuthorizedHosts($authorizedHosts)
    ->crawl($url);
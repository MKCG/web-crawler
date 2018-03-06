<?php

namespace MKCG\Crawler;

use Behat\Mink\Mink,
    Behat\Mink\Session,
    Behat\Mink\Driver\GoutteDriver,
    Behat\Mink\Driver\Goutte\Client as GoutteClient,
    Behat\Mink\Element\DocumentElement,
    Behat\Mink\Exception\UnsupportedDriverActionException;

class Crawler
{
    const EVENT_URL_VISITED     = 1;
    const EVENT_PAGE_RETRIEVED  = 2;
    const EVENT_URL_FOUND       = 4;

    private $mink;

    private $queue;

    private $authorizedHosts = [];

    private $visited = [];

    private $callbacks = [
        self::EVENT_URL_VISITED    => [],
        self::EVENT_PAGE_RETRIEVED => [],
        self::EVENT_URL_FOUND      => []
    ];

    public function __construct(Mink $mink)
    {
        $this->mink = $mink;

        $this->queue = new \SplQueue();
        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
    }

    public function onEvent($event, callable $callback)
    {
        $this->callbacks[$event][] = $callback;

        return $this;
    }

    public function setAuthorizedHosts(array $hosts)
    {
        $this->authorizedHosts = $hosts;

        return $this;
    }

    public function crawl(string $url)
    {
        if (!$this->canVisit($url)) {
            return $this;
        }

        $this->queue[] = $url;

        foreach ($this->queue as $url) {
            if ($this->wasVisited($url)) {
                continue;
            }

            $this->visit($url);
        }

        return $this;
    }

    private function visit(string $url)
    {
        $session = $this->mink->getSession();

        $session->visit($url);
        $this->visited[$url] = true;

        foreach ($this->callbacks[static::EVENT_URL_VISITED] as $callback) {
            call_user_func($callback, $url, $session);
        }

        $page = $session->getPage();

        foreach ($this->callbacks[static::EVENT_PAGE_RETRIEVED] as $callback) {
            call_user_func($callback, $page);
        }

        $this->extractLinks($page);
    }

    private function extractLinks(DocumentElement $page) : self
    {
        $links = $page->findAll('css', 'a');

        foreach ($links as $i => $link) {
            $url = $link->getAttribute('href');

            if (!$url) {
                continue;
            }

            $isValid = true;

            foreach ($this->callbacks[static::EVENT_URL_FOUND] as $callback) {
                $isValid = call_user_func($callback, $url) && $isValid;
            }

            if (!$isValid || empty($url) || !$this->canVisit($url)) {
                continue;
            }

            $this->queue[] = $url;
        }

        return $this;
    }

    private function canVisit(string $url) : bool
    {
        return !$this->wasVisited($url) && $this->hostIsAuthorized($url);
    }

    private function wasVisited(string $url) : bool
    {
        return isset($this->visited[$url]);
    }

    private function hostIsAuthorized(string $url) : bool
    {
        if (empty($this->authorizedHosts)) {
            return true;
        }

        $url = strtolower($url);
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === false) {
            return false;
        }

        $hostParts = explode('.', $host);

        foreach ($this->authorizedHosts as $authorizedHost) {
            $authorizedHost = strtolower($authorizedHost);
            $authorizedHostParts = explode('.', $authorizedHost);

            if (count($authorizedHostParts) > count($hostParts)) {
                continue;
            }

            $copyHostParts = $hostParts;

            while (count($copyHostParts) > count($authorizedHostParts)) {
                array_shift($copyHostParts);
            }

            if ($authorizedHostParts === $copyHostParts) {
                return true;
            }
        }

        return false;
    }
}

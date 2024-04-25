<?php

namespace neilanderson\ServerFinder;

use Spatie\Dns\Dns;

class Finder
{
    private Dns $dns;

    private $lookupFile = __DIR__ . '/../data/lookups.json';

    private array $data;
    private array $ipMap;
    private array $serverMap;

    public function __construct(
        string $lookupFile = null
    ) {
        $this->dns = new Dns();
        $this->getLookupData($lookupFile);
    }

    public function find(string $ip)
    {
        if (isset($this->ipMap[$ip])) {
            $item = $this->ipMap[$ip];

            echo 'Found match for ' . $ip . PHP_EOL;
            echo $this->writeServerInfo($item);

            if (isset($item->servers)) {
                echo PHP_EOL . 'Related:';
                $count = 1;

                foreach ($item->servers as $server) {
                    if (isset($this->serverMap[$server])) {
                        echo ' #' . $count++;
                        $this->writeServerInfo($this->serverMap[$server]);
                    }
                }

                echo PHP_EOL;
            }

            return;
        }

        echo 'No match found for IP ' . $ip . PHP_EOL;
    }

    private function writeServerInfo($item)
    {
        echo ' - Name: ' . $item->name . PHP_EOL;
        echo ' - IPs: ' . implode(', ', $item->ips) . PHP_EOL;
        echo ' - Type: ' . $item->type . PHP_EOL;
    }

    private function getLookupData($lookupFile)
    {
        $data = file_get_contents($lookupFile ?? $this->lookupFile);

        if (empty($data))
            return;

        $data = json_decode($data);

        // Store data
        $this->data = $data;

        // Generate map
        foreach ($this->data as &$datum) {
            foreach ($datum->ips as $ip) {
                $this->ipMap[$ip] = $datum;

                if ($datum->type === 'server')
                    $this->serverMap[$datum->name] = $datum;
            }
        }
    }
}
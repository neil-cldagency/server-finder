<?php

namespace neilanderson\ServerFinder;

use Exception;
use Spatie\Dns\Dns;
use stdClass;

use neilanderson\ServerFinder\FinderException;

/**
 * Find a server based on IP, url or domain using a lookup table
 *
 * Lookup table example format:
 *
 *     [
 *         {
 *             "name": "my-server",
 *             "infrastructure": "digitalocean",
 *             "type": "server",
 *             "addresses": ["123.123.231.132"],
 *             "related": ["database/my-db"]
 *         },
 *         {
 *             "name": "my-db",
 *             "infrastructure": "digitalocean",
 *             "type": "database",
 *             "addresses": ["000.000.000.000", "my-db-hostname.digitalocean.example.com"],
 *             "related": ["server/my-server"]
 *         },
 *     ]
 *
 * The tool will look for a file called "server-finder-data.json" in the following paths:
 *  ./data/server-finder-data.json
 *  ~/.server-finder-data.json
 * /etc/server-finder/server-finder-data.json
 *
 * You can specify the path to a data json file with `-l <lookupfile>`
 */
class Finder
{
    private Dns $dns;

    private $lookupFileName = 'server-finder-data.json';

    private array $data;
    private array $ipMap;
    private array $nameMap;
    private array|null $records = null;

    public function __construct(
        string $lookupFile = null
    ) {
        set_exception_handler(function($e) {
            $this->exception($e);
        });

        $this->dns = new Dns();
        $this->getLookupData($this->findLookupFile($lookupFile));
    }

    public function find(string|array $addresses)
    {
        if (!is_array($addresses))
            $addresses = [$addresses];

        foreach ($addresses as $address) {
            echo 'Looking up ' . $address . '...' . PHP_EOL;

            $ipObject = $this->getIpsFromAddress($address);

            if (!count($ipObject->ips)) {
                echo 'Couldn’t determine IPs for ' . $address . '!' . PHP_EOL;
                continue;
            }

            // Search by IPs
            foreach ($ipObject->ips as $ip) {
                $item = null;

                if ($ip !== $address)
                    echo 'Got IP: ' . $ip . PHP_EOL . PHP_EOL;

                if (isset($this->ipMap[$ip]))
                    $item = $this->ipMap[$ip];

                if ($item)
                    $this->writeMatch($item);
            }

            // Search by address (you never know)
            if (isset($this->ipMap[$ipObject->address])) {
                $item = $this->ipMap[$ipObject->address];
                $this->writeMatch($item);
            }

            if (!$item)
                echo 'No matches found for IP ' . $ip . PHP_EOL;
        }
    }

    private function writeMatch($item)
    {
        echo $this->writeServerInfo($item);

        if (isset($item->related)) {
            echo PHP_EOL . 'Related:' . PHP_EOL;
            $count = 1;

            foreach ($item->related as $related) {
                $location = explode('/', $related);

                if (isset($this->nameMap[$location[0]][$location[1]])) {
                    echo ' #' . $count++;
                    $this->writeServerInfo($this->nameMap[$location[0]][$location[1]]);
                }
            }

            echo PHP_EOL;
        }
    }

    private function writeServerInfo($item)
    {
        echo ' - Name: ' . $item->name . PHP_EOL;
        echo ' - Addresses: ' . implode(', ', $item->addresses) . PHP_EOL;
        echo ' - Type: ' . $item->type .
            (isset($item->infrastructure) ? ' (' . $item->infrastructure . ')' : '') .
            PHP_EOL;
    }

    private function findLookupFile(string|null $lookupFile = null) {
        $paths = [];

        if ($lookupFile !== null && realpath($lookupFile))
            array_push($paths, realpath($lookupFile));

        array_push($paths, realpath(__DIR__ . '/../data') . '/' . $this->lookupFileName);
        array_push($paths, $_SERVER['HOME'] . '/.' . $this->lookupFileName);
        array_push($paths, '/etc/server-finder/' . $this->lookupFileName);

        foreach ($paths as $path) {
            if ($this->tryLookupFile($path))
                return $path;
        }

        throw new FinderException(
            'No lookup file found. (Tried paths: ' . implode('; ', $paths)
        );
    }

    private function tryLookupFile(string $path) {
        if (file_exists($path))
            return $path;

        return false;
    }

    private function getLookupData($lookupFile)
    {
        $data = file_get_contents($lookupFile);

        if (empty($data))
            return;

        $data = json_decode($data);

        // Store data
        $this->data = $data;

        // Generate map
        foreach ($this->data as &$datum) {
            foreach ($datum->addresses as $address) {
                if (isset($this->ipMap[$address]))
                    throw new FinderException(
                        'Address ' . $address .
                        ' already exists in DB - please check data source!'
                    );

                $this->ipMap[$address] = $datum;
            }

            if (
                isset($this->nameMap[$datum->type]) &&
                isset($this->nameMap[$datum->type][$datum->name])
            ) {
                throw new FinderException(
                    'Name ' . $datum->name . ' already exists in type ' .
                    $datum->type . ' - please check data source!'
                );
            }

            $this->nameMap[$datum->type][$datum->name] = $datum;
        }
    }

    private function getIpsFromAddress(string $address): stdClass
    {
        if ((bool) ip2long($address))
            return $this->getIpObject([$address]);

        $url = parse_url(
            'http://' . preg_replace('/https?:\/\//', '', $address)
        );

        if (!isset($url['host']))
            return $this->getIpObject([]);

        if ((bool) ip2long($url['host']))
            return $this->getIpObject([$url['host']]);

        $this->records = $this->dns->getRecords($url['host'], [
            'A',
            'ALIAS'
            // 'AAAA'
        ]);

        if ($this->records && count($this->records)) {
            $result = [];

            foreach ($this->records as $record) {
                array_push($result, $record->ip() ?? $record->ipv6());
            }

            return $this->getIpObject(
                $result,
                $address
            );
        }

        return $this->getIpObject([]);
    }

    private function getIpObject(array $ips, string|null $address = null): stdClass
    {
        return (object) [
            'ips' => $ips,
            'address' => $address ?? $ips[0] ?? null
        ];
    }

    private function exception(Exception $e)
    {
        if ($e instanceof FinderException) {
            echo $e->getMessage() . PHP_EOL;
            exit;
        }

        throw $e;
    }
}
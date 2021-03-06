<?php

class RedLock
{
    private $retryDelay;
    private $retryCount;
    private $clockDriftFactor = 0.01;

    private $quorum;

    private $servers = array();
    private $instances = array();

    function __construct($servers, $retryDelay = 200, $retryCount = 3)
    {
        $this->servers = $servers;
        
        $this->retryDelay = $retryDelay;
        $this->retryCount = $retryCount;

        $this->quorum  = min(count($servers), (count($servers) / 2 + 1));
    }

    public function lock($resource, $ttl)
    {
        $this->initInstances();

        $token = uniqid();
        $retry = $this->retryCount;

        do {
            $n = 0;

            $startTime = microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;

            if ($n >= $this->quorum && $validityTime > 0) {
                return array(
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token'    => $token,
                );

            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            $delay = mt_rand(floor($this->retryDelay / 2), $this->retryDelay);
            usleep($delay * 1000);

            $retry--;

        } while ($retry > 0);

        return false;
    }

    public function unlock(array $lock)
    {
        $this->initInstances();
        $resource = $lock['resource'];
        $token    = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    private function initInstances()
    {
        if (empty($this->instances)) {
            foreach ($this->servers as $server) {
                if(is_object($server) && $server instanceof \Predis\Client) {
                    $this->instances[] = $server;
                }
                else {
                    if(is_array($server)) {
                        list($host, $port) = $server;
                        $this->instances[] = new \Predis\Client(array(
                            'scheme' => 'tcp',
                            'host'   => $host,
                            'port'   => $port,
                        ));
                    } 
                    else {
                        $this->instances[] = new Predis\Client($server);
                    }
                }
            }
        }
    }

    private function lockInstance($instance, $ressource, $token, $ttl)
    {
        return $instance->set($ressource, $token, 'NX', 'PX', $ttl);
    }

    private function unlockInstance($instance, $ressource, $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';
        return $instance->eval($script, 1, $ressource, $token);
    }
}

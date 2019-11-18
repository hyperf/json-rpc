<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\JsonRpc;

use Hyperf\Di\Annotation\Inject;
use Hyperf\JsonRpc\Pool\PoolFactory;
use Hyperf\LoadBalancer\LoadBalancerInterface;
use Hyperf\LoadBalancer\Node;
use Hyperf\Pool\Pool;
use Hyperf\Rpc\Contract\TransporterInterface;
use RuntimeException;
use Swoole\Coroutine\Client as SwooleClient;

class JsonRpcTransporter implements TransporterInterface
{
    /**
     * @var null|LoadBalancerInterface
     */
    private $loadBalancer;

    /**
     * If $loadBalancer is null, will select a node in $nodes to request,
     * otherwise, use the nodes in $loadBalancer.
     *
     * @var Node[]
     */
    private $nodes = [];

    /**
     * @var float
     */
    private $connectTimeout = 5;

    /**
     * @var float
     */
    private $recvTimeout = 5;

    /**
     * TODO: Set config.
     * @var array
     */
    private $config;

    /**
     * @Inject()
     * @var PoolFactory
     */
    protected $factory;

    public function send(string $data)
    {
        $client = retry(2, function () use ($data) {
            $pool = $this->getClient();
            $client = $pool->get();
            if ($client->send($data . $this->getEof()) === false) {
                if ($client->errCode == 104) {
                    $client->release();
                    throw new RuntimeException('Connect to server failed.');
                }
            }
            return $client;
        });
        $data = $client->recv($this->recvTimeout);
        $client->release();
        return $data;
    }

    public function getClient(): Pool
    {
        $node = $this->getNode();
        $config = [
            'host'=>$node->host,
            'port'=>$node->port,
            'connectTimeout'=>$this->connectTimeout,
        ];
        $name = $node->host.':'.$node->port;
        $pool = $this->factory->getPool($name,$config);
        return $pool;
    }

    public function getLoadBalancer(): ?LoadBalancerInterface
    {
        return $this->loadBalancer;
    }

    public function setLoadBalancer(LoadBalancerInterface $loadBalancer): TransporterInterface
    {
        $this->loadBalancer = $loadBalancer;
        return $this;
    }

    /**
     * @param \Hyperf\LoadBalancer\Node[] $nodes
     */
    public function setNodes(array $nodes): self
    {
        $this->nodes = $nodes;
        return $this;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    private function getEof()
    {
        return "\r\n";
    }

    /**
     * If the load balancer is exists, then the node will select by the load balancer,
     * otherwise will get a random node.
     */
    private function getNode(): Node
    {
        if ($this->loadBalancer instanceof LoadBalancerInterface) {
            return $this->loadBalancer->select();
        }
        return $this->nodes[array_rand($this->nodes)];
    }
}

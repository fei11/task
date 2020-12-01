<?php


namespace EasySwoole\Task;


use EasySwoole\Component\Process\AbstractProcess;
use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use EasySwoole\Task\Exception\Exception;
use Swoole\Atomic\Long;
use Swoole\Server;

class Task
{
    private $taskIdAtomic;
    private $config;
    private $attachServer = false;

    const PUSH_IN_QUEUE = 0;
    const PUSH_QUEUE_FAIL = -1;
    const ERROR_PROCESS_BUSY = -2;
    const ERROR_PACKAGE_ERROR = -3;
    const ERROR_TASK_ERROR = -4;
    const ERROR_PACKAGE_EXPIRE = -5;


    function __construct(Config $config)
    {
        $this->taskIdAtomic = new Long(0);
        $this->config = $config;
    }

    static function errCode2Msg(int $code):string
    {
        switch ($code){
            case self::PUSH_IN_QUEUE:{
                return 'push task in queue';
            }
            case self::PUSH_QUEUE_FAIL:{
                return 'push task to queue fail';
            }
            case self::ERROR_PROCESS_BUSY:{
                return 'task process busy';
            }
            case self::ERROR_PACKAGE_ERROR:{
                return 'task package decode error';
            }
            case self::ERROR_TASK_ERROR:{
                return "task run error";
            }
            case self::ERROR_PACKAGE_EXPIRE:{
                return "task package expire";
            }
            default:{
                return 'unknown error';
            }
        }
    }

    public function attachToServer(Server $server)
    {
        if(!$this->attachServer){
            $list = $this->__initProcess();
            /** @var AbstractProcess $item */
            foreach ($list as $item){
                $server->addProcess($item->getProcess());
            }
            $this->attachServer = true;
            return true;
        }else{
            throw new Exception("Task instance has been attach to server");
        }

    }

    public function __initProcess():array
    {
        $ret = [];
        $serverName = $this->config->getServerName();
        for($i = 0;$i < $this->config->getWorkerNum();$i++){
            $config = new UnixProcessConfig();
            $config->setProcessName("{$serverName}.TaskWorker.{$i}");
            $config->setSocketFile($this->idToUnixName($i));
            $config->setProcessGroup("{$serverName}.TaskWorker");
            $config->setArg([
                'workerIndex'=>$i,
                'taskIdAtomic'=>$this->taskIdAtomic,
                'taskConfig'=>$this->config
            ]);
            $ret[$i] = new Worker($config);
        }
        return  $ret;
    }

    public function async($task,callable $finishCallback = null,$taskWorkerId = null):?int
    {
        if($taskWorkerId === null){
            $taskWorkerId = $this->randomWorkerId();
        }
        $package = new Package();
        $package->setType($package::ASYNC);
        $package->setTask($task);
        $package->setOnFinish($finishCallback);
        $package->setExpire(round(microtime(true) + $this->config->getTimeout() - 0.01,3));
        return $this->sendAndRecv($package,$taskWorkerId);
    }

    /*
     * 同步返回执行结果
     */
    public function sync($task,$timeout = 3.0,$taskWorkerId = null)
    {
        if($taskWorkerId === null){
            $taskWorkerId = $this->randomWorkerId();
        }
        $package = new Package();
        $package->setType($package::SYNC);
        $package->setTask($task);
        $package->setExpire(round(microtime(true) + $timeout - 0.01,4));
        return $this->sendAndRecv($package,$taskWorkerId,$timeout);
    }

    private function idToUnixName(int $id):string
    {
        return $this->config->getTempDir()."/{$this->config->getServerName()}.TaskWorker.{$id}.sock";
    }

    private function randomWorkerId()
    {
        mt_srand();
        return rand(0,$this->config->getWorkerNum() - 1);
    }

    private function sendAndRecv(Package $package,int $id,float $timeout = null)
    {
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        $client = new UnixClient($this->idToUnixName($id));
        $client->send(Protocol::pack(\Opis\Closure\serialize($package)));
        $ret = $client->recv($timeout);
        $client->close();
        if (!empty($ret)) {
            return \Opis\Closure\unserialize(Protocol::unpack($ret));
        }else{
            return self::ERROR_PROCESS_BUSY;
        }
    }
}
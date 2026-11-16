<?php
declare(strict_types=1);
namespace CajeerBook;

/** Signal handlers only set a flag: never interrupt a transaction by throwing from a handler. */
final class WorkerLoop
{
    private bool $stopping=false;
    public function installSignals():void
    {
        if(!function_exists('pcntl_async_signals')||!function_exists('pcntl_signal'))throw new \RuntimeException('Worker loop requires ext-pcntl for graceful shutdown.');
        pcntl_async_signals(true);
        foreach([SIGTERM,SIGINT] as $signal)pcntl_signal($signal,function():void{$this->stopping=true;},false);
    }
    public function stopping():bool{return $this->stopping;}
    public function pause(float $seconds):void
    {
        $until=hrtime(true)+(int)($seconds*1e9);
        while(!$this->stopping&&($remaining=$until-hrtime(true))>0)usleep((int)min(200000,max(1,$remaining/1000)));
    }
    public function run(callable $tick,callable $wait):void
    {
        $this->installSignals();$failures=0;$hints=[];
        while(!$this->stopping){
            try{$n=$tick($hints,fn()=>$this->stopping);$hints=[];$failures=0;if(!$this->stopping&&$n===0)$hints=$wait();}
            catch(\Throwable $e){$hints=[];$failures=min(6,$failures+1);Observability::log('worker.loop_failure',['error_type'=>get_class($e)]);$this->pause(min(30,2**$failures)+random_int(0,1000)/1000);}
        }
        Observability::log('worker.stopped');Observability::flush();
    }
}

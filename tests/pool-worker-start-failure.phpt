--TEST--
Test that a Pool doesn't keep workers which failed to start
--FILE--
<?php

use pmmp\thread\Pool;
use pmmp\thread\Runnable;
use pmmp\thread\Worker;

class Task extends Runnable{
	public function run() : void{
		echo "ran\n";
	}
}

class Fragile extends Worker{
	public static bool $fail = true;

	public function __construct(){
		if(self::$fail){
			throw new \RuntimeException("not today");
		}
	}
}

$pool = new Pool(1, Fragile::class);
try{
	$pool->submit(new Task());
}catch(\RuntimeException $e){
	echo $e->getMessage(), "\n";
}
var_dump($pool->collect());

Fragile::$fail = false;
$pool->submit(new Task());
$pool->shutdown();
var_dump($pool->collect());
?>
--EXPECT--
not today
int(0)
ran
int(0)

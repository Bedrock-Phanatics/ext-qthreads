--TEST--
Test Pool::resize() stops the last workers and rejects negative sizes
--FILE--
<?php

use pmmp\thread\Pool;
use pmmp\thread\Runnable;

class Task extends Runnable{
	public function run() : void{}
}

class CountingPool extends Pool{
	public function workers() : int{
		return count($this->workers ?? []);
	}
}

$pool = new CountingPool(3);
for($i = 0; $i < 3; $i++){
	$pool->submit(new Task());
}
var_dump($pool->workers());

$pool->resize(1);
var_dump($pool->workers());

try{
	$pool->resize(-1);
}catch(\ValueError $e){
	echo $e->getMessage(), "\n";
}
var_dump($pool->workers());

$pool->submit(new Task());
$pool->resize(0);
var_dump($pool->workers());
$pool->shutdown();
?>
--EXPECT--
int(3)
int(1)
pmmp\thread\Pool::resize(): Argument #1 ($size) must be greater than or equal to 0
int(1)
int(0)

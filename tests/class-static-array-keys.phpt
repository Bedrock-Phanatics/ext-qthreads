--TEST--
Test that copying static arrays with string keys into a thread doesn't leak the keys
--FILE--
<?php
class Test{
    public static $map = ['alpha' => 'beta', 'nested' => ['gamma' => 'delta']];
}

class Child extends Test{
    public static $own = ['zeta' => 1];
}

class UserThread extends \pmmp\thread\Thread{
    public function run() : void{
        var_dump(Test::$map['nested']['gamma'], Child::$own['zeta']);
    }
}

for($i = 0; $i < 2; ++$i){
    $thread = new UserThread;
    $thread->start(\pmmp\thread\Thread::INHERIT_ALL);
    $thread->join();
}
?>
--EXPECT--
string(5) "delta"
int(1)
string(5) "delta"
int(1)

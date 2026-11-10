--TEST--
Test that a fatal error inside synchronized() still ends the script
--SKIPIF--
<?php $s = function_exists("opcache_get_status") ? opcache_get_status(false) : false; if(PHP_OS_FAMILY === "Windows" && is_array($s) && !empty($s["jit"]["on"])) die("skip a bailout through JIT frames crashes in RtlUnwind on Windows"); ?>
--FILE--
<?php

use pmmp\thread\ThreadSafeArray;

function fatal() : void{
	eval('function fatal(){}');
}

$array = new ThreadSafeArray();
$array->synchronized(function() : void{
	fatal();
});
echo "still running\n";
?>
--EXPECTF--
Fatal error: Cannot redeclare function fatal() %s on line %d

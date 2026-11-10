--TEST--
Test that a fatal error inside synchronized() still ends the script
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

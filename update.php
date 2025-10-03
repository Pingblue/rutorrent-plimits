<?php

// need 5+ args before reading $argv[4]
if (count($argv) >= 5) {
    $_SERVER['REMOTE_USER'] = $argv[4];
}

require_once(dirname(__FILE__).'/limits.php');

// the rest needs 4+ args (indexes 1..3)
if (count($argv) > 3) {
    $tl = new trackersLimit();
    if ($tl->loadLocal() && $tl->checkPublic($argv[1])) {
        $hash = $argv[2];
        $mode = $argv[3];
        switch ($mode) {
            case "insert":
                trackersLimit::trace('Added torrent from the public tracker '.$hash);
                $req = new rXMLRPCRequest(array(
                    new rXMLRPCCommand("branch", array(
                        $hash,
                        getCmd("d.is_active="),
                        getCmd('cat').'=$'.getCmd("d.stop").'=,$'.getCmd("d.set_throttle_name=").'slimit,$'.getCmd('d.start='),
                        getCmd('d.set_throttle_name=').'slimit'
                    )),
                    new rXMLRPCCommand("view.set_visible", array($hash, "rlimit"))
                ));
                $req->run();
                break;

            case "finish":
                trackersLimit::trace('Finished torrent from the public tracker '.$hash);
                (new rXMLRPCRequest(new rXMLRPCCommand("d.close", array($hash))))->run();
                break;

            case "resume":
                trackersLimit::trace('Resumed torrent from the public tracker '.$hash);
                (new rXMLRPCRequest(new rXMLRPCCommand("branch", array(
                    $hash,
                    getCmd("d.complete="),
                    getCmd('d.close=')
                ))))->run();
                break;
        }
    }
}
